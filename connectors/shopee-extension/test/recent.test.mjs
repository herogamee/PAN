import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFile } from 'node:fs/promises';
import { webcrypto } from 'node:crypto';
const source=await readFile(new URL('../background.js',import.meta.url),'utf8');
function order(id,date='2026-09-15',type=3){return {list_type:type,info_card:{order_id:id,create_time:date?Date.parse(date+'T00:00:00Z')/1000:undefined,final_total:10000000,order_list_cards:[{shop_info:{shop_id:1,shop_name:'Fixture'},product_info:{item_groups:[{items:[{item_id:1,name:'Item',amount:1,item_price:10000000}]}]}}]}};}
async function fixture({pages,seed={},failImport=false,cutoff='2026-09-08',accountIds=[],initialPanOrders=0,existingOrderNos=[]}={}){
  const storage={hubUrl:'http://localhost/pan',apiKey:'fixture',...seed},requests=[],posts=[];let identities=0;
  const knownOrders=new Set(existingOrderNos.map(String));const basePanOrders=Math.max(0,Number(initialPanOrders||0)-knownOrders.size);
  const panCount=()=>basePanOrders+knownOrders.size;
  const sandbox={crypto:webcrypto,console,setTimeout:fn=>{queueMicrotask(fn);return 0;},clearTimeout(){},fetch:async(url,opts={})=>{
    if(url.includes('/api/status.php'))return {ok:true,status:200,text:async()=>JSON.stringify({ok:true,version:'2.4.1',purchase_orders:panCount(),orders:panCount(),accounts:1})};
    if(url.includes('sync_anchor.php'))return {ok:true,status:200,text:async()=>JSON.stringify({ok:true,account_id:'42',cutoff_date:cutoff,latest_order_date:'2026-09-15'})};
    const body=opts.body?JSON.parse(opts.body):{};posts.push({url,body});
    if(failImport&&url.endsWith('/import.php'))return {ok:false,status:503,text:async()=>'"{\"ok\":false,\"error\":\"import failed\"}"'.slice(1,-1)};
    if(url.endsWith('/import.php')){
      const orderNos=[...new Set((body.items||[]).map(x=>String(x.order_no||'')).filter(Boolean))];let inserted=0,updated=0;
      for(const no of orderNos){if(knownOrders.has(no))updated++;else{knownOrders.add(no);inserted++;}}
      return {ok:true,status:200,text:async()=>JSON.stringify({ok:true,orders:orderNos.length,items:(body.items||[]).length,inserted_orders:inserted,updated_orders:updated,pan_purchase_orders:panCount(),pan_total_orders:panCount()})};
    }
    if(url.endsWith('/cancelled.php')){
      let deleted=0;for(const no of body.order_nos||[]){if(knownOrders.delete(String(no)))deleted++;}
      return {ok:true,status:200,text:async()=>JSON.stringify({ok:true,deleted,pan_purchase_orders:panCount(),pan_total_orders:panCount()})};
    }
    return {ok:true,status:200,text:async()=>'"{\"ok\":true}"'.slice(1,-1)};
  },chrome:{storage:{local:{get:async keys=>Object.fromEntries((Array.isArray(keys)?keys:[keys]).map(k=>[k,structuredClone(storage[k])])),set:async values=>Object.assign(storage,structuredClone(values))}},runtime:{sendMessage:async()=>{},onMessage:{addListener(){}}},scripting:{executeScript:async({func,args})=>{
    if(func.toString().includes('get_account_info'))return [{result:{ok:true,account:{userid:accountIds[identities++]||42,username:'fixture'}}}];
    // Buyer detail calls are separate from paginated list reads. This fixture
    // intentionally offers metadata-only detail so preview fallback remains tested.
    if(func.toString().includes('get_order_detail'))return [{result:{ok:true,http:200,json:{error:0,data:{pc_processing_info:{}}},url:'https://shopee.co.th/api/v4/order/get_order_detail'}}];
    const primary=func.toString().includes('get_all_order_and_checkout_list');
    const type=primary?'primary':args[0],offset=primary?args[0]:args[1];requests.push({type,offset});
    const result=pages?.(type,offset)||{data:{details_list:[],next_offset:-1}};
    return [{result:{ok:!result.http||result.http===200,http:result.http||200,json:result,url:'https://shopee.co.th/api/v4/order/get_order_list'}}];
  }}}};
  const ctx=vm.createContext(sandbox);vm.runInContext(source,ctx);
  return {ctx,storage,requests,posts,run:fresh=>ctx.runRecentSync(1,fresh),state:()=>storage.syncStates?.['42']||{}};
}

