import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
import {webcrypto} from 'node:crypto';

const source=readFileSync(new URL('../background.js',import.meta.url),'utf8');
const fakeOrder=(id='FAKE-QUANTITY-ORDER')=>({
  list_type:3,info_card:{order_id:id,create_time:Date.parse('2026-10-09T09:00:00Z')/1000,
    product_count:5,final_total:21610000,order_list_cards:[{
      shop_info:{shop_id:400,shop_name:'Fixture Shop'},product_info:{item_groups:[{items:[
        {item_id:101,name:'Pants',model_name:'W/One size',amount:1,item_price:9890000},
        {item_id:101,name:'Pants',model_name:'Black/One size',amount:1,item_price:9890000},
        {item_id:202,name:'Fishing rod',model_name:'5#',model_quantity_purchased:3,item_price:610000},
      ]}]}
    }]}
});
function fixture({data=fakeOrder(),accounts=[42,42,42],detailOnlyMeta=false,detailFor=null,listRecords=null}={}){
  const storage={hubUrl:'http://localhost/pan',apiKey:'safe-fixture'},posts=[];
  let accountCalls=0;
  const sandbox={crypto:webcrypto,console,setTimeout,clearTimeout,
    fetch:async (url,opts={})=>{
      posts.push({url,body:opts.body?JSON.parse(opts.body):null});
      const result=url.includes('/api/sync_anchor.php')?
        {ok:true,account_id:'42',cutoff_date:'',latest_order_date:''}:
        {ok:true,updated_orders:1,inserted_orders:0};
      return {ok:true,status:200,text:async()=>JSON.stringify(result)};
    },
    chrome:{storage:{local:{
      get:async keys=>Object.fromEntries((Array.isArray(keys)?keys:[keys]).map(k=>[k,structuredClone(storage[k])])),
      set:async value=>Object.assign(storage,structuredClone(value)),
    }},runtime:{sendMessage:async()=>{},onMessage:{addListener(){}}},
      scripting:{executeScript:async({func,args})=>{
        const text=func.toString();
        if(text.includes('get_account_info'))return [{result:{http:200,ok:true,account:{userid:accounts[Math.min(accountCalls++,accounts.length-1)],username:'fixture'}}}];
        if(text.includes('get_order_detail'))return [{result:{http:200,ok:true,json:{error:0,data:detailOnlyMeta?{pc_processing_info:{}}:(detailFor?detailFor(String(args?.[0])):data)},url:'https://shopee.co.th/api/v4/order/get_order_detail'}}];
        return [{result:{http:200,ok:true,json:{error:0,data:{details_list:listRecords??(detailOnlyMeta?[fakeOrder()]:[]),next_offset:-1}},url:'https://shopee.co.th/api/v4/order/get_order_list'}}];
      }}}
  };
  const ctx=vm.createContext(sandbox);vm.runInContext(source,ctx);
  return {ctx,posts,storage};
}

test('reproduced pants 2 variants + fishing rod 3 pieces become 3 lines / 5 pieces',()=>{
  const f=fixture();const r=f.ctx.normalizeOrder(fakeOrder(),{userid:42,username:'fixture'});
  assert.equal(r.ignoredReason,'');
  assert.equal(r.items.length,3);
  assert.equal(new Set(r.items.map(i=>i.product_key)).size,3,'variant fallback keys must be unique');
  assert.equal(r.itemQuantity,5);
  assert.deepEqual(Array.from(r.items,x=>x.quantity),[1,1,3]);
  assert.deepEqual(Array.from(r.items,x=>x.variant_name),['W/One size','Black/One size','5#']);
  const raw=JSON.parse(r.items[2].raw_json);
  assert.deepEqual(raw.quantity_sources,['model_quantity_purchased']);
});

