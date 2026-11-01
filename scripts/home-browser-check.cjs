// Homepage-only browser QA. Public GETs only; no auth, fixtures, migrations, or DB writes.
// A private PHP process uses array sessions/cache so public GETs cannot create DB-backed guest sessions.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn } = require('node:child_process');

const root = fs.realpathSync(path.resolve(__dirname, '..'));
const privateRoot = path.join(root, 'storage', 'app', 'private');
const output = path.join(root, 'docs', 'preview');
const runId = crypto.randomBytes(8).toString('hex');
const profile = path.join(privateRoot, `home-browser-profile-${runId}`);
const origin = 'http://127.0.0.1:8006';
const debuggerOrigin = 'http://127.0.0.1:9232';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const exited = child => child.exitCode !== null || child.signalCode !== null;
const report = { publicGetOnly: true, authenticationUsed: false, fixturesCreated: false, databaseWritesRequested: false, sessionDriver: 'array', cacheStore: 'array', ports: { application: 8006, browser: 9232 }, checks: [] };

function inside(target, parent = root) {
  const relative = path.relative(fs.realpathSync(parent), path.resolve(target));
  if (!relative || relative.startsWith('..') || path.isAbsolute(relative)) throw new Error('QA path escaped its intended directory.');
  if (fs.existsSync(target)) {
    const actual = path.relative(fs.realpathSync(parent), fs.realpathSync(target));
    if (!actual || actual.startsWith('..') || path.isAbsolute(actual)) throw new Error('QA path resolves outside its intended directory.');
  }
}
async function freePort(port) {
  await new Promise((resolve, reject) => {
    const listener = net.createServer();
    listener.once('error', () => reject(new Error(`Port ${port} is already occupied; existing processes were left untouched.`)));
    listener.listen(port, '127.0.0.1', () => listener.close(resolve));
  });
}
async function ready(url, child) {
  const end = Date.now() + 20000;
  while (Date.now() < end) {
    if (exited(child)) throw new Error('An owned preview process exited during startup.');
    try { if ((await fetch(url, { signal: AbortSignal.timeout(1000) })).ok) return; } catch {}
    await sleep(150);
  }
  throw new Error('An owned preview process did not become ready within20s.');
}
async function stop(child) {
  if (!child || exited(child)) return;
  child.kill();
  for (let attempt = 0; attempt < 60 && !exited(child); attempt++) await sleep(100);
  if (!exited(child)) throw new Error('An owned preview process did not exit; its profile was retained.');
}

