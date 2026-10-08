import { test } from 'node:test';
import assert from 'node:assert/strict';
import { accessIssue } from '../access.mjs';
test('restored anti-fraud state blocks new sync and scheduled attempts', () => {
  const restored = JSON.parse('{"error":"Shopee anti-fraud 90309999","running":false}');
  assert.equal(accessIssue(restored).code, 'shopee_rejected');
});
test('expired session requires action; ordinary transient errors remain retryable', () => {
  assert.equal(accessIssue({error:'Shopee HTTP 403'}).code,'login_required');
  assert.equal(accessIssue({error:'Shopee HTTP 500 หลัง retry'}),null);
  assert.equal(accessIssue({error:''}),null);
});
