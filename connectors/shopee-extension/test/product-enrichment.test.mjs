import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const source=await readFile(new URL('../background.js',import.meta.url),'utf8');

async function fixture({count=102,failingItems=[],switchAfter=Infinity}={}){
  const failed=new Set(failingItems);
  const products=Array.from({length:count},(_,i)=>({shop_id:'100',item_id:String(i+1).padStart(4,'0')}));
  const completed=new Set(),queueCalls=[],enriched=[],states={},storage={hubUrl:'http://localhost/pan',apiKey:'fixture'};
  let accountReads=0;
  const sandbox={console,Date,crypto:globalThis.crypto,setTimeout:fn=>{queueMicrotask(fn);return 0},clearTimeout(){},fetch:async(url,opts={})=>{
    const parsed=new URL(url);
    if(parsed.pathname.endsWith('/api/product_queue.php')){
      assert.equal(parsed.searchParams.get('account_id'),'42');
      const afterShop=parsed.searchParams.get('after_shop_id')||'',afterItem=parsed.searchParams.get('after_item_id')||'';
      const limit=Number(parsed.searchParams.get('limit'))||100;
      const pending=products.filter(x=>!completed.has(x.item_id)).filter(x=>!afterShop||x.shop_id>afterShop||x.shop_id===afterShop&&x.item_id>afterItem);
      const rows=pending.slice(0,limit),last=rows.at(-1);
      queueCalls.push({afterShop,afterItem,returned:rows.map(x=>x.item_id)});
      return {ok:true,status:200,text:async()=>JSON.stringify({ok:true,products:rows,next_cursor:last?{shop_id:last.shop_id,item_id:last.item_id}:null,has_more:rows.length===limit})};
    }
    if(parsed.pathname.endsWith('/api/product_enrich.php')){
      const body=JSON.parse(opts.body);
      completed.add(body.item_id);enriched.push(body);
      return {ok:true,status:200,text:async()=>JSON.stringify({ok:true,updated_rows:1})};
    }
    throw new Error('Unexpected hub path: '+parsed.pathname);
  },chrome:{storage:{local:{get:async keys=>Object.fromEntries((Array.isArray(keys)?keys:[keys]).map(k=>[k,structuredClone(storage[k])])),set:async values=>Object.assign(storage,structuredClone(values))}},runtime:{sendMessage:async()=>{},onMessage:{addListener(){}}},scripting:{executeScript:async({func,args=[]})=>{
    if(func.toString().includes('get_account_info'))return [{result:{ok:true,account:{userid:++accountReads>switchAfter?99:42,username:'fixture'}}}];
    if(func.toString().includes('/api/v4/pdp/get_pc')){
      const item=String(args[1]);
      if(failed.has(item))return [{result:{ok:false,http:403,json:null}}];
      return [{result:{ok:true,http:200,json:{data:{item:{categories:[{catid:1,display_name:'สินค้า'},{catid:2,display_name:'สำนักงาน'}]}}}}}];
    }
    throw new Error('Unexpected main-world call');
  }}}};
  const ctx=vm.createContext(sandbox);vm.runInContext(source,ctx);
  return {run:()=>ctx.runProductEnrichment(1),queueCalls,enriched,completed,products,storage,states};
}

test('category cursor visits later products despite failure in first batch',async()=>{
  const f=await fixture({count:102,failingItems:['0001']});await f.run();
  assert.equal(f.queueCalls.length,2);
  assert.deepEqual(f.queueCalls.map(x=>x.returned.length),[100,2]);
  assert.equal(f.queueCalls[1].afterItem,'0100');
  assert.equal(f.enriched.length,101);
  assert.equal(f.completed.has('0001'),false);
  const st=f.storage.syncStates['42'];
  assert.equal(st.productEnrichDone,101);assert.equal(st.productEnrichErrors,1);assert.equal(st.done,true);
});

test('category failures do not loop endlessly or prevent subsequent pages',async()=>{
  const f=await fixture({count:102,failingItems:Array.from({length:102},(_,i)=>String(i+1).padStart(4,'0'))});await f.run();
  assert.equal(f.queueCalls.length,2);
  assert.equal(f.enriched.length,0);
  assert.equal(f.storage.syncStates['42'].productEnrichErrors,102);
  assert.equal(f.storage.syncStates['42'].done,true);
});

test('category enrichment aborts on Shopee account switch',async()=>{
  const f=await fixture({count:8,switchAfter:2});await f.run();
  const st=f.storage.syncStates['42'];
  assert.equal(st.done,false);
  assert.match(st.error,/บัญชี Shopee เปลี่ยน/);
  assert.equal(f.enriched.length,1);
});