(async () => {
  let server, browser, socket;
  let createdProfile = false, failure;
  try {
    fs.mkdirSync(privateRoot, { recursive: true });
    fs.mkdirSync(output, { recursive: true });
    inside(profile, privateRoot);
    inside(output);
    if (fs.existsSync(profile)) throw new Error('The new homepage QA profile already exists.');
    if (fs.existsSync(path.join(root, 'bootstrap', 'cache', 'config.php'))) throw new Error('Uncached configuration is required for array-only QA sessions/cache. No live cache was changed.');
    await freePort(8006);
    await freePort(9232);
    const chrome = [process.env.CHROME_PATH, 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'].find(candidate => candidate && fs.existsSync(candidate));
    if (!chrome) throw new Error('Installed Chrome was not found; no browser was installed.');
    const environment = { ...process.env, APP_URL: origin, APP_DEBUG: 'false', SESSION_DRIVER: 'array', CACHE_STORE: 'array', LOG_CHANNEL: 'stderr', MAIL_MAILER: 'array', SESSION_COOKIE: `analista_home_qa_${runId}`, SESSION_DOMAIN: 'null', SESSION_SECURE_COOKIE: 'false' };
    server = spawn(process.env.PHP_BINARY ?? 'php', ['-S', '127.0.0.1:8006', '-t', 'public', 'dev-server.php'], { cwd: root, env: environment, windowsHide: true, stdio: 'ignore' });
    await ready(`${origin}/assets/favicon.svg`, server);
    fs.mkdirSync(profile);
    createdProfile = true;
    browser = spawn(chrome, ['--headless=new', '--remote-debugging-port=9232', '--remote-debugging-address=127.0.0.1', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-sync', '--disable-extensions', 'about:blank'], { cwd: root, windowsHide: true, stdio: 'ignore' });
    await ready(`${debuggerOrigin}/json/version`, browser);
    console.log('QA: Owned public-only preview and fresh Chrome profile ready');
    const targets = await (await fetch(`${debuggerOrigin}/json/list`)).json();
    const target = targets.find(item => item.type === 'page');
    if (!target) throw new Error('Owned Chrome has no page target.');
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', () => reject(new Error('Could not connect to owned Chrome.')), { once: true }); });
    let sequence = 0;
    const pending = new Map();
    const runtimeErrors = [];
    const rejectedMethods = [];
    const httpErrors = [];
    socket.addEventListener('message', ({ data }) => {
      const packet = JSON.parse(data);
      if (packet.id && pending.has(packet.id)) {
        const callback = pending.get(packet.id); pending.delete(packet.id);
        packet.error ? callback.reject(new Error(packet.error.message)) : callback.resolve(packet.result);
      }
      if (packet.method === 'Runtime.exceptionThrown') runtimeErrors.push(packet.params.exceptionDetails.text);
      if (packet.method === 'Network.responseReceived' && packet.params.response.status >= 400) httpErrors.push({ path: new URL(packet.params.response.url).pathname, status: packet.params.response.status });
      if (packet.method === 'Fetch.requestPaused') {
        if (packet.params.request.method !== 'GET') {
          rejectedMethods.push(packet.params.request.method);
          send('Fetch.failRequest', { requestId: packet.params.requestId, errorReason: 'BlockedByClient' }).catch(() => {});
        } else send('Fetch.continueRequest', { requestId: packet.params.requestId }).catch(() => {});
      }
    });
    function send(method, params = {}) {
      return new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`CDP command timed out: ${method}`)); }, method === 'Page.navigate' ? 30000 : 10000);
        pending.set(id, { resolve: value => { clearTimeout(timeout); resolve(value); }, reject: error => { clearTimeout(timeout); reject(error); } });
        socket.send(JSON.stringify({ id, method, params }));
      });
    }
    async function evaluate(expression) {
      const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
      if (result.exceptionDetails) throw new Error('Homepage browser evaluation failed.');
      return result.result.value;
    }
    async function wait(expression, label) {
      const end = Date.now() + 18000;
      while (Date.now() < end) { try { if (await evaluate(expression)) return; } catch {} await sleep(125); }
      throw new Error(`Timed out waiting for ${label}.`);
    }
    async function screenshot(name) {
      const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      const target = path.join(output, name); inside(target);
      fs.writeFileSync(target, Buffer.from(result.data, 'base64'));
    }
    async function viewport(width) {
      await send('Emulation.setDeviceMetricsOverride', { width, height: width === 390 ? 844 : 1080, deviceScaleFactor: 1, mobile: width === 390 });
      await sleep(300);
    }
    async function inspect(label, width) {
      const result = await evaluate(`(() => { const main=document.querySelector('#main');const heading=main?.querySelector('h1');const content=main?.querySelector('.home-intro')||main?.querySelector('[data-home-content],.home-content,.home-hero,.hero')||main;const rect=content?.getBoundingClientRect();const sidebar=document.querySelector('.sidebar')?.getBoundingClientRect();return {width:innerWidth,scroll:document.documentElement.scrollWidth,bodyClass:document.body.className,heading:heading?.innerText,headingSize:heading?getComputedStyle(heading).fontSize:null,contentSelector:content?.className,content:rect?{left:rect.left,width:rect.width,right:rect.right}:null,sidebar:sidebar?{left:sidebar.left,right:sidebar.right,width:sidebar.width}:null,topbarPresent:!!document.querySelector('.topbar'),mobileHeaderVisible:getComputedStyle(document.querySelector('.mobile-header')).display!=='none'};})()`);
      if (result.width !== width || result.scroll > width + 1 || !result.heading || result.topbarPresent) throw new Error(`${label}: incorrect layout ${JSON.stringify(result)}`);
      if (width === 1920) {
        const center = result.content.left + result.content.width / 2;
        const availableCenter = result.sidebar.right + (width - result.sidebar.right) / 2;
        if (result.content.width < 630 || result.content.width > 660 || Math.abs(center - availableCenter) > 12 || Math.abs(parseFloat(result.headingSize) - 40) > 1) {
          throw new Error(`${label}: reference width/type/centering mismatch ${JSON.stringify(result)}`);
        }
      }
      if (width === 390 && (!result.mobileHeaderVisible || result.sidebar.right > 1)) throw new Error(`${label}: mobile navigation overlaps content.`);
      report.checks.push({ label, ...result, horizontalOverflow: false });
    }
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    await send('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });
    await viewport(1920);
    await send('Page.navigate', { url: origin + '/' });
    await wait(`location.pathname==='/' && document.readyState==='complete' && !!document.querySelector('#main h1')`, 'public homepage');
    await evaluate('document.fonts.ready.then(() => true)');
    await inspect('Desktop light', 1920);
    report.coreLinks = await evaluate(`Array.from(document.querySelectorAll('#main a[href]')).map(a=>({text:a.textContent.trim(),path:new URL(a.href).pathname,query:new URL(a.href).search})).filter(link=>['/evaluators','/projects','/tutorials','/register'].includes(link.path))`);
    for (const required of ['/evaluators', '/projects', '/tutorials']) if (!report.coreLinks.some(link => link.path === required)) throw new Error(`Homepage core link missing: ${required}`);
    await screenshot('home-tight-desktop.png');
    await evaluate(`document.querySelector('[data-theme-preference=dark]').click()`);
    await wait(`document.documentElement.dataset.theme==='dark'`, 'desktop dark theme');
    await inspect('Desktop dark', 1920);
    await screenshot('home-tight-desktop-dark.png');
    await viewport(390);
    await evaluate(`scrollTo(0,0)`);
    await inspect('Mobile dark', 390);
    await screenshot('home-tight-mobile-dark.png');
    await evaluate(`document.querySelector('[data-theme-preference=light]').click()`);
    await wait(`document.documentElement.dataset.theme==='light'`, 'mobile light theme');
    await inspect('Mobile light', 390);
    await screenshot('home-tight-mobile.png');
    await evaluate(`document.querySelector('[data-menu]').click()`);
    await wait(`document.body.classList.contains('menu-open')`, 'mobile sidebar menu');
    await evaluate(`document.querySelector('[data-overlay]').click()`);
    await wait(`!document.body.classList.contains('menu-open')`, 'mobile sidebar close');
    report.mobileMenu = 'opens and closes';
    report.runtimeErrors = runtimeErrors;
    report.httpErrors = httpErrors;
    report.rejectedMethods = rejectedMethods;
    if (runtimeErrors.length || httpErrors.length || rejectedMethods.length) throw new Error('Browser reported runtime/network errors or an unexpected write request.');
    report.passed = true;
    console.log(JSON.stringify({ passed: true, layouts: report.checks.length, noTopbar: true, publicGetOnly: true, databaseWritesRequested: false }));
    await send('Browser.close').catch(() => {});
  } catch (error) {
    failure = error;
    report.passed = false;
    report.failure = error.message;
  } finally {
    socket?.close();
    let cleanupFailure;
    for (const child of [browser, server]) { try { await stop(child); } catch (error) { cleanupFailure ??= error; } }
    if (!cleanupFailure && createdProfile) {
      try {
        inside(profile, privateRoot);
        if (fs.lstatSync(profile).isSymbolicLink() || !/^home-browser-profile-[a-f0-9]{16}$/.test(path.basename(profile))) throw new Error('Owned profile changed unexpectedly; recursive cleanup was refused.');
        fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
      } catch (error) { cleanupFailure = error; }
    }
    report.cleanup = cleanupFailure ? { completed: false, reason: cleanupFailure.message } : { completed: true };
    const reportPath = path.join(output, 'home-tight-browser-results.json'); inside(reportPath);
    fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
    if (failure || cleanupFailure) throw failure ?? cleanupFailure;
  }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
