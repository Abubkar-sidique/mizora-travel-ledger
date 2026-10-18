import { spawn } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';

const [baseUrl, chromePath, profilePath] = process.argv.slice(2);
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
  await evaluate(`(() => { const form = document.querySelector('form'); form.elements.username.value = 'audit.owner1'; form.elements.password.value = 'Audit-Only-Owner-Password-731'; form.requestSubmit(); return true; })()`);
  for (let attempt = 0; attempt < 100; attempt++) {
    if (await evaluate("location.search.includes('p=home') && document.readyState === 'complete'")) break;
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  assert(await evaluate("document.body.innerText.includes('Dashboard')"), 'Browser login reaches the owner dashboard');

  const routes = ['?p=home', '?p=accounts', '?p=reports', '?p=profile'];
  for (const route of routes) {
    await navigate(route);
    const result = await evaluate('({width: innerWidth, document: document.documentElement.scrollWidth, body: document.body.scrollWidth, title: document.title})');
    assert(result.document <= result.width + 2 && result.body <= result.width + 2, `No horizontal overflow at 390px on ${route}: ${JSON.stringify(result)}`);
  }
  await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
  for (const route of routes) {
    await navigate(route);
    const result = await evaluate('({width: innerWidth, document: document.documentElement.scrollWidth, body: document.body.scrollWidth})');
    assert(result.document <= result.width + 2 && result.body <= result.width + 2, `No horizontal overflow at 1440px on ${route}: ${JSON.stringify(result)}`);
  }
  console.log('PASS browser smoke: Chrome login and four owner pages at 390px and 1440px without horizontal overflow.');
} finally {
  try { socket?.close(); } catch {}
  if (browser.exitCode === null) browser.kill();
  await new Promise(resolve => setTimeout(resolve, 250));
}
