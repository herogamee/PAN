import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {test} from 'node:test';
const source = readFileSync(new URL('../assets/orders.js', import.meta.url),'utf8');
function fixture() {
  const listeners = {};
  const button = {
    attrs: {'aria-controls':'pan-order-lines-4','aria-expanded':'false'},
    getAttribute(key){return this.attrs[key]}, setAttribute(key,val){this.attrs[key]=val},
  };
  const row = { querySelector(s){return s==='button.order-expand-button'?button:null},
    classList:{set:new Set(),toggle(name,on){if(on)this.set.add(name);else this.set.delete(name)}} };
  const detail = {hidden:true};
  const table = {addEventListener(type,cb){listeners[type]=cb}, contains(v){return v===row}};
  const document = {readyState:'complete',getElementById(id){if(id==='panOrdersTable')return table;if(id==='pan-order-lines-4')return detail;return null}};
  vm.runInNewContext(source,{document});
  function click(kind='row'){
    const interactive = kind==='shop'?{matches(){return false}}:kind==='button'?{matches(s){return s==='button.order-expand-button'}}:null;
    const node={closest(s){if(s==='tr[data-order-master]')return row;if(s==='a,button,input,select,textarea,label')return interactive;return null}};
    listeners.click({target:node});
  }
  return {button,row,detail,click};
}
test('clicking an order row opens its details then closes it on second click',()=>{
  const f=fixture();
  f.click();assert.equal(f.detail.hidden,false);assert.equal(f.button.attrs['aria-expanded'],'true');
  f.click();assert.equal(f.detail.hidden,true);assert.equal(f.button.attrs['aria-expanded'],'false');
});
test('keyboard accessible button click opens the same order panel',()=>{
  const f=fixture();f.click('button');assert.equal(f.detail.hidden,false);
});
test('clicking the shop link does not expand or prevent navigation',()=>{
  const f=fixture();f.click('shop');assert.equal(f.detail.hidden,true);
});