test('identical SKU across shipping groups remains two distinct purchased lines',()=>{
  const f=fixture();const o=fakeOrder();o.info_card.product_count=6;
  o.info_card.order_list_cards[0].product_info.item_groups.push({items:[
    {item_id:202,name:'Fishing rod',model_name:'5#',model_quantity_purchased:1,item_price:610000}
  ]});
  const r=f.ctx.normalizeOrder(o,{userid:42});
  assert.equal(r.itemQuantity,6);assert.equal(r.items.length,4);
  assert.deepEqual(Array.from(r.items.filter(x=>x.product_name==='Fishing rod'),x=>x.quantity),[3,1]);
  assert.equal(new Set(r.items.map(x=>x.product_key)).size,4);
});

test('missing or conflicting quantity hard-stops instead of fabricating 1',async()=>{
  const f=fixture();const o=fakeOrder('FAKE-MISSING-QUANTITY');delete o.info_card.order_list_cards[0].product_info.item_groups[0].items[2].model_quantity_purchased;
  assert.equal(f.ctx.normalizeOrder(o,{userid:42}).ignoredReason,'missing_quantity');
  const o2=fakeOrder();o2.info_card.order_list_cards[0].product_info.item_groups[0].items[2].amount=1;
  assert.equal(f.ctx.normalizeOrder(o2,{userid:42}).ignoredReason,'conflicting_quantity_fields');
  await assert.rejects(()=>f.ctx.processSyncRecords([fakeOrder(),o],{userid:42},'http://localhost/pan','scan','url'),/missing_quantity/);
  assert.equal(f.posts.length,0,'entire page rejected before mutation');
});

test('source product count higher than all parsed units blocks unsafe import',()=>{
  const f=fixture();const o=fakeOrder();o.info_card.product_count=7;
  assert.equal(f.ctx.normalizeOrder(o,{userid:42}).ignoredReason,'product_count_exceeds_snapshot');
});

test('targeted Buyer Detail refresh imports source units without any user-entered count',async()=>{
  const f=fixture();f.storage.syncStates={'42':{job:'recent',recentOffset:60,scanId:'saved-checkpoint'}};
  await f.ctx.refreshSingleOrder(7,'FAKE-QUANTITY-ORDER');
  assert.equal(f.posts.length,1);
  const data=f.posts[0].body;
  assert.equal(data.job_type,'buyer_detail_recheck');
  assert.ok(data.items.every(x=>x.item_snapshot_source==='buyer_detail_complete'));
  assert.ok(data.items.every(x=>x.item_snapshot_complete===1));
  assert.equal(data.items.length,3);
  assert.equal(data.items.reduce((sum,r)=>sum+r.quantity,0),5);
  assert.ok(data.items.every(x=>x.source_account_id==='42'));
  assert.equal(f.storage.syncStates['42'].recentOffset,60);
  assert.equal(f.storage.syncStates['42'].scanId,'saved-checkpoint');
  assert.equal(f.storage.syncStates['42'].quantityAudit.status,'updated');
});

test('metadata-only Buyer Detail refuses any targeted mutation without asking for a manual count',async()=>{
  const f=fixture({detailOnlyMeta:true});await f.ctx.refreshSingleOrder(7,'FAKE-QUANTITY-ORDER');
  assert.equal(f.posts.length,0);
  assert.equal(f.storage.syncStates['42'].quantityAudit.status,'waiting_detail');
});

test('account switches before targeted write are rejected; no database mutation',async()=>{
  const f=fixture({accounts:[42,99]});await f.ctx.refreshSingleOrder(7,'FAKE-QUANTITY-ORDER');
  assert.equal(f.posts.length,0);assert.equal(f.storage.syncStates['42'].quantityAudit.status,'error');
});

test('same-page duplicate order is imported once, conflicting snapshot aborts page',async()=>{
  const f=fixture();const one=fakeOrder();
  const result=await f.ctx.processSyncRecords([one,structuredClone(one)],{userid:42,username:'fixture'},
    'http://localhost/pan','fixture-scan','https://shopee.co.th/user/purchase');
  assert.equal(result.orderCount,1);
  assert.equal(result.duplicateRecords,1);
  assert.equal(f.posts.length,1);
  assert.equal(f.posts[0].body.items.length,3);
  const g=fixture();const another=fakeOrder();another.info_card.order_list_cards[0].product_info.item_groups[0].items[2].model_quantity_purchased=4;another.info_card.product_count=6;
  await assert.rejects(()=>g.ctx.processSyncRecords([fakeOrder(),another],{userid:42,username:'fixture'},'http://localhost/pan','scan','url'),/Conflicting duplicate/);
  assert.equal(g.posts.length,0);
});