test('uses primary history and stops after two old pages without reconcile',async()=>{
  const f=await fixture({pages:(type,offset)=>type==='primary'?{data:{details_list:[order('p'+offset,offset===0?'2026-09-15':'2026-01-01')],next_offset:offset+20}}:undefined});
  await f.run(true);
  assert.deepEqual(f.requests.map(r=>r.offset),[0,20,40]);
  assert.ok(f.requests.every(r=>r.type==='primary'));
  assert.equal(f.state().done,true);
  assert.ok(!f.posts.some(p=>p.url.endsWith('/reconcile.php')));
});
test('unknown dates cannot trigger early stop; blank anchor traverses history',async()=>{
  for(const cutoff of ['2026-09-08','']){
    const f=await fixture({cutoff,pages:(type,offset)=>type==='primary'?{data:{details_list:[order('p'+offset,cutoff?'':'2026-01-01')],next_offset:offset===60?-1:offset+20}}:undefined});
    await f.run(true);assert.equal(f.requests.length,4);
  }
});
test('failed import retains offset for retry and does not reconcile',async()=>{
  const f=await fixture({failImport:true,pages:type=>type==='primary'?{data:{details_list:[order('a','2026-09-15',7)],next_offset:20}}:undefined});
  await f.run(true);assert.match(f.state().error,/import failed/);assert.equal(f.state().recentOffset,0);assert.equal(f.state().done,false);
  assert.equal(f.posts.length,1);
});
test('resume uses saved status offset, scan ID and cutoff',async()=>{
  const f=await fixture({seed:{syncStates:{'42':{job:'recent',recentHub:'http://localhost/pan',recentIndex:4,recentOffset:40,recentOldPages:1,recentSeen:['3:20'],cutoff:'2026-09-08',scanId:'saved',orders:2,pages:3}}},pages:type=>type===3?{data:{details_list:[order('old','2026-01-01')],next_offset:60}}:undefined});
  await f.run(false);assert.deepEqual(f.requests[0],{type:3,offset:40});
  assert.equal(f.posts[0].body.scan_id,'saved');assert.equal(f.state().done,true);
});
test('account switch and anti-fraud stop without importing other-account data',async()=>{
  const switched=await fixture({accountIds:[42,99]});await switched.run(true);assert.match(switched.state().error,/บัญชี Shopee เปลี่ยน/);assert.equal(switched.posts.length,0);
  const blocked=await fixture({pages:()=>({error:90309999})});await blocked.run(true);assert.match(blocked.state().error,/90309999/);assert.equal(blocked.posts.length,0);
});
test('cancellations are deleted through account-scoped API and not imported',async()=>{
  const f=await fixture({pages:type=>type==='primary'?{data:{details_list:[order('cancelled','2026-09-15',4)],next_offset:-1}}:undefined});
  await f.run(true);assert.equal(f.posts.length,1);assert.ok(f.posts[0].url.endsWith('/cancelled.php'));assert.equal(f.posts[0].body.account_id,'42');
});
test('legacy status schema failure migrates to primary offset zero with the same scan',async()=>{
  const seed={syncStates:{'42':{job:'recent',recentHub:'http://localhost/pan',recentIndex:0,recentOffset:40,cutoff:'2026-09-08',scanId:'legacy'}}};
  const f=await fixture({seed,pages:type=>type==='primary'?{new_data:{order_or_checkout_data:[{order_list_detail:order('a')}],next_offset:-1}}:{data:{unrecognized_list:[]}}});
  await f.run(false);
  assert.deepEqual(f.requests,[{type:7,offset:40},{type:'primary',offset:0}]);
  assert.equal(f.posts[0].body.scan_id,'legacy');
  assert.equal(f.state().done,true);
  assert.equal(f.state().recentEndpoint,'primary');
  assert.ok(!f.posts.some(p=>p.url.endsWith('/reconcile.php')));
});
test('nonempty unknown primary records stop and preserve offset instead of reporting empty success',async()=>{
  const f=await fixture({pages:()=>({new_data:{order_or_checkout_data:[{unknown_record:true}],next_offset:20}})});
  await f.run(true);
  assert.equal(f.state().done,false);
  assert.equal(f.state().recentOffset,0);
  assert.match(f.state().error,/schema/);
  assert.equal(f.posts.length,0);
});
test('primary resume retains its offset and endpoint',async()=>{
  const seed={syncStates:{'42':{job:'recent',recentEndpoint:'primary',recentHub:'http://localhost/pan',recentIndex:0,recentOffset:60,cutoff:'2026-09-08',scanId:'primary-saved'}}};
  const f=await fixture({seed,pages:()=>({data:{details_list:[order('last')],next_offset:-1}})});
  await f.run(false);assert.deepEqual(f.requests,[{type:'primary',offset:60}]);assert.equal(f.posts[0].body.scan_id,'primary-saved');
});

