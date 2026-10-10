import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { createCollector } from '../collector.mjs';

function order(id, listType = 3) {
  return { list_type: listType, info_card: { order_id: id, final_total: 18000000,
    order_list_cards: [{ shop_info: { shop_id: 1, shop_name: 'Test shop' }, product_info: {
      item_groups: [{ items: [{ item_id: 2, name: 'Test product', amount: 2, item_price: 10000000 }] }]
    } }] } };
}
async function fixture(response, seed = {}) {
  const posts = [], state = seed;
  const page = { url: () => 'https://shopee.co.th/user/purchase/', evaluate: code => vm.runInNewContext(code, {
    location: { origin: 'https://shopee.co.th' }, AbortSignal,
    fetch: async url => {
      if (url.includes('get_account_info')) return { ok: true, status: 200, json: async () => ({ data: { userid: 42, username: 'fixture' } }) };
      const result = typeof response === 'function' ? response(url) : response;
      return { ok: (result.http || 200) === 200, status: result.http || 200, json: async () => result };
    }
  }) };
  const c = await createCollector({ page: () => page, state, save: async () => {}, hubUrl: 'http://localhost/pan', apiKey: 'test-only', fetchImpl: async (url, opts) => {
    assert.equal(opts.headers['X-PAN-Key'], 'test-only');
    assert.equal(opts.redirect, 'error');
    posts.push({ url, body: JSON.parse(opts.body) });
    return { ok: true, status: 200, text: async () => '{"ok":true}' };
  } });
  return { c, state, posts };
}
test('normalizes orders, excludes cancellations, preserves unknown date and account scope', async () => {
  const { c, posts } = await fixture({ data: { details_list: [order('a'),order('cancelled',4)], next_offset: -1 } });
  await c.sync(true);
  assert.equal(c.status().done, true);
  const batch = posts.find(p => p.url.endsWith('/import.php')).body;
  assert.equal(batch.items.length, 1);
  assert.equal(batch.items[0].source_account_id, '42');
  assert.equal(batch.items[0].order_date, '');
  assert.equal(batch.items[0].actual_unit_price, 90);
  assert.equal(posts.find(p=>p.url.endsWith('/cancelled.php')).body.order_nos[0], 'cancelled');
  assert.equal(posts.find(p=>p.url.endsWith('/reconcile.php')).body.account_id, '42');
});
test('session rejection stops without import or reconcile', async () => {
  const { c, posts } = await fixture({ http: 403 });
  await c.sync(true);
  assert.match(c.status().error, /403/);
  assert.equal(c.status().running, false);
  assert.equal(posts.length, 0);
});
test('anti-fraud stops without import or reconcile', async () => {
  const { c, posts } = await fixture({ error: 90309999 });
  await c.sync(true);
  assert.match(c.status().error, /anti-fraud/);
  assert.equal(posts.length, 0);
});
test('account login is insufficient: access probe rejects anti-fraud and imports nothing', async () => {
  const { c, posts } = await fixture({error:90309999});
  assert.equal(String((await c.account()).userid),'42');
  await assert.rejects(c.checkAccess(),/90309999/);
  assert.match(c.status().error,/90309999/);
  assert.equal(posts.length,0);
});
test('successful read probe clears saved block without starting sync', async () => {
  const seed={lastAccountId:'42',syncStates:{'42':{error:'Shopee anti-fraud 90309999'}}};
  const { c,posts }=await fixture({data:{details_list:[],next_offset:-1}},seed);
  await c.checkAccess();
  assert.equal(c.status().error,'');
  assert.equal(posts.length,0);
});
test('resume continues saved pagination and scan identity', async () => {
  const seed = { lastAccountId: '42', syncStates: { '42': { accountId: '42', offset: 20, pages: 1, orders: 1, seenOffsets: [0], scanId: 'existing-scan', apiMode: 'primary' } } };
  const { c, posts } = await fixture(url => { if(url.includes('get_order_detail'))return {data:{pc_processing_info:{}}}; assert.match(url, /offset=20/); return { data: { details_list: [order('b')], next_offset: -1 } }; }, seed);
  await c.sync(false);
  assert.equal(c.status().orders, 2);
  assert.equal(posts.find(p=>p.url.endsWith('/import.php')).body.scan_id, 'existing-scan');
});
test('schema failure cannot reconcile a partial scan', async () => {
  const seed = { lastAccountId: '42', syncStates: { '42': { offset: 20, pages: 1, orders: 1, scanId: 'partial', apiMode: 'primary' } } };
  const { c, posts } = await fixture({ unexpected: true }, seed);
  await c.sync(false);
  assert.match(c.status().error, /schema/);
  assert.equal(posts.length, 0);
});
