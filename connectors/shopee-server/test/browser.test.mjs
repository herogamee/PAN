import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import path from 'node:path';
import { tmpdir } from 'node:os';
import { chromium } from 'playwright';
import { createCollector } from '../collector.mjs';

test('real Chromium executes collector and keeps cookies across restart, with isolated profiles', async () => {
  const dir=await mkdtemp(path.join(process.env.PAN_TEST_WORK_DIR || tmpdir(),'pan-browser-test-'));
  let context;
  try {
    context=await chromium.launchPersistentContext(path.join(dir,'one'),{headless:true});
    await context.route('https://shopee.co.th/**',async route=>{
      assert.equal(route.request().headers()['x-pan-key'],undefined);
      const url=route.request().url();
      if(url.includes('get_account_info')) return route.fulfill({json:{data:{userid:123,username:'browser-fixture'}}});
      if(url.includes('/api/')) return route.fulfill({json:{data:{details_list:[],next_offset:-1}}});
      return route.fulfill({contentType:'text/html',body:'<h1>Local test fixture</h1><input id="text">'});
    });
    const page=context.pages()[0]; await page.goto('https://shopee.co.th/user/purchase/');
    await context.addCookies([{name:'test-persistent-session',value:'fixture-only',domain:'shopee.co.th',path:'/',secure:true,httpOnly:true,expires:Math.floor(Date.now()/1000)+3600}]);
    const collector=await createCollector({page:()=>page,state:{},save:async()=>{},hubUrl:'http://localhost/pan',apiKey:'fake-key',fetchImpl:async()=>{throw new Error('Empty scan must not import');}});
    assert.equal(String((await collector.account()).userid),'123');
    await page.locator('#text').click(); await page.keyboard.insertText('ทดสอบ input');
    assert.equal(await page.locator('#text').inputValue(),'ทดสอบ input');
    assert.ok((await page.screenshot()).length>100);
    await context.close();
    context=await chromium.launchPersistentContext(path.join(dir,'one'),{headless:true});
    assert.equal((await context.cookies('https://shopee.co.th')).find(c=>c.name==='test-persistent-session')?.value,'fixture-only');
    await context.close();
    context=await chromium.launchPersistentContext(path.join(dir,'two'),{headless:true});
    assert.equal((await context.cookies('https://shopee.co.th')).length,0);
  } finally {
    await context?.close();
    const base=path.resolve(process.env.PAN_TEST_WORK_DIR || tmpdir());
    assert.ok(path.resolve(dir).startsWith(base+path.sep+'pan-browser-test-'));
    await rm(dir,{recursive:true,force:true});
  }
});
