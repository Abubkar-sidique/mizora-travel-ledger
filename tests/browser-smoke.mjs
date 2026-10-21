import { spawn } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';

const [baseUrl, chromePath, profilePath] = process.argv.slice(2);
const mode = process.argv[5] === 'master' ? 'master' : 'owner';
const masterPassword = process.argv[6] || 'Audit-Only-Strong-Password-731';
if (!baseUrl || !chromePath || !profilePath) throw new Error('Usage: node browser-smoke.mjs BASE_URL CHROME_PATH PROFILE_PATH');
await mkdir(profilePath, { recursive: false });
const browser = spawn(chromePath, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  '--disable-background-networking', '--remote-debugging-port=0', '--remote-debugging-address=127.0.0.1', '--remote-allow-origins=*',
  `--user-data-dir=${profilePath}`, 'about:blank'
], { stdio: 'ignore', windowsHide: true });

let socket;
try {
  const activePortFile = path.join(profilePath, 'DevToolsActivePort');
  let activePort;
  for (let attempt = 0; attempt < 100; attempt++) {
    try { activePort = (await readFile(activePortFile, 'utf8')).trim().split(/\r?\n/); break; }
    catch { if (browser.exitCode !== null) throw new Error(`Chrome exited with ${browser.exitCode}`); await new Promise(resolve => setTimeout(resolve, 100)); }
  }
  if (!activePort?.[0]) throw new Error('Chrome did not publish its DevTools port');
  const debuggerBase = `http://127.0.0.1:${activePort[0]}`;
  const response = await fetch(`${debuggerBase}/json/new?about:blank`, { method: 'PUT' });
  if (!response.ok) throw new Error(`Chrome target creation failed: ${response.status}`);
  const target = await response.json();
  socket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });

  let nextId = 0;
  const pending = new Map();
  socket.addEventListener('message', async event => {
    const packet = JSON.parse(typeof event.data === 'string' ? event.data : await event.data.text());
    if (!packet.id) return;
    const waiter = pending.get(packet.id);
    if (!waiter) return;
    pending.delete(packet.id);
    if (packet.error) waiter.reject(new Error(packet.error.message));
    else waiter.resolve(packet.result);
  });
  const command = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++nextId;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
    setTimeout(() => { if (pending.has(id)) { pending.delete(id); reject(new Error(`Timed out: ${method}`)); } }, 10000).unref();
  });
  const evaluate = async expression => {
    const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text || 'Browser JavaScript exception');
    return result.result.value;
  };
  const navigate = async route => {
    const expectedUrl = new URL(route, baseUrl).href;
    await command('Page.navigate', { url: expectedUrl });
    for (let attempt = 0; attempt < 100; attempt++) {
      if (await evaluate(`location.href === ${JSON.stringify(expectedUrl)} && document.readyState === 'complete'`)) return;
      await new Promise(resolve => setTimeout(resolve, 50));
    }
    throw new Error(`Page did not finish loading: ${route}`);
  };
  const assert = (condition, message) => { if (!condition) throw new Error(`FAIL: ${message}`); };

  await command('Page.enable');
  await command('Runtime.enable');
  await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await navigate('?p=login');
  assert((await evaluate("document.body.innerText.includes('Welcome Back')")), 'Login page renders in a mobile viewport');
  for (const width of [320, 360, 430]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height: 740, deviceScaleFactor: 1, mobile: true });
    await navigate('?p=login');
    const auth = await evaluate('({width:innerWidth,document:document.documentElement.scrollWidth,body:document.body.scrollWidth,form:!!document.querySelector("form input[type=password]")})');
    assert(auth.form && auth.document <= width + 2 && auth.body <= width + 2, `Login is usable without horizontal overflow at ${width}px: ${JSON.stringify(auth)}`);
  }
  await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await navigate('?p=login');
  await evaluate(`(() => { const form = document.querySelector('form'); form.elements.username.value = ${JSON.stringify(mode === 'master' ? 'audit.master' : 'audit.owner1')}; form.elements.password.value = ${JSON.stringify(mode === 'master' ? masterPassword : 'Audit-Only-Owner-Password-731')}; form.requestSubmit(); return true; })()`);
  for (let attempt = 0; attempt < 100; attempt++) {
    if (await evaluate(`location.search.includes('p=${mode === 'master' ? 'agencies' : 'home'}') && document.readyState === 'complete'`)) break;
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  assert(await evaluate(`document.body.innerText.includes(${JSON.stringify(mode === 'master' ? 'Travel Agencies' : 'Dashboard')})`), `Browser login reaches the ${mode} landing page`);

  let accountRoute = '';
  if (mode === 'owner') {
    await navigate('?p=accounts');
    accountRoute = await evaluate(`(() => { const a = [...document.querySelectorAll('a[href*="p=acc&id="]')].find(x => x.getClientRects().length); return a?.getAttribute('href') || ''; })()`);
    assert(accountRoute, 'Owner ledger listing provides a usable account ledger link');
  }
  const ownerRoutes = ['?p=home', '?p=accounts', accountRoute, '?p=reports', '?p=profile'];
  const masterRoutes = ['?p=agencies', '?p=backup', '?p=profile'];
  const routes = mode === 'master' ? masterRoutes : ownerRoutes;
  const checkRoute = async (route, width, mobile) => {
    await command('Emulation.setDeviceMetricsOverride', { width, height: mobile ? 740 : 900, deviceScaleFactor: 1, mobile });
    await navigate(route);
    const result = await evaluate(`(() => ({width: innerWidth, document: document.documentElement.scrollWidth, body: document.body.scrollWidth, title: document.title, tables: [...document.querySelectorAll('.listing-scroll')].map(x => ({client:x.clientWidth, scroll:x.scrollWidth, overflow:getComputedStyle(x).overflowX, rows:x.querySelectorAll('tbody tr').length, cells:[...x.querySelectorAll('tbody tr:first-child td')].map(c=>({display:getComputedStyle(c).display,text:c.innerText.trim()}))})), controls:[...document.querySelectorAll('.page-head button,.page-head a,.workspace-actions button,.workspace-actions a')].filter(x=>x.getClientRects().length).map(x=>{const r=x.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom}}),formFields:[...document.querySelectorAll('#new-account input:not([type="hidden"]),#new-account select:not(.dd-native),#new-account .dd-btn')].filter(x=>x.getClientRects().length).map(x=>{const r=x.getBoundingClientRect();return {left:r.left,right:r.right,width:r.width}})}))()`);
    assert(result.document <= result.width + 2 && result.body <= result.width + 2, `No page overflow at ${width}px on ${route}: ${JSON.stringify(result)}`);
    for (const table of result.tables) {
      assert(table.rows > 0, `Table rows remain rendered on ${route} at ${width}px`);
      if (table.scroll > table.client + 2) assert(['auto','scroll'].includes(table.overflow), `Wide table has its own horizontal scroll on ${route} at ${width}px`);
      assert(table.cells.filter(cell => cell.text).every(cell => cell.display !== 'none'), `No populated transaction cells are hidden on ${route} at ${width}px`);
    }
    for (const control of result.controls) assert(control.left >= -2 && control.right <= width + 2, `Page actions stay within ${width}px viewport on ${route}: ${JSON.stringify(control)}`);
    for (const field of result.formFields) assert(field.width >= 80 && field.left >= -2 && field.right <= width + 2, `New account fields remain usable and in view at ${width}px: ${JSON.stringify(field)}`);
    return result;
  };
  let mobileChecks = 0;
  for (const width of [320, 360, 390, 430]) for (const route of routes) { await checkRoute(route, width, true); mobileChecks++; }

  // Exercise the transaction form drawer at the narrowest width; it must fit
  // the viewport and remain internally scrollable rather than cutting fields.
  if (mode === 'owner') {
    await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 600, deviceScaleFactor: 1, mobile: true });
    await navigate(accountRoute);
    await evaluate(`document.querySelector('[data-open-drawer="dr"]')?.click()`);
    const drawer = await evaluate(`(() => {const d=document.querySelector('.form-drawer');const r=d.getBoundingClientRect();return {open:d.open,left:r.left,right:r.right,width:innerWidth,scroll:d.scrollHeight,client:d.clientHeight,fields:[...d.querySelectorAll('input:not([type="hidden"]):not([type="radio"]),select:not(.dd-native),textarea,.dd-btn')].filter(x=>x.getClientRects().length).map(x=>{const f=x.getBoundingClientRect();return {left:f.left,right:f.right,width:f.width}})}})()`);
    assert(drawer.open && drawer.left >= -1 && drawer.right <= drawer.width + 1, `Transaction form fits on 320px: ${JSON.stringify(drawer)}`);
    assert(drawer.fields.length >= 5 && drawer.scroll >= drawer.client, `Transaction form fields remain reachable in the drawer: ${JSON.stringify(drawer)}`);
    for (const field of drawer.fields) assert(field.width >= 80 && field.left >= -2 && field.right <= 322, `Debit/credit form field fits on 320px: ${JSON.stringify(field)}`);
  }
  for (const route of routes) await checkRoute(route, 1440, false);
  console.log(`PASS browser smoke (${mode}): login at 320/360/390/430px; ${mobileChecks} mobile pages across 320/360/390/430px; desktop pages at 1440px; table scroll, visible cells, actions${mode === 'owner' ? ' and transaction drawer' : ''} checked.`);
} finally {
  try { socket?.close(); } catch {}
  if (browser.exitCode === null) browser.kill();
  await new Promise(resolve => setTimeout(resolve, 250));
}