test('status parser accepts order_or_checkout_data wrapper without advancing unsafely',async()=>{
  const seed={syncStates:{'42':{job:'recent',recentEndpoint:'status',recentHub:'http://localhost/pan',recentIndex:0,recentOffset:0,cutoff:'2026-09-08',scanId:'status-wrapper'}}};
  const wrapped=order('wrapped','2026-09-15',7);delete wrapped.list_type;
  const f=await fixture({seed,pages:(type)=>type===7?{new_data:{order_or_checkout_data:[{order_list_detail:wrapped}],next_offset:-1}}:{data:{details_list:[],next_offset:-1}}});
  await f.run(false);
  assert.equal(f.state().done,true);
  assert.equal(f.posts.length,1);
  assert.equal(f.posts[0].body.items[0].list_type,7);
});

test('status parser discovers nested named order arrays and nested next_offset',async()=>{
  const f=await fixture();
  const rec=order('nested','2026-09-15',3);
  const json={data:{payload:{history:{details_list:[{order_list_detail:rec}]},page:{next_offset:80}}}};
  const parsed=f.ctx.pickStatusDetailsInfo(json);
  assert.equal(parsed.recognized,true);
  assert.equal(parsed.format,'data.payload.history.details_list');
  assert.equal(parsed.records[0].info_card.order_id,'nested');
  assert.equal(f.ctx.nextOffset(json,60,20,1),80);
});

test('status parser rejects partially unknown nonempty arrays instead of silently skipping records',async()=>{
  const f=await fixture();
  const json={data:{details_list:[order('ok'),{unknown_record:true}]}};
  const parsed=f.ctx.pickStatusDetailsInfo(json);
  assert.equal(parsed.recognized,false);
  assert.equal(parsed.records.length,0);
});

test('full sync status fallback accepts wrapped Shopee schema',async()=>{
  const wrapped=order('fallback','2026-09-15',3);delete wrapped.list_type;
  const f=await fixture({pages:(type)=>type==='primary'?{data:{details_list:[]}}:type===3?{data:{payload:{order_or_checkout_data:[{list_type:3,order_list_detail:wrapped}],page:{next_offset:-1}}}}:{data:{details_list:[],next_offset:-1}}});
  await f.ctx.runSync(1,true);
  assert.equal(f.state().done,true);
  assert.equal(f.state().apiMode,'status');
  assert.ok(f.posts.some(p=>p.url.endsWith('/import.php')));
});

test('malformed status response still preserves checkpoint',async()=>{
  const seed={syncStates:{'42':{job:'sync',apiMode:'status',statusIndex:0,statusOffset:40,statusSeenOffsets:[],scanId:'safe-stop',pages:2,orders:0,items:0}}};
  const f=await fixture({seed,pages:(type)=>type===3?{data:{payload:{details_list:[{unknown_record:true}],page:{next_offset:60}}}}:{data:{details_list:[],next_offset:-1}}});
  await f.ctx.runSync(1,false);
  assert.equal(f.state().done,false);
  assert.equal(f.state().statusOffset,40);
  assert.match(f.state().error,/status-list schema/);
  assert.equal(f.posts.length,0);
});

