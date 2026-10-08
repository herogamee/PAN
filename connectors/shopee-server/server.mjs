import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { mkdir, readFile, writeFile, rename } from 'node:fs/promises';
import { timingSafeEqual } from 'node:crypto';
import { chromium } from 'playwright';
import { createCollector } from './collector.mjs';
import { accessIssue } from './access.mjs';

const token = process.env.PAN_CONNECTOR_TOKEN || '';
const root = path.resolve(process.env.PAN_PROFILE_DIR || '');
const webRoot = path.resolve(fileURLToPath(new URL('../../', import.meta.url)));
const hubUrl = (process.env.PAN_URL || '').replace(/\/$/, '');
const apiKey = process.env.PAN_API_KEY || '';
if (token.length < 32 || !apiKey || !process.env.PAN_PROFILE_DIR || !hubUrl) throw new Error('Set PAN_CONNECTOR_TOKEN (32+ chars), PAN_PROFILE_DIR, PAN_URL and PAN_API_KEY');
if (root === webRoot || root.startsWith(webRoot + path.sep)) throw new Error('PAN_PROFILE_DIR must be outside the web root');
const hub = new URL(hubUrl);
if (hub.protocol !== 'https:' && !(hub.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(hub.hostname))) throw new Error('PAN_URL requires HTTPS except on localhost');
await mkdir(root, { recursive: true, mode: 0o700 });
let browser, page, collector, active = '', busy = false, job = null, error = '', saveQueue = Promise.resolve();
let lastState = {}, lastProfile = '', pauseRequested = false;
const port = Number(process.env.PAN_CONNECTOR_PORT || 3210);
const profileName = value => {
  if (!/^[a-zA-Z0-9_-]{1,40}$/.test(value || '')) throw new Error('Profile: use 1-40 letters, numbers, _ or -');
  return value;
};
async function close() {
  if (collector) { lastState = collector.status(); lastProfile = active; }
  if (browser) await browser.close();
  browser = page = collector = null; active = '';
}
async function open(name) {
  profileName(name);
  if (active === name && page && !page.isClosed()) return;
  await close();
  const dir = path.join(root, name);
  await mkdir(dir, { recursive: true, mode: 0o700 });
  let state = {};
  try { state = JSON.parse(await readFile(path.join(dir, 'collector.json'), 'utf8')); }
  catch (e) { if (e.code !== 'ENOENT') throw e; }
  for (const st of Object.values(state.syncStates || {})) if (st.running) Object.assign(st, { running: false, paused: true, status: 'interrupted · resume available' });
  browser = await chromium.launchPersistentContext(path.join(dir, 'browser'), {
    headless: process.env.PAN_HEADLESS !== 'false', viewport: { width: 1280, height: 900 },
    locale: 'th-TH', timezoneId: 'Asia/Bangkok', acceptDownloads: false,
    chromiumSandbox: process.platform === 'linux'
  });
  // Remote controls may only navigate to Shopee, never to local/internal services.
  await browser.route('**/*', route => {
    const req = route.request(), u = new URL(req.url());
    if (req.isNavigationRequest() && req.frame().parentFrame() === null &&
        !(u.protocol === 'https:' && (u.hostname === 'shopee.co.th' || u.hostname.endsWith('.shopee.co.th')))) return route.abort();
    return route.continue();
  });
  page = browser.pages()[0] || await browser.newPage();
  for (const other of browser.pages()) if (other !== page) await other.close();
  browser.on('page', other => { if (other !== page) other.close().catch(() => {}); });
  page.setDefaultTimeout(15000);
  const save = () => {
    const data = JSON.stringify(state);
    saveQueue = saveQueue.catch(() => {}).then(async () => {
      await writeFile(path.join(dir, 'collector.json.tmp'), data, { mode: 0o600 });
      await rename(path.join(dir, 'collector.json.tmp'), path.join(dir, 'collector.json'));
    });
    return saveQueue;
  };
  collector = await createCollector({ page: () => page, state, save, hubUrl, apiKey });
  active = name;
  error = '';
  // Do not immediately revisit a failed purchase endpoint when reopening a blocked session.
  await page.goto(accessIssue(collector.status()) ? 'https://shopee.co.th/' : 'https://shopee.co.th/user/purchase/', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await save();
}
function launchJob(kind) {
  if (!collector) throw new Error('เปิดบัญชีก่อน');
  const c = collector;
  pauseRequested = false;
  job = (async () => {
    await c.account(); // Fail visibly even before a collector state has been created.
    if (pauseRequested) return;
    if (kind === 'repair') await c.repair(); else await c.sync(kind !== 'resume');
  })().catch(e => { error = e.message; }).finally(() => { job = null; });
}
async function command(d) {
  if (d.action === 'pause') { pauseRequested = true; await collector?.pause(); return {}; }
  if (busy || job) throw new Error('มีงานกำลังทำอยู่ กรุณารอหรือหยุดงานก่อน');
  busy = true;
  try {
    if (d.action === 'open') { await open(profileName(d.profile)); return {}; }
    if (d.action === 'close') { await close(); return {}; }
    if (!page || page.isClosed()) throw new Error('กดเปิดบัญชีก่อน');
    if (d.action === 'account') return { account: await collector.account(), message: 'ยืนยันบัญชีแล้ว แต่ยังไม่ได้ตรวจสิทธิ์อ่านคำสั่งซื้อ' };
    if (d.action === 'check-access') {
      const result = await collector.checkAccess(); error = ''; return result;
    }
    if (d.action === 'login') { await page.goto('https://shopee.co.th/buyer/login', { waitUntil: 'domcontentloaded' }); return {}; }
    if (['sync', 'resume', 'repair'].includes(d.action)) {
      const issue = accessIssue(collector.status(), error);
      if (issue) throw new Error(issue.message);
      error = ''; launchJob(d.action); return {};
    }
    if (d.action === 'purchase') { await page.goto('https://shopee.co.th/user/purchase/', { waitUntil: 'domcontentloaded' }); return {}; }
    if (d.action === 'drag') {
      if (![d.x,d.y,d.endX,d.endY].every(Number.isFinite) || [d.x,d.endX].some(v => v<0 || v>1280) || [d.y,d.endY].some(v => v<0 || v>900)) throw new Error('Invalid coordinates');
      await page.mouse.move(d.x,d.y); await page.mouse.down();
      try { await page.mouse.move(d.endX,d.endY,{steps:15}); } finally { await page.mouse.up(); }
    } else if (d.action === 'click') {
      if (![d.x,d.y].every(Number.isFinite) || d.x < 0 || d.x > 1280 || d.y < 0 || d.y > 900) throw new Error('Invalid coordinates');
      await page.mouse.click(d.x, d.y);
    } else if (d.action === 'text') {
      if (typeof d.text !== 'string' || d.text.length > 2000) throw new Error('Invalid text');
      await page.keyboard.insertText(d.text);
    } else if (d.action === 'key') {
      if (!['Enter','Tab','Shift+Tab','Backspace','Escape','ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Control+A','Delete'].includes(d.key)) throw new Error('Invalid key');
      await page.keyboard.press(d.key);
    } else if (d.action === 'scroll') {
      if (!Number.isFinite(d.y) || Math.abs(d.y) > 1500) throw new Error('Invalid scroll');
      await page.mouse.wheel(0, d.y);
    } else throw new Error('Unknown action');
    return {};
  } finally { busy = false; }
}
function authorized(value) {
  const given = Buffer.from(value || ''), expected = Buffer.from(`Bearer ${token}`);
  return given.length === expected.length && timingSafeEqual(given, expected);
}
const server = http.createServer(async (req, res) => {
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  const json = (code, value) => { res.writeHead(code); res.end(JSON.stringify(value)); };
  if (!authorized(req.headers.authorization)) return json(401, { ok: false, error: 'Unauthorized' });
  try {
    if (req.method === 'GET' && req.url === '/status') {
      const state = collector?.status() || lastState;
      return json(200, { ok: true, profile: active, lastProfile, busy: busy || !!job, error, state, accessIssue: accessIssue(state, error) });
    }
    if (req.method === 'GET' && req.url === '/screen') {
      if (!page || page.isClosed()) throw new Error('ยังไม่ได้เปิดบัญชี');
      const screen = await page.screenshot({ type: 'jpeg', quality: 70, timeout: 10000 });
      res.setHeader('Content-Type', 'image/jpeg'); res.end(screen); return;
    }
    if (req.method !== 'POST' || req.url !== '/command') return json(404, { ok: false, error: 'Not found' });
    let body = '';
    for await (const chunk of req) { body += chunk; if (Buffer.byteLength(body) > 16384) return json(413, { ok: false, error: 'Request too large' }); }
    return json(200, { ok: true, ...await command(JSON.parse(body)) });
  } catch (e) { return json(400, { ok: false, error: String(e.message).slice(0,500) }); }
});
server.requestTimeout = 60000;
server.listen(port, '127.0.0.1', () => console.log(`PAN Shopee connector: 127.0.0.1:${port}`));
const minutes = Number(process.env.PAN_SYNC_MINUTES || 0);
let scheduled = false;
if (minutes >= 15) setInterval(async () => {
  if (busy || job || scheduled || active) return; // Do not take over an interactive login.
  scheduled = true; busy = true;
  try {
    const name = profileName(process.env.PAN_SYNC_PROFILE || 'default');
    let saved = {};
    try { saved = JSON.parse(await readFile(path.join(root,name,'collector.json'),'utf8')); }
    catch (e) { if (e.code !== 'ENOENT') throw e; }
    const state = saved.syncStates?.[saved.lastAccountId] || {};
    if (accessIssue(state, error)) { lastState = state; lastProfile = name; return; }
    await open(name); launchJob('sync'); await job; await close();
  }
  catch (e) { error = e.message; }
  finally { busy = false; scheduled = false; }
}, minutes * 60000).unref();
async function shutdown() {
  server.close();
  pauseRequested = true;
  await collector?.pause();
  if (job) await job;
  await close(); await saveQueue; process.exit(0);
}
process.on('SIGTERM', shutdown); process.on('SIGINT', shutdown);
