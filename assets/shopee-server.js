const $ = id => document.getElementById(id);
let active = false, polling = false, lastImage = '', pending = Promise.resolve();
async function request(action, data = {}) {
  const r = await fetch('./api/shopee-server.php', { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: new URLSearchParams({ csrf_token: window.panShopeeCsrf, action, ...data }) });
  if (action === 'screen' && r.ok && r.headers.get('content-type')?.startsWith('image/')) return r.blob();
  let j; try { j = await r.json(); } catch { throw new Error('เซสชัน PAN หมดอายุหรือเซิร์ฟเวอร์ตอบกลับผิดรูปแบบ กรุณาโหลดหน้าใหม่'); }
  if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
  return j;
}
function command(action, data = {}) {
  pending = pending.then(async () => {
    $('message').textContent = '';
    const j = await request(action, data);
    if (j.account) $('account').textContent = `บัญชี: ${j.account.username || j.account.nickname || ''} · ID ${j.account.userid}`;
    if (j.message) $('message').textContent = j.message;
    if (action === 'open' || action === 'close') $('account').textContent = '';
    if (!['click','drag','text','key','scroll'].includes(action)) await refresh();
  }).catch(e => { $('message').textContent = e.message; });
  return pending;
}
async function refresh() {
  if (polling) return;
  polling = true;
  try {
    const j = await request('status'), s = j.state || {};
    active = !!j.profile;
    $('status').textContent = `บริการพร้อม · โปรไฟล์: ${j.profile || 'ยังไม่เปิด'}${j.busy ? ' · กำลังทำงาน' : ''}\n${s.status || ''} · ${s.orders || 0} คำสั่งซื้อ · ${s.items || 0} รายการ${s.error || j.error ? '\n' + (j.error || s.error) : ''}`;
    $('access-issue').textContent = j.accessIssue?.message || '';
    $('access-issue').hidden = !j.accessIssue;
    document.querySelectorAll('[data-action]').forEach(b => { b.disabled = (j.busy && b.dataset.action !== 'pause') || (!!j.accessIssue && ['sync','resume','repair'].includes(b.dataset.action)); });
    $('screen').hidden = !active;
    $('screen-note').hidden = active;
    if (active && !document.hidden) {
      const blob = await request('screen'), url = URL.createObjectURL(blob);
      $('screen').src = url;
      if (lastImage) URL.revokeObjectURL(lastImage);
      lastImage = url;
    }
  } catch(e) { $('message').textContent = e.message; }
  finally { polling = false; }
}
document.querySelectorAll('[data-action]').forEach(b => b.onclick = () => command(b.dataset.action, b.dataset.action === 'open' ? { profile: $('profile').value.trim() } : {}));
document.querySelectorAll('[data-key]').forEach(b => b.onclick = () => command('key', { key: b.dataset.key }));
document.querySelectorAll('[data-scroll]').forEach(b => b.onclick = () => command('scroll', { y: b.dataset.scroll }));
$('send').onclick = () => { const text = $('text').value; $('text').value = ''; if (text) command('text', { text }); };
$('text').onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); $('send').click(); } };
let pointerStart;
function point(e) { const r=$('screen').getBoundingClientRect(); return {x:Math.max(0,Math.min(1280,(e.clientX-r.left)*1280/r.width)),y:Math.max(0,Math.min(900,(e.clientY-r.top)*900/r.height))}; }
$('screen').onpointerdown = e => { pointerStart=point(e); e.currentTarget.setPointerCapture(e.pointerId); e.currentTarget.focus(); };
$('screen').onpointerup = e => {
  if (!pointerStart) return;
  const start=pointerStart, end=point(e); pointerStart=null;
  if (Math.hypot(end.x-start.x,end.y-start.y)>5) command('drag',{...start,endX:end.x,endY:end.y});
  else command('click',start);
};
$('screen').onpointercancel = () => { pointerStart=null; };
$('screen').onkeydown = e => {
  if (e.isComposing) return;
  const keys = ['Enter','Tab','Backspace','Escape','ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Delete'];
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') { e.preventDefault(); command('key', { key: 'Control+A' }); }
  else if (keys.includes(e.key)) { e.preventDefault(); command('key', { key: e.shiftKey && e.key === 'Tab' ? 'Shift+Tab' : e.key }); }
  else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); command('text', { text: e.key }); }
};
refresh(); setInterval(refresh, 2000);
