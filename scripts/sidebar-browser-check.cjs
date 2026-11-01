// Shared sidebar QA: public GETs only, array sessions/cache, no fixtures or runtime DB writes.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn } = require('node:child_process');

const root = fs.realpathSync(path.resolve(__dirname, '..'));
const privateRoot = path.join(root, 'storage', 'app', 'private');
const output = path.join(root, 'docs', 'preview');
const runId = crypto.randomBytes(8).toString('hex');
const profile = path.join(privateRoot, `sidebar-browser-profile-${runId}`);
const origin = 'http://127.0.0.1:8010';
const debug = 'http://127.0.0.1:9236';
const routes = ['/', '/evaluators', '/projects', '/tutorials', '/guidelines', '/login', '/register'];
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const exited = child => child.exitCode !== null || child.signalCode !== null;
const report = { publicGetOnly: true, authenticationUsed: false, fixturesCreated: false, databaseWritesRequested: false, sessionDriver: 'array', cacheStore: 'array', ports: { application: 8010, browser: 9236 }, checks: [], runtimeErrors: [], httpErrors: [], rejectedMethods: [] };

function inside(target, parent = root) {
  const relative = path.relative(fs.realpathSync(parent), path.resolve(target));
  if (!relative || relative.startsWith('..') || path.isAbsolute(relative)) throw new Error('QA target escaped its intended directory.');
  if (fs.existsSync(target)) {
    const actual = path.relative(fs.realpathSync(parent), fs.realpathSync(target));
    if (!actual || actual.startsWith('..') || path.isAbsolute(actual) || fs.lstatSync(target).isSymbolicLink()) throw new Error('QA target resolves outside its directory or is a symbolic link.');
  }
}
async function freePort(port) {
  await new Promise((resolve, reject) => {
    const listener = net.createServer();
    listener.once('error', () => reject(new Error(`Port ${port} is occupied; existing processes were left untouched.`)));
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
  throw new Error('An owned preview process did not become ready within 20 seconds.');
}
async function stop(child) {
  if (!child || exited(child)) return;
  child.kill();
  for (let i = 0; i < 60 && !exited(child); i++) await sleep(100);
  if (!exited(child)) throw new Error('An owned preview process did not exit; its private profile was retained.');
}

(async () => {
  let server, browser, socket, failure;
  let createdProfile = false;
  try {
    fs.mkdirSync(privateRoot, { recursive: true });
    fs.mkdirSync(output, { recursive: true });
    inside(profile, privateRoot); inside(output);
    if (fs.existsSync(profile)) throw new Error('The new QA profile already exists.');
    if (fs.existsSync(path.join(root, 'bootstrap', 'cache', 'config.php'))) throw new Error('Cached configuration prevents guaranteed array sessions/cache; no live cache was changed.');
    await freePort(8010); await freePort(9236);
    const chrome = [process.env.CHROME_PATH, 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'].find(candidate => candidate && fs.existsSync(candidate));
    if (!chrome) throw new Error('Installed Chrome was not found; no browser was installed.');
    const env = { ...process.env, APP_URL: origin, APP_DEBUG: 'false', SESSION_DRIVER: 'array', CACHE_STORE: 'array', LOG_CHANNEL: 'stderr', MAIL_MAILER: 'array', SESSION_COOKIE: `analista_sidebar_qa_${runId}`, SESSION_DOMAIN: 'null', SESSION_SECURE_COOKIE: 'false' };
    server = spawn(process.env.PHP_BINARY ?? 'php', ['-S', '127.0.0.1:8010', '-t', 'public', 'dev-server.php'], { cwd: root, env, windowsHide: true, stdio: 'ignore' });
    await ready(`${origin}/assets/favicon.svg`, server);
    fs.mkdirSync(profile); createdProfile = true;
    browser = spawn(chrome, ['--headless=new', '--remote-debugging-port=9236', '--remote-debugging-address=127.0.0.1', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-sync', '--disable-extensions', 'about:blank'], { cwd: root, windowsHide: true, stdio: 'ignore' });
    await ready(`${debug}/json/version`, browser);
    console.log('QA: Owned GET-only preview and fresh Chrome ready');
    const targets = await (await fetch(`${debug}/json/list`)).json();
    const target = targets.find(item => item.type === 'page');
    if (!target) throw new Error('Owned Chrome has no page target.');
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', () => reject(new Error('Could not connect to owned Chrome.')), { once: true }); });
    let sequence = 0;
    const pending = new Map();
    socket.addEventListener('message', ({ data }) => {
      const packet = JSON.parse(data);
      if (packet.id && pending.has(packet.id)) {
        const callback = pending.get(packet.id); pending.delete(packet.id);
        packet.error ? callback.reject(new Error(packet.error.message)) : callback.resolve(packet.result);
      }
      if (packet.method === 'Runtime.exceptionThrown') report.runtimeErrors.push(packet.params.exceptionDetails.text);
      if (packet.method === 'Network.responseReceived' && packet.params.response.status >= 400) report.httpErrors.push({ path: new URL(packet.params.response.url).pathname, status: packet.params.response.status });
      if (packet.method === 'Fetch.requestPaused') {
        const request = packet.params.request;
        if (request.method !== 'GET' || new URL(request.url).origin !== origin) {
          report.rejectedMethods.push({ method: request.method, external: new URL(request.url).origin !== origin });
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
      if (result.exceptionDetails) throw new Error('Sidebar browser evaluation failed.');
      return result.result.value;
    }
    async function wait(expression, label) {
      const end = Date.now() + 18000;
      while (Date.now() < end) { try { if (await evaluate(expression)) return; } catch {} await sleep(125); }
      throw new Error(`Timed out waiting for ${label}.`);
    }
    async function navigate(route) {
      await send('Page.navigate', { url: origin + route });
      await wait(`location.pathname===${JSON.stringify(route)} && document.readyState==='complete' && !!document.querySelector('#main')`, route);
      await evaluate('document.fonts.ready.then(() => true)');
    }
    async function screenshot(name) {
      const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      const target = path.join(output, name); inside(target);
      fs.writeFileSync(target, Buffer.from(result.data, 'base64'));
    }
    async function viewport(width) {
      await send('Emulation.setDeviceMetricsOverride', { width, height: width === 390 ? 844 : 1080, deviceScaleFactor: 1, mobile: width === 390 });
      await sleep(200);
    }
    async function inspect(route, width, theme, menuOpen = false) {
      const state = await evaluate(`(() => {
        const box = node => { const rect=node.getBoundingClientRect(); return {left:rect.left,top:rect.top,width:rect.width,height:rect.height,right:rect.right,bottom:rect.bottom}; };
        const styles = (node, keys) => { const computed=getComputedStyle(node); return Object.fromEntries(keys.map(key=>[key,computed[key]])); };
        const sidebar=document.querySelector('.sidebar'); const brand=sidebar.querySelector('.brand');const home=Array.from(sidebar.querySelectorAll('.nav-link')).find(a=>new URL(a.href).pathname==='/');
        return {path:location.pathname,width:innerWidth,scrollWidth:document.documentElement.scrollWidth,theme:document.documentElement.dataset.theme||'light',menuOpen:document.body.classList.contains('menu-open'),sidebar:box(sidebar),sidebarStyle:styles(sidebar,['paddingTop','paddingRight','paddingBottom','paddingLeft','fontFamily']),brand:styles(brand,['fontSize','gap']),brandIcon:box(brand.querySelector('.icon')),home:box(home),homeStyle:styles(home,['fontFamily','fontSize','lineHeight','minHeight','paddingTop','paddingRight','paddingBottom','paddingLeft','gap']),homeIcon:box(home.querySelector('.icon')),navRows:Array.from(sidebar.querySelectorAll('.nav-link')).map(a=>({text:a.textContent.trim(),...box(a)})),mobileHeaderVisible:getComputedStyle(document.querySelector('.mobile-header')).display!=='none',mobileExpanded:document.querySelector('[data-menu]').getAttribute('aria-expanded'),topbarPresent:!!document.querySelector('.topbar'),sharedSheet:!!document.querySelector('link[href*="app.css"]')};
      })()`);
      const fail = reason => { report.checks.push({ route, width, theme, ...state, passed: false }); throw new Error(`${route} ${width}px ${theme}: ${reason}`); };
      if (state.width !== width || state.scrollWidth > width + 1) fail('horizontal overflow or viewport mismatch');
      if (state.topbarPresent || !state.sharedSheet) fail('shared stylesheet missing or removed topbar present');
      const expectedWidth = width === 390 ? 260 : 224;
      if (Math.abs(state.sidebar.width - expectedWidth) > 0.6) fail(`sidebar width ${state.sidebar.width}, expected ${expectedWidth}`);
      if (!state.homeStyle.fontFamily.includes('Geist Mono') || state.homeStyle.fontSize !== '12px' || state.homeStyle.minHeight !== '36px' || state.homeStyle.gap !== '11px' || ['paddingTop','paddingRight','paddingBottom','paddingLeft'].some(key=>state.homeStyle[key]!=='8px')) fail('navigation typography/spacing differs from Home reference');
      if (Math.abs(state.home.height - 36) > 0.6 || Math.abs(state.homeIcon.width - 14) > 0.6 || Math.abs(state.homeIcon.height - 14) > 0.6) fail('navigation row/icon size differs');
      if (state.brand.fontSize !== '25px' || Math.abs(state.brandIcon.width - 23) > 0.6 || Math.abs(state.brandIcon.height - 23) > 0.6) fail('brand dimensions differ');
      if (width > 760) {
        if (state.sidebar.left !== 0 || state.sidebarStyle.paddingTop !== '30px' || state.sidebarStyle.paddingLeft !== '24px') fail('desktop sidebar position/padding differs');
        const baseline=report.checks.find(check=>check.width===1920);
        if(baseline && (Math.abs(state.home.top-baseline.home.top)>0.6 || state.navRows.some((row,index)=>Math.abs(row.top-baseline.navRows[index].top)>0.6 || Math.abs(row.height-baseline.navRows[index].height)>0.6)))fail('navigation row positions vary between pages');
      } else if (!state.mobileHeaderVisible || state.menuOpen !== menuOpen || (menuOpen && (Math.abs(state.sidebar.left)>0.6 || state.mobileExpanded!=='true')) || (!menuOpen && state.sidebar.right>1)) fail('mobile menu position/state differs');
      report.checks.push({ route, width, theme, ...state, passed: true });
      console.log(JSON.stringify({route,width,theme,menuOpen,sidebarWidth:state.sidebar.width,navFont:state.homeStyle.fontSize,navHeight:state.home.height}));
    }
    async function openMenu() {await evaluate(`document.querySelector('[data-menu]').click()`);await wait(`document.body.classList.contains('menu-open')`,'mobile menu opens');await sleep(250);}
    async function closeMenu() {await evaluate(`document.querySelector('[data-overlay]').click()`);await wait(`!document.body.classList.contains('menu-open')`,'mobile menu closes');await sleep(250);}
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    await send('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });
    await viewport(1920);
    for (const route of routes) {
      await navigate(route); await inspect(route,1920,'light');
      if(route==='/'||route==='/tutorials')await screenshot(`sidebar-shared-desktop-${route==='/'?'home':'tutorials'}.png`);
    }
    await viewport(1024);for(const route of ['/', '/evaluators']){await navigate(route);await inspect(route,1024,'light');}await viewport(1920);
    await navigate('/guidelines'); await evaluate(`document.querySelector('[data-theme-preference=dark]').click()`);await wait(`document.documentElement.dataset.theme==='dark'`,'dark theme');await inspect('/guidelines',1920,'dark');await screenshot('sidebar-shared-desktop-dark-guidelines.png');
    await viewport(390);
    await evaluate(`document.querySelector('[data-theme-preference=light]').click()`);await wait(`document.documentElement.dataset.theme==='light'`,'light theme');
    for(const route of routes) {
      await navigate(route); await inspect(route,390,'light',false);await openMenu();await inspect(route,390,'light',true);
      if(route==='/'||route==='/tutorials')await screenshot(`sidebar-shared-mobile-${route==='/'?'home':'tutorials'}.png`);
      await closeMenu();
    }
    await navigate('/login');await openMenu();await evaluate(`document.querySelector('[data-theme-preference=dark]').click()`);await wait(`document.documentElement.dataset.theme==='dark'`,'mobile dark theme');await inspect('/login',390,'dark',true);await screenshot('sidebar-shared-mobile-dark-login.png');await closeMenu();
    if(report.runtimeErrors.length||report.httpErrors.length||report.rejectedMethods.length)throw new Error('Browser encountered runtime/network errors or an unexpected nonlocal/write request.');
    report.passed=true;
    console.log(JSON.stringify({passed:true,checks:report.checks.length,publicGetOnly:true,databaseWritesRequested:false}));
    await send('Browser.close').catch(()=>{});
  } catch(error) {failure=error;report.passed=false;report.failure=error.message;}
  finally {
    socket?.close();let cleanupFailure;
    for(const child of[browser,server]){try{await stop(child);}catch(error){cleanupFailure??=error;}}
    if(!cleanupFailure&&createdProfile){try{inside(profile,privateRoot);if(!/^sidebar-browser-profile-[a-f0-9]{16}$/.test(path.basename(profile)))throw new Error('Unexpected profile name; cleanup refused.');fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:100});}catch(error){cleanupFailure=error;}}
    report.cleanup=cleanupFailure?{completed:false,reason:cleanupFailure.message}:{completed:true};
    const target=path.join(output,'sidebar-shared-browser-results.json');inside(target);fs.writeFileSync(target,JSON.stringify(report,null,2));
    if(failure||cleanupFailure)throw failure??cleanupFailure;
  }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