test('primary metadata-only response retries same offset then falls back safely without advancing checkpoint',async()=>{
  const f=await fixture({pages:(type,offset)=>{
    if(type==='primary')return {error:0,error_msg:'',new_data:{next_offset:20,translation_status:1}};
    if(type===7)return {data:{details_list:[order('status-order','2026-09-15',7)],next_offset:-1}};
    return {data:{details_list:[],next_offset:-1}};
  }});
  await f.run(true);
  assert.deepEqual(f.requests.slice(0,3),[{type:'primary',offset:0},{type:'primary',offset:0},{type:'primary',offset:0}]);
  assert.ok(f.requests.some(r=>r.type===7&&r.offset===0));
  assert.ok(!f.requests.some(r=>r.type==='primary'&&r.offset===20));
  assert.equal(f.state().done,true);
  assert.ok(f.posts.some(p=>p.url.endsWith('/import.php')));
});

test('metadata-only shape is identified and diagnostics include next_offset and translation_status',async()=>{
  const f=await fixture();
  const json={error:0,error_msg:'',new_data:{next_offset:40,translation_status:{state:'pending'}}};
  assert.equal(f.ctx.primaryMetadataOnly(json),true);
  const shape=f.ctx.responseShape(json);
  assert.equal(shape.new_data_next_offset,40);
  assert.equal(shape.translation_status.type,'object');
  assert.deepEqual(Array.from(shape.arrays),[]);
});

test('status fallback failure after primary metadata-only does not bounce back to primary or move checkpoint',async()=>{
  const f=await fixture({pages:(type,offset)=>{
    if(type==='primary')return {error:0,new_data:{next_offset:20,translation_status:0}};
    if(type===7)return {error:0,data:{unknown_payload:{value:1}}};
    return {data:{details_list:[],next_offset:-1}};
  }});
  await f.run(true);
  assert.equal(f.state().done,false);
  assert.equal(f.state().recentEndpoint,'status');
  assert.equal(f.state().recentIndex,0);
  assert.equal(f.state().recentOffset,0);
  assert.match(f.state().error,/status fallback schema/);
  const primaryRequests=f.requests.filter(r=>r.type==='primary');
  assert.equal(primaryRequests.length,3);
  assert.ok(primaryRequests.every(r=>r.offset===0));
});

test('full sync treats status data metadata next_offset -1 as verified end of current status',async()=>{
  const seed={syncStates:{'42':{job:'sync',apiMode:'status',statusIndex:0,statusOffset:1100,statusSeenOffsets:['3:1080'],scanId:'terminal-meta',pages:55,orders:1059,items:2000}}};
  const f=await fixture({seed,pages:(type,offset)=>{
    if(type===3&&offset===1100)return {error:0,error_msg:'',data:{next_offset:-1,translation_status:0}};
    return {error:0,data:{details_list:[],next_offset:-1}};
  }});
  await f.ctx.runSync(1,false);
  assert.equal(f.state().done,true);
  assert.equal(f.state().error||'','');
  assert.deepEqual(f.requests[0],{type:3,offset:1100});
  assert.equal(f.state().statusIndex,6);
  assert.deepEqual(f.requests[1],{type:7,offset:0});
  assert.equal(f.requests.filter(r=>r.type===3&&r.offset===1100).length,1);
});

test('full sync retries nonterminal status metadata at same offset and preserves checkpoint',async()=>{
  const seed={syncStates:{'42':{job:'sync',apiMode:'status',statusIndex:0,statusOffset:1100,statusSeenOffsets:['3:1080'],scanId:'nonterminal-meta',pages:55,orders:1059,items:2000}}};
  const f=await fixture({seed,pages:(type,offset)=>type===3?{error:0,data:{next_offset:1120,translation_status:0}}:{error:0,data:{details_list:[],next_offset:-1}}});
  await f.ctx.runSync(1,false);
  assert.equal(f.state().done,false);
  assert.equal(f.state().statusOffset,1100);
  assert.match(f.state().error,/metadata-only/);
  assert.deepEqual(f.requests.slice(0,3),[{type:3,offset:1100},{type:3,offset:1100},{type:3,offset:1100}]);
  assert.ok(!f.requests.some(r=>r.type===3&&r.offset===1120));
});

test('recent status fallback treats terminal metadata as end of status without bouncing endpoint',async()=>{
  const seed={syncStates:{'42':{job:'recent',recentEndpoint:'status',recentFallbackFromPrimary:true,recentHub:'http://localhost/pan',recentIndex:0,recentOffset:1100,recentOldPages:0,recentSeen:['7:1080'],cutoff:'2026-09-08',scanId:'recent-terminal'}}};
  const f=await fixture({seed,pages:(type,offset)=>{
    if(type===7&&offset===1100)return {error:0,data:{next_offset:-1,translation_status:0}};
    return {error:0,data:{details_list:[],next_offset:-1}};
  }});
  await f.run(false);
  assert.equal(f.state().done,true);
  assert.equal(f.state().recentEndpoint,'status');
  assert.equal(f.state().error||'','');
  assert.deepEqual(f.requests[0],{type:7,offset:1100});
});

