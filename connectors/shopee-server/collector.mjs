// Run the existing collector unchanged through a small Chrome API adapter.
// Only trusted repository JavaScript is evaluated here, never browser/user input.
import vm from 'node:vm';
import { readFile } from 'node:fs/promises';
import { webcrypto } from 'node:crypto';
import { accessIssue } from './access.mjs';

export async function createCollector({ page, state, save, hubUrl, apiKey, fetchImpl = fetch }) {
  const source = await readFile(new URL('../shopee-extension/background.js', import.meta.url), 'utf8');
  const context = vm.createContext({
    console, setTimeout, clearTimeout, crypto: webcrypto,
    fetch: (url, options = {}) => {
      // Collector must never send the PAN key to a different origin/path or a redirect.
      if (!String(url).startsWith(hubUrl.replace(/\/$/, '') + '/api/')) throw new Error('Invalid PAN API destination');
      return fetchImpl(url, { ...options, redirect: 'error', signal: AbortSignal.timeout(30000) });
    },
    chrome: {
      scripting: { executeScript: async ({ func, args }) => {
        const p = page();
        if (new URL(p.url()).origin !== 'https://shopee.co.th') throw new Error('กรุณาเปิดหน้า Shopee ก่อน');
        return [{ result: await p.evaluate(`((fetch)=>(${func.toString()})(...${JSON.stringify(args)}))((url,opts={})=>globalThis.fetch(url,{...opts,signal:AbortSignal.timeout(25000)}))`) }];
      } },
      storage: { local: {
        get: async keys => {
          const all = { ...structuredClone(state), hubUrl, apiKey };
          return Object.fromEntries((Array.isArray(keys) ? keys : [keys]).map(k => [k, all[k]]));
        },
        set: async values => { Object.assign(state, structuredClone(values)); await save(); }
      } },
      runtime: { sendMessage: async () => {} }
    }
  });
  vm.runInContext(source.slice(0, source.indexOf('chrome.runtime.onMessage.addListener')), context);
  const call = (name, ...args) => context[name](...args);
  return {
    account: async () => {
      try { return await call('accountForTab', 1); }
      catch (e) {
        if (/HTTP (401|403)|error 19\b/.test(e.message)) throw new Error('กรุณาเข้าสู่ระบบ Shopee หรือยืนยันตัวตนใหม่ แล้วกดตรวจบัญชีอีกครั้ง');
        throw e;
      }
    },
    sync: fresh => call('runSync', 1, fresh),
    checkAccess: async () => {
      const account = await call('accountForTab', 1);
      const aid = String(account.userid);
      const result = await call('mainWorldPage', 1, 0, 1);
      const code = Number(result.json?.error || 0);
      let failure = '';
      if (code) failure = code === 90309999 ? 'Shopee anti-fraud 90309999' : `Shopee API error ${code}`;
      else if (!result.ok) failure = `Shopee HTTP ${result.http}`;
      else if (!call('pickDetailsInfo', result.json).recognized) failure = 'Shopee schema ไม่ตรง: ยังยืนยันสิทธิ์ดึงข้อมูลไม่ได้';
      if (failure) {
        await call('setState', aid, { error: failure, status: 'access check failed', running: false });
        throw new Error(accessIssue({ error: failure })?.message || failure);
      }
      await call('setState', aid, { error: '', status: 'ตรวจสิทธิ์ดึงข้อมูลผ่าน', running: false });
      return { account, message: 'ตรวจอ่านรายการคำสั่งซื้อผ่านแล้ว ยังไม่ได้เริ่มซิงก์' };
    },
    repair: () => call('runRepair', 1),
    pause: async () => { const id = state.lastAccountId; if (id) await call('setState', id, { paused: true, status: 'pausing' }); },
    status: () => structuredClone(state.syncStates?.[state.lastAccountId] || {})
  };
}