function fourBuyerRows(){
  const o=fakeOrder();
  o.info_card.product_count=5;
  o.info_card.order_list_cards[0].product_info.item_groups=[
    {items:[
      {item_id:101,name:'Pants',model_name:'W/One size',amount:1,item_price:6500000},
      {item_id:101,name:'Pants',model_name:'W/One size',amount:1,item_price:6500000},
    ]},
    {items:[
      {item_id:202,name:'Fishing rod',model_name:'5#',amount:2,item_price:900000},
      {item_id:202,name:'Fishing rod',model_name:'5#',amount:1,item_price:900000},
    ]},
  ];
  return o;
}

test('four independently purchased source rows remain four rows and five units',()=>{
  const f=fixture({data:fourBuyerRows()});
  const n=f.ctx.normalizeOrder(fourBuyerRows(),{userid:42},{}, {}, {source:'buyer_detail_complete'});
  assert.equal(n.ignoredReason,'');
  assert.equal(n.items.length,4);
  assert.equal(n.itemQuantity,5);
  assert.deepEqual(Array.from(n.items,r=>r.quantity),[1,1,2,1]);
  assert.equal(new Set(n.items.map(r=>r.product_key)).size,4);
  assert.ok(n.items.every(i=>JSON.parse(i.raw_json).source_line_path));
});

test('Buyer Detail response item_list replaces the abbreviated two-row list snapshot',async()=>{
  const f=fixture({data:{item_list:fourBuyerRows().info_card.order_list_cards[0].product_info.item_groups.flatMap(g=>g.items)}});
  const base=fakeOrder();base.info_card.product_count=2;
  base.info_card.order_list_cards[0].product_info.item_groups=[{items:[
    {item_id:101,name:'Pants',model_name:'W/One size',amount:1,item_price:6500000},
    {item_id:202,name:'Fishing rod',model_name:'5#',amount:1,item_price:900000}
  ]}];
  const out=await f.ctx.processSyncRecords([base],{userid:42,username:'fixture'},
    'http://localhost/pan','scan','order-list',[ ],{autoEnrich:true,tabId:7,jobType:'recent'});
  assert.equal(out.buyerDetailComplete,1);
  const posted=f.posts.find(p=>p.url.endsWith('/import.php'));
  assert.ok(posted);
  assert.equal(posted.body.items.length,4);
  assert.equal(posted.body.items.reduce((sum,r)=>sum+r.quantity,0),5);
  assert.ok(posted.body.items.every(r=>r.item_snapshot_source==='buyer_detail_complete'));
});

test('Buyer Detail inconsistent product count isolates Order as pending without any unsafe import',async()=>{
  const data=fourBuyerRows();data.info_card.product_count=9;
  const f=fixture({data});
  const outcome=await f.ctx.processSyncRecords([fakeOrder()],{userid:42},'http://localhost/pan','scan','url',[],
    {autoEnrich:true,tabId:7});
  assert.equal(outcome.orderCount,0);
  assert.equal(outcome.pendingItemOrders.length,1);
  assert.match(outcome.pendingItemOrders[0].reason,/buyer_detail_/);
  assert.ok(!f.posts.some(p=>p.url.endsWith('/import.php')));
});