test('full sync skips Shopee API 33800002 only for optional non-purchase status 12 and continues cancellation scan',async()=>{
  const seed={syncStates:{'42':{job:'sync',apiMode:'status',statusIndex:3,statusOffset:0,statusSeenOffsets:['3:0','8:0'],scanId:'live-33800002',pages:115,orders:2132,items:3218}}};
  const f=await fixture({seed,pages:(type,offset)=>{
    if(type===12&&offset===0)return {error:33800002,error_msg:'',data:{}};
    if(type===4)return {error:0,data:{details_list:[],next_offset:-1}};
    if(type===9)return {error:33800002,error_msg:'',data:{}};
    return {error:0,data:{details_list:[],next_offset:-1}};
  }});
  await f.ctx.runSync(1,false);
  assert.equal(f.state().done,true);
  assert.equal(f.state().error||'','');
  assert.deepEqual(f.requests.map(r=>r.type),[12,4,9]);
  assert.deepEqual(Array.from(f.state().skippedStatusTypes||[]),[12,9]);
  assert.equal(f.state().lastOptionalStatusError.error,33800002);
  assert.equal(f.state().lastOptionalStatusError.list_type,9);
});

test('Shopee API 33800002 on purchase status remains a hard stop and preserves checkpoint',async()=>{
  const seed={syncStates:{'42':{job:'sync',apiMode:'status',statusIndex:0,statusOffset:40,statusSeenOffsets:['3:20'],scanId:'purchase-error',pages:2,orders:10,items:10}}};
  const f=await fixture({seed,pages:(type,offset)=>type===3&&offset===40?{error:33800002,error_msg:'',data:{}}:{error:0,data:{details_list:[],next_offset:-1}}});
  await f.ctx.runSync(1,false);
  assert.equal(f.state().done,false);
  assert.equal(f.state().statusIndex,0);
  assert.equal(f.state().statusOffset,40);
  assert.match(f.state().error,/33800002/);
  assert.deepEqual(Array.from(f.state().skippedStatusTypes||[]),[]);
});

test('recent status fallback skips 33800002 for optional non-purchase types without returning to primary',async()=>{
  const seed={syncStates:{'42':{job:'recent',recentEndpoint:'status',recentFallbackFromPrimary:true,recentHub:'http://localhost/pan',recentIndex:2,recentOffset:0,recentOldPages:0,recentSeen:[],cutoff:'2026-09-08',scanId:'recent-optional'}}};
  const f=await fixture({seed,pages:(type)=>{
    if(type===9||type===12)return {error:33800002,error_msg:'',data:{}};
    return {error:0,data:{details_list:[],next_offset:-1}};
  }});
  await f.run(false);
  assert.equal(f.state().done,true);
  assert.equal(f.state().recentEndpoint,'status');
  assert.equal(f.state().error||'','');
  assert.deepEqual(Array.from(f.state().skippedStatusTypes||[]),[9,12]);
  assert.ok(!f.requests.some(r=>r.type==='primary'));
});

test('v2.4.7 separates Shopee records, unique orders, duplicate reads and PAN insert/update counts',async()=>{
  let primaryMetaCalls=0;
  const f=await fixture({pages:(type,offset)=>{
    if(type==='primary'&&offset===0)return {error:0,data:{details_list:[order('same','2026-09-16',3)],next_offset:20}};
    if(type==='primary'&&offset===20){primaryMetaCalls++;return {error:0,new_data:{next_offset:40,translation_status:0}};}
    if(type===3&&offset===0)return {error:0,data:{details_list:[order('same','2026-09-16',3)],next_offset:-1}};
    return {error:0,data:{details_list:[],next_offset:-1}};
  }});
  await f.ctx.runSync(1,true);
  const st=f.state();
  assert.equal(primaryMetaCalls,3);
  assert.equal(st.done,true);
  assert.equal(st.shopeeRecords,2);
  assert.equal(st.shopeeUniqueOrders,1);
  assert.equal(st.duplicateRecords,1);
  assert.equal(st.panInsertedOrders,1);
  assert.equal(st.panUpdatedOrders,1);
  assert.equal(st.panOrders,1);
  assert.equal(st.orders,2); // legacy processed counter retained for debug/backward compatibility
});