test('incomplete Order List product count is repaired by full Buyer Detail before a page is imported',async()=>{
  const f=fixture({data:{item_list:fourBuyerRows().info_card.order_list_cards[0].product_info.item_groups.flatMap(g=>g.items)}});
  const base=fakeOrder();base.info_card.product_count=5;
  base.info_card.order_list_cards[0].product_info.item_groups=[{items:[
    {item_id:101,name:'Pants',model_name:'W/One size',amount:1,item_price:6500000},
    {item_id:202,name:'Fishing rod',model_name:'5#',amount:1,item_price:900000}
  ]}];
  assert.equal(f.ctx.normalizeOrder(base,{userid:42}).ignoredReason,'product_count_exceeds_snapshot');
  const result=await f.ctx.processSyncRecords([base],{userid:42,username:'fixture'},'http://localhost/pan','scan','order-list',[],{autoEnrich:true,tabId:7});
  assert.equal(result.buyerDetailComplete,1);
  const imported=f.posts.find(p=>p.url.endsWith('/import.php'))?.body?.items;
  assert.equal(imported?.length,4);
  assert.equal(imported.reduce((sum,x)=>sum+x.quantity,0),5);
});

// Regression for the real-world v2.4.15 error: invalid=2/20 with
// product_count_exceeds_snapshot and missing_valid_items. Valid Orders must
// still be inserted, while the two other Order IDs are kept for a private,
// account-scoped retry. No source quantities are invented.
test('two malformed item snapshots in 20 do not block the 18 valid Orders',async()=>{
  let detailAvailable=false;
  const f=fixture({detailFor:id=>detailAvailable?fakeOrder(id):{pc_processing_info:{}}});
  const rows=Array.from({length:20},(_,i)=>fakeOrder('FAKE-ORDER-'+String(i).padStart(4,'0')));
  rows[18].info_card.product_count=8; // 8 advertised, 5 explicit purchased units
  for(const item of rows[19].info_card.order_list_cards[0].product_info.item_groups[0].items)item.status=3;
  assert.equal(f.ctx.normalizeOrder(rows[18],{userid:42}).ignoredReason,'product_count_exceeds_snapshot');
  assert.equal(f.ctx.normalizeOrder(rows[19],{userid:42}).ignoredReason,'missing_valid_items');
  const out=await f.ctx.processSyncRecords(rows,{userid:42,username:'fixture'},'http://localhost/pan',
    'scan-20','order-list',[],{autoEnrich:true,tabId:7,jobType:'sync'});
  assert.equal(out.orderCount,18);
  assert.equal(out.pendingItemOrders.length,2);
  assert.deepEqual(Array.from(out.pendingItemOrders,r=>r.reason),
    ['product_count_exceeds_snapshot','missing_valid_items']);
  const imports=f.posts.filter(x=>x.url.endsWith('/api/import.php'));
  assert.equal(imports.length,1,'valid Orders committed together');
  assert.equal(new Set(imports[0].body.items.map(x=>x.order_no)).size,18);
  assert.equal(imports[0].body.items.length,54);
  assert.ok(!imports[0].body.items.some(x=>x.order_no==='FAKE-ORDER-0018'||x.order_no==='FAKE-ORDER-0019'));
  const update=f.ctx.syncCounterPatch({orders:0,items:0,pendingItemOrders:[]},out);
  assert.equal(update.pendingItemCount,2);
  assert.equal(update.pendingReasons.product_count_exceeds_snapshot,1);
  assert.equal(update.pendingReasons.missing_valid_items,1);
  await f.ctx.setState('42',{...update,job:'sync',scanComplete:true,partial:true,
    accountId:'42',scanId:'saved-checkpoint',offset:-1,done:false});
  const original=structuredClone(f.storage.syncStates['42']);
  detailAvailable=true;
  await f.ctx.retryPendingItemOrders(7);
  const result=f.storage.syncStates['42'];
  assert.equal(result.pendingItemCount,0,'both problematic Orders recovered from fresh Detail');
  assert.equal(result.pendingItemOrders.length,0);
  assert.equal(result.done,true);
  assert.equal(result.partial,false);
  assert.equal(result.scanId,original.scanId);
  assert.equal(result.offset,original.offset,'retry never rewinds checkpoint');
  assert.equal(f.posts.filter(x=>x.url.endsWith('/api/import.php')).length,3,'exactly two targeted imports');
});

test('metadata-only retry retains pending Orders and reports incomplete rather than false success',async()=>{
  const f=fixture({detailOnlyMeta:true});
  const ref={orderNo:'FAKE-PENDING-123',listType:3,shopId:'400',shopName:'Fixture Shop',productCount:7,reason:'product_count_exceeds_snapshot'};
  await f.ctx.setState('42',{job:'sync',scanComplete:true,partial:true,pendingItemOrders:[ref],pendingItemCount:1});
  await f.ctx.retryPendingItemOrders(7);
  const state=f.storage.syncStates['42'];
  assert.equal(state.pendingItemCount,1);
  assert.equal(state.partial,true);
  assert.equal(state.done,false);
  assert.equal(f.posts.length,0,'no incomplete snapshot persisted');
  assert.equal(state.pendingReasons.buyer_detail_has_no_items,1);
});


test('fresh Full Sync from an empty database continues 18/20 and stops as PARTIAL with preserved retry ledger',async()=>{
  const rows=Array.from({length:20},(_,i)=>fakeOrder('FAKE-FULL-'+String(i).padStart(4,'0')));
  rows[18].info_card.product_count=9;
  for(const item of rows[19].info_card.order_list_cards[0].product_info.item_groups[0].items)item.status=3;
  const f=fixture({detailOnlyMeta:true,listRecords:rows});
  await f.ctx.runSync(7,true);
  const st=f.storage.syncStates['42'];
  assert.equal(st.running,false);
  assert.equal(st.pages,1);
  assert.equal(st.offset,-1,'pagination advanced past accepted page');
  assert.equal(st.orders,18);
  assert.equal(st.pendingItemCount,2);
  assert.equal(st.partial,true);
  assert.equal(st.done,false,'never claim complete with two unreadable Orders');
  assert.equal(st.scanComplete,true);
  assert.ok(st.status.includes('2 Order'));
  assert.equal(f.posts.filter(p=>p.url.endsWith('/api/import.php')).length,1);
  assert.equal(f.posts.filter(p=>p.url.endsWith('/api/reconcile.php')).length,0,
    'no whole-account reconcile while unresolved Orders remain');
});

test('Recent Sync keeps unresolved Orders rather than declaring complete',async()=>{
  const rows=[fakeOrder('FAKE-RECENT-GOOD'),fakeOrder('FAKE-RECENT-BAD')];
  rows[1].info_card.product_count=99;
  const f=fixture({detailOnlyMeta:true,listRecords:rows});
  await f.ctx.runRecentSync(7,true);
  const st=f.storage.syncStates['42'];
  assert.equal(st.running,false);
  assert.equal(st.orders,1);
  assert.equal(st.pendingItemCount,1);
  assert.equal(st.partial,true);
  assert.equal(st.done,false);
  assert.equal(st.scanComplete,true);
  assert.equal(f.posts.filter(p=>p.url.endsWith('/api/import.php')).length,1);
});

test('pending-order retry never writes to a different PAN URL or Shopee account',async()=>{
  const f=fixture();const row={orderNo:'FAKE-PENDING-987',listType:3,shopId:'400',shopName:'Fixture Shop',
    productCount:7,reason:'product_count_exceeds_snapshot'};
  await f.ctx.setState('42',{pendingItemOrders:[row],pendingItemCount:1,
    pendingHub:'https://another-pan.example.test',job:'sync',scanComplete:true,partial:true});
  await f.ctx.retryPendingItemOrders(7);
  assert.equal(f.storage.syncStates['42'].pendingItemCount,1);
  assert.ok(f.storage.syncStates['42'].error.includes('PAN URL'));
  assert.equal(f.posts.length,0);
  const g=fixture({accounts:[42,99]});
  await g.ctx.setState('42',{pendingItemOrders:[row],pendingItemCount:1,
    pendingHub:'http://localhost/pan',job:'sync',scanComplete:true,partial:true});
  await g.ctx.retryPendingItemOrders(7);
  assert.equal(g.storage.syncStates['42'].pendingItemCount,1);
  assert.ok(g.storage.syncStates['42'].error.includes('account switched'));
  assert.equal(g.posts.length,0);
});