test('v2.4.8 mixed structural page stops before import so checkpoint cannot skip one bad order',async()=>{
  const f=await fixture();
  const bad=order('bad');bad.info_card.order_list_cards[0].shop_info={};
  await assert.rejects(()=>f.ctx.processSyncRecords([order('ok'),bad],{userid:42,username:'fixture'},'http://localhost/pan','scan','https://shopee.co.th/user/purchase',[]),/Shopee schema/);
  assert.equal(f.posts.length,0);
});

test('v2.4.8 detail parser reads nested payment and logistics labels and records missing fields honestly',async()=>{
  const f=await fixture();
  const meta=f.ctx.detailMeta({data:{payment_info:{payment_channel:{display_name:'ShopeePay'}},shipping:{logistics_channel:{name:'SPX Express'},tracking_info:{tracking_number:'TH123'}},pc_processing_info:{complete_time:Date.parse('2026-09-17T00:00:00Z')/1000}}});
  assert.equal(meta.payment_method,'ShopeePay');
  assert.equal(meta.shipping_carrier,'SPX Express');
  assert.equal(meta.tracking_number,'TH123');
  assert.equal(meta.completed_at.startsWith('2026-09-17'),true);
  assert.equal(meta.detail_missing_fields,'');
});

test('missing buyer carrier and received timestamp never trigger repeat Detail; tracking/raw metadata kept',async()=>{
  const f=await fixture();
  const delivered=Math.floor(Date.parse('2026-10-08T14:00:00+07:00')/1000);
  const meta=f.ctx.detailMeta({data:{info_card:{list_type:3},shipping:{tracking_info:{tracking_number:'SYNTHETIC-TRACK',delivered_time:delivered}}}});
  assert.equal(meta.shipping_carrier,'');
  assert.equal(meta.tracking_number,'SYNTHETIC-TRACK');
  assert.equal(meta.delivered_at,'2026-10-08 14:00:00');
  assert.equal(meta.detail_missing_fields,'');
  assert.equal(JSON.parse(meta.metadata_json).sources.carrier,'');
  const incomplete=f.ctx.detailMeta({data:{info_card:{list_type:3},shipping:{tracking_info:{tracking_number:'SYNTHETIC-TRACK'}}}});
  assert.equal(incomplete.detail_missing_fields,'');
  const transit=f.ctx.detailMeta({data:{info_card:{list_type:7},shipping:{tracking_info:{tracking_number:'SYNTHETIC-TRACK'}}}});
  assert.equal(transit.detail_missing_fields,'');
});

test('payment detail prioritizes readable label over numeric Shopee codes, and retains raw provenance',async()=>{
  const f=await fixture();
  const meta=f.ctx.detailMeta({data:{payment_info:{payment_method:92,payment_channel:{display_name:'ShopeePay'}},
    shipping:{logistics_channel:{name:'SPX Express'}},pc_processing_info:{complete_time:1788000000}}});
  assert.equal(meta.payment_method,'ShopeePay');
  assert.equal(meta.detail_missing_fields,'');
  const origin=JSON.parse(meta.metadata_json);
  assert.equal(origin.codes.payment_method,'92');
  assert.match(origin.sources.payment,/payment_channel/);
  const named=f.ctx.detailMeta({data:{payment_info:{payment_method:6,payment_method_name:'SPayLater'},
    shipping:{carrier_name:'Courier'},pc_processing_info:{complete_time:1788000000}}});
  assert.equal(named.payment_method,'SPayLater');
  assert.equal(JSON.parse(named.metadata_json).codes.payment_method,'6');
});

test('numeric-only payment codes remain raw metadata but no longer determine delivery coverage',async()=>{
  const f=await fixture();
  for(const code of [6,92]){
    const meta=f.ctx.detailMeta({data:{payment_info:{payment_method:code},
      shipping:{carrier_name:'Courier'},pc_processing_info:{complete_time:1788000000}}});
    assert.equal(meta.payment_method,String(code));
    assert.doesNotMatch(meta.detail_missing_fields,/payment_method/);
    assert.equal(JSON.parse(meta.metadata_json).codes.payment_method,String(code));
  }
});

test('v2.4.8 category parser accepts Shopee category breadcrumb arrays',async()=>{
  const f=await fixture();
  const cat=f.ctx.extractCategoryInfo({data:{item:{categories:[{catid:1,display_name:'บ้านและสวน'},{catid:2,display_name:'เครื่องมือ'}]}}});
  assert.equal(cat.category_id,'2');
  assert.equal(cat.category_name,'เครื่องมือ');
  assert.equal(cat.category_path,'บ้านและสวน > เครื่องมือ');
});

test('v2.4.8 full sync checks account identity before every page',async()=>{
  const f=await fixture({accountIds:[42,99],pages:()=>({error:0,data:{details_list:[order('a')],next_offset:-1}})});
  await f.ctx.runSync(1,true);
  assert.match(f.state().error,/บัญชี Shopee เปลี่ยนระหว่าง Full Sync/);
  assert.equal(f.posts.length,0);
});

test('order-created timestamp is stored with actual Bangkok time, not silently downcast to date',async()=>{
  const f=await fixture();
  const stamp=Math.floor(Date.parse('2026-09-15T14:32:11+07:00')/1000);
  const d=order('created-with-time');d.info_card.create_time=stamp;
  const row=f.ctx.normalizeOrder(d,{userid:42,username:'fixture'});
  assert.equal(row.ignoredReason,'');
  assert.equal(row.items[0].order_created_at,'2026-09-15 14:32:11');
  assert.equal(row.items[0].order_date,'2026-09-15');
  assert.equal(row.items[0].date_source,'info_card.create_time');
});

test('only date precision from API stays date-only; no fabricated 07:00 at UTC midnight',async()=>{
  const f=await fixture();
  const d=order('date-only');d.info_card.create_time='2026-09-15';
  const row=f.ctx.normalizeOrder(d,{userid:42,username:'fixture'});
  assert.equal(row.items[0].order_date,'2026-09-15');
  assert.equal(row.items[0].order_created_at,'');
  assert.equal(f.ctx.extractBestListDate(d).precision,'date');
  assert.equal(f.ctx.normalizeEpochSeconds('2026-09-15'),null);
});

test('payment time and shipping activity may not masquerade as order-created date',async()=>{
  const f=await fixture();
  const d=order('paid-and-shipped-only','');
  d.info_card.pay_time=Math.floor(Date.parse('2026-10-05T06:00:00Z')/1000);
  d.shipping={tracking_info:{ctime:Math.floor(Date.parse('2026-10-06T09:00:00Z')/1000)}};
  assert.equal(f.ctx.extractBestListDate(d),null);
  const row=f.ctx.normalizeOrder(d,{userid:42,username:'fixture'});
  assert.equal(row.items[0].order_date,'');
  assert.equal(row.items[0].order_created_at,'');
  assert.equal(row.items[0].date_source,'unknown');
});

test('order Complete, shipping ETA and hub received time are not buyer delivery',async()=>{
  const f=await fixture();
  const at=Math.floor(Date.parse('2026-09-18T12:23:00Z')/1000);
  const meta=f.ctx.detailMeta({data:{pc_processing_info:{complete_time:at},
    shipping:{delivery_time:at,estimated_delivered_time:at,tracking_info:{received_time:at}},
    buyer_received_time:at}});
  assert.equal(meta.completed_at,'2026-09-18 19:23:00');
  assert.equal(meta.delivered_at,'');
  assert.equal(meta.delivery_date_source,'');
});

test('raw delivered-like timestamps remain metadata only, never required for Detail completeness',async()=>{
  const f=await fixture();
  const delivery=Math.floor(Date.parse('2026-09-17T13:19:20+07:00')/1000);
  const complete=Math.floor(Date.parse('2026-09-18T10:10:10+07:00')/1000);
  const meta=f.ctx.detailMeta({data:{shipping:{tracking_info:{delivered_time:delivery}},pc_processing_info:{complete_time:complete}}});
  assert.equal(meta.delivered_at,'2026-09-17 13:19:20');
  assert.equal(meta.completed_at,'2026-09-18 10:10:10');
  assert.equal(meta.delivery_date_source,'detail.shipping.tracking_info.delivered_time');
  assert.equal(meta.detail_missing_fields,'');
});
