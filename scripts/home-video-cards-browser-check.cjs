// Public-only homepage video-card browser QA. Array sessions/cache; no accounts, writes, fixtures or external network calls.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn } = require('node:child_process');

const root = fs.realpathSync(path.resolve(__dirname, '..'));
const privateRoot = path.join(root, 'storage', 'app', 'private');
const output = path.join(root, 'docs', 'preview');
const thumbnailSources = path.join(root, 'storage', 'app', 'tutorial-video-sources');
const runId = crypto.randomBytes(8).toString('hex');
const profile = path.join(privateRoot, `home-video-cards-browser-profile-${runId}`);
const origin = 'http://127.0.0.1:8016';
const debuggerOrigin = 'http://127.0.0.1:9242';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const exited = child => !!child.qaSpawnError || child.exitCode !== null || child.signalCode !== null;
const report = {
  publicGetOnly: true, authenticationUsed: false, fixturesCreated: false,
  databaseWritesRequested: false, sessionDriver: 'array', cacheStore: 'array',
  externalNetworkAllowed: false, ports: { application: 8016, browser: 9242 }, checks: [],
  thumbnailsStubbed: [], embedsStubbed: [], blockedExternalRequests: [], rejectedMethods: [], runtimeErrors: [], httpErrors: [],
};

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
    listener.once('error', () => reject(new Error(`Port ${port} is occupied; existing processes were left untouched.`)));
    listener.listen(port, '127.0.0.1', () => listener.close(resolve));
  });
}
async function ready(url, child) {
  const end = Date.now() + 20000;
  while (Date.now() < end) {
    if (exited(child)) throw child.qaSpawnError ?? new Error('An owned preview process exited during startup.');
    try { if ((await fetch(url, { signal: AbortSignal.timeout(1000) })).ok) return; } catch {}
    await sleep(150);
  }
  throw new Error('An owned preview process did not become ready within 20 seconds.');
}
async function stop(child) {
  if (!child || exited(child)) return;
  child.kill();
  for (let attempt = 0; attempt < 60 && !exited(child); attempt++) await sleep(100);
  if (!exited(child)) throw new Error('An owned preview process did not exit; its profile was retained.');
}

(async () => {
  let server, browser, socket, failure;
  let previewLog = '';
  let createdProfile = false;
  try {
    fs.mkdirSync(privateRoot, { recursive: true });
    fs.mkdirSync(output, { recursive: true });
    inside(profile, privateRoot); inside(output);
    if (fs.existsSync(profile)) throw new Error('The unique homepage video-card QA profile already exists.');
    if (fs.existsSync(path.join(root, 'bootstrap', 'cache', 'config.php'))) throw new Error('Uncached configuration is required for array-only QA sessions/cache. No live cache was changed.');
    await freePort(8016); await freePort(9242);
    const chrome = [process.env.CHROME_PATH, 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'].find(candidate => candidate && fs.existsSync(candidate));
    if (!chrome) throw new Error('Installed Chrome was not found; no browser was installed.');
    const environment = { ...process.env, APP_URL: origin, APP_DEBUG: 'false', SESSION_DRIVER: 'array', CACHE_STORE: 'array', LOG_CHANNEL: 'stderr', MAIL_MAILER: 'array', SESSION_COOKIE: `analista_home_cards_qa_${runId}`, SESSION_DOMAIN: 'null', SESSION_SECURE_COOKIE: 'false' };
    server = spawn(process.env.PHP_BINARY ?? 'php', ['-S', '127.0.0.1:8016', '-t', 'public', 'dev-server.php'], { cwd: root, env: environment, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    for (const stream of [server.stdout, server.stderr]) stream.on('data', chunk => { previewLog = (previewLog + chunk.toString()).slice(-2000); });
    server.once('error', error => { server.qaSpawnError = error; failure ??= error; });
    await ready(`${origin}/assets/favicon.svg`, server);
    fs.mkdirSync(profile); createdProfile = true;
    browser = spawn(chrome, ['--headless=new', '--remote-debugging-port=9242', '--remote-debugging-address=127.0.0.1', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-sync', '--disable-extensions', 'about:blank'], { cwd: root, windowsHide: true, stdio: 'ignore' });
    browser.once('error', error => { browser.qaSpawnError = error; failure ??= error; });
    await ready(`${debuggerOrigin}/json/version`, browser);
    console.log('QA: Owned homepage video-card preview and fresh Chrome profile ready');
    const targets = await (await fetch(`${debuggerOrigin}/json/list`)).json();
    const target = targets.find(item => item.type === 'page');
    if (!target) throw new Error('Owned Chrome has no page target.');
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', () => reject(new Error('Could not connect to owned Chrome.')), { once: true }); });
    let sequence = 0;
    const pending = new Map();
    const interceptErrors = [];
    function send(method, params = {}) {
      return new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`CDP command timed out: ${method}`)); }, method === 'Page.navigate' ? 30000 : 10000);
        pending.set(id, { resolve: value => { clearTimeout(timeout); resolve(value); }, reject: error => { clearTimeout(timeout); reject(error); } });
        socket.send(JSON.stringify({ id, method, params }));
      });
    }
    async function intercept({ requestId, request }) {
      const url = new URL(request.url);
      if (request.method !== 'GET') {
        report.rejectedMethods.push({ method: request.method, path: url.pathname });
        return send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' });
      }
      if (url.origin === origin || url.protocol === 'data:' || url.protocol === 'about:') return send('Fetch.continueRequest', { requestId });
      if (url.hostname === 'i.ytimg.com' && /^\/vi\/[A-Za-z0-9_-]{11}\/(?:hqdefault|mqdefault|maxresdefault)\.jpg$/.test(url.pathname)) {
        const videoId = url.pathname.split('/')[2];
        const cachedThumbnail = path.join(thumbnailSources, `${videoId}.jpg`);
        if (fs.existsSync(cachedThumbnail)) {
          inside(cachedThumbnail, thumbnailSources);
          const jpeg = fs.readFileSync(cachedThumbnail);
          if (jpeg.length < 3 || jpeg[0] !== 0xff || jpeg[1] !== 0xd8 || jpeg[2] !== 0xff) throw new Error('Cached YouTube thumbnail is not a JPEG.');
          report.thumbnailsStubbed.push({ url: url.href, videoId, source: 'cachedYouTubeJpeg' });
          return send('Fetch.fulfillRequest', { requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'image/jpeg' }, { name: 'Cache-Control', value: 'no-store' }], body: jpeg.toString('base64') });
        }
        throw new Error('Expected cached YouTube thumbnail is missing for homepage card: ' + videoId);
      }
      report.blockedExternalRequests.push(url.href);
      return send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' });
    }
    socket.addEventListener('message', ({ data }) => {
      const packet = JSON.parse(data);
      if (packet.id && pending.has(packet.id)) {
        const callback = pending.get(packet.id); pending.delete(packet.id);
        packet.error ? callback.reject(new Error(packet.error.message)) : callback.resolve(packet.result);
      }
      if (packet.method === 'Runtime.exceptionThrown') report.runtimeErrors.push(packet.params.exceptionDetails.text);
      if (packet.method === 'Network.responseReceived' && packet.params.response.status >= 400) report.httpErrors.push({ path: new URL(packet.params.response.url).pathname, status: packet.params.response.status });
      if (packet.method === 'Fetch.requestPaused') intercept(packet.params).catch(error => interceptErrors.push(error.message));
    });
    async function evaluate(expression) {
      const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
      if (result.exceptionDetails) throw new Error(`Homepage video-card browser evaluation failed: ${result.exceptionDetails.text}`);
      return result.result.value;
    }
    async function wait(expression, label) {
      const end = Date.now() + 18000;
      while (Date.now() < end) { try { if (await evaluate(expression)) return; } catch {} await sleep(125); }
      throw new Error(`Timed out waiting for ${label}.`);
    }
    async function navigate(relative, expression = "!!document.querySelector('.home-video-cards')") {
      await send('Page.navigate', { url: origin + relative });
      await wait(`location.href===${JSON.stringify(origin + relative)} && document.readyState==='complete' && (${expression})`, relative);
      await evaluate('document.fonts.ready.then(() => true)');
    }
    async function screenshot(name) {
      const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      const target = path.join(output, name); inside(target);
      fs.writeFileSync(target, Buffer.from(result.data, 'base64'));
    }
    async function viewport(width) {
      await send('Emulation.setDeviceMetricsOverride', { width, height: width <= 760 ? 844 : 1000, deviceScaleFactor: 1, mobile: width <= 760 });
      await sleep(250);
    }
    async function screenshotSection(name) {
      await evaluate("document.querySelector('.home-video-cards').closest('.home-section').scrollIntoView({block:'start'})");
      await sleep(150);
      const clip = await evaluate("(() => { const r=document.querySelector('.home-video-cards').closest('.home-section').getBoundingClientRect();return {x:Math.max(0,r.left+scrollX-8),y:Math.max(0,r.top+scrollY-12),width:Math.min(innerWidth,r.width+16),height:Math.min(2400,r.height+24),scale:1};})()");
      const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip });
      const target = path.join(output, name); inside(target);
      fs.writeFileSync(target, Buffer.from(result.data, 'base64'));
    }
    async function inspect(label, width) {
      await evaluate("document.querySelectorAll('.home-video-thumbnail img').forEach(img=>img.loading='eager')");
      await wait("document.querySelectorAll('.home-video-thumbnail img').length===3 && Array.from(document.querySelectorAll('.home-video-thumbnail img')).every(img=>img.complete&&img.naturalWidth>0)", 'three real homepage video thumbnails');
      const result = await evaluate(`(() => {
        const main=document.querySelector('.main-content').getBoundingClientRect();
        const grid=document.querySelector('.home-video-cards');const gridRect=grid.getBoundingClientRect();
        const sidebar=document.querySelector('.sidebar');const sr=sidebar.getBoundingClientRect();const ss=getComputedStyle(sidebar);
        const nav=sidebar.querySelector('nav');const navStyle=getComputedStyle(nav);
        const cards=Array.from(grid.querySelectorAll('a.home-video-card')).map(card=>{
          const r=card.getBoundingClientRect();const img=card.querySelector('.home-video-thumbnail img');
          return {path:new URL(card.href).pathname,title:card.querySelector('.home-video-copy h3')?.textContent.trim(),description:card.querySelector('.home-video-copy p')?.textContent.trim(),creator:card.querySelector('.home-video-creator')?.textContent.trim(),watch:card.querySelector('.home-video-watch')?.textContent.trim(),image:img?{src:img.src,width:img.naturalWidth,height:img.naturalHeight,complete:img.complete}:null,rect:{left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height}};
        });
        return {width:innerWidth,scroll:document.documentElement.scrollWidth,theme:document.documentElement.dataset.theme,topbarPresent:!!document.querySelector('.topbar'),main:{left:main.left,right:main.right,width:main.width},grid:{left:gridRect.left,right:gridRect.right,width:gridRect.width,display:getComputedStyle(grid).display,columns:getComputedStyle(grid).gridTemplateColumns},sidebar:{left:sr.left,right:sr.right,width:sr.width,position:ss.position,overflow:ss.overflow,navOverflowY:navStyle.overflowY},mobileHeaderVisible:getComputedStyle(document.querySelector('.mobile-header')).display!=='none',cards};
      })()`);
      if (result.width!==width || result.scroll>width+1 || result.topbarPresent || result.grid.display!=='grid' || result.cards.length!==3) throw new Error(label+': incorrect homepage layout '+JSON.stringify(result));
      if (width>760 && (Math.abs(result.sidebar.width-224)>1 || Math.abs(result.sidebar.left)>1 || result.sidebar.position!=='fixed' || result.sidebar.overflow!=='hidden' || result.sidebar.navOverflowY!=='auto')) throw new Error(label+': desktop sidebar changed '+JSON.stringify(result.sidebar));
      if (width<=760 && (!result.mobileHeaderVisible || result.sidebar.right>1 || Math.abs(result.sidebar.width-260)>1 || result.sidebar.position!=='fixed')) throw new Error(label+': mobile sidebar overlaps content '+JSON.stringify(result.sidebar));
      for (const card of result.cards) {
        if (!/^\/tutorials\/[a-z0-9-]+$/.test(card.path) || !card.title || !card.description || !card.creator || !/^watch/i.test(card.watch??'') || !card.image?.complete || card.image.width<1 || !/^https:\/\/i\.ytimg\.com\/vi\/[A-Za-z0-9_-]{11}\//.test(card.image.src)) throw new Error(label+': incomplete video card '+JSON.stringify(card));
        if (card.rect.left<result.main.left-1 || card.rect.right>result.main.right+1 || card.rect.left<result.grid.left-1 || card.rect.right>result.grid.right+1 || card.rect.width<1) throw new Error(label+': video card exceeds main content '+JSON.stringify(card));
      }
      const paths=result.cards.map(card=>card.path);
      if (new Set(paths).size!==3) throw new Error(label+': video cards must be distinct.');
      if (report.catalogPaths && JSON.stringify(paths)!==JSON.stringify(report.catalogPaths)) throw new Error(label+': published homepage cards changed across viewport checks.');
      report.catalogPaths??=paths;
      report.checks.push({label,...result,horizontalOverflow:false,sidebarUnchanged:true});
    }
    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
    await send('Fetch.enable',{patterns:[{urlPattern:'*',requestStage:'Request'}]});
    await viewport(1440);await navigate('/');
    await inspect('Desktop 1440 light',1440);await screenshotSection('home-video-cards-desktop.png');
    await viewport(1920);await inspect('Desktop 1920 light',1920);
    await viewport(900);await inspect('Tablet 900 light',900);
    await viewport(390);await inspect('Mobile 390 light',390);await screenshotSection('home-video-cards-mobile.png');
    await viewport(320);await inspect('Mobile 320 light',320);
    await viewport(1440);await evaluate("document.querySelector('[data-theme-preference=dark]').click()");
    await wait("document.documentElement.dataset.theme==='dark'",'dark homepage theme');
    await inspect('Desktop 1440 dark',1440);await screenshotSection('home-video-cards-dark.png');
    const expected=report.checks[0].cards[0];
    await evaluate("document.querySelector('a.home-video-card').click()");
    await wait(`location.pathname===${JSON.stringify(expected.path)} && document.readyState==='complete' && !!document.querySelector('[data-video-player]')`,'homepage card detail navigation');
    const destinationTitle=await evaluate("document.querySelector('#main h1').textContent.trim()");
    if(destinationTitle!==expected.title) throw new Error('Homepage card destination title does not match its video.');
    report.cardNavigation={path:expected.path,title:destinationTitle,passed:true};
    report.interceptErrors=interceptErrors;
    if(report.runtimeErrors.length||report.httpErrors.length||report.rejectedMethods.length||report.blockedExternalRequests.length||interceptErrors.length) throw new Error('Browser reported an error, unexpected external request, or write request.');
    report.passed=true;
    console.log(JSON.stringify({passed:true,videos:3,layouts:report.checks.length,publicGetOnly:true,externalNetworkAllowed:false,databaseWritesRequested:false}));
    await send('Browser.close').catch(()=>{});
  } catch(error) {
    failure??=error;report.passed=false;report.failure=error.message;
    if(!report.checks.length&&previewLog) report.previewStartupLog=previewLog;
  } finally {
    socket?.close();let cleanupFailure;
    for(const child of [browser,server]) {try{await stop(child);}catch(error){cleanupFailure??=error;}}
    if(!cleanupFailure&&createdProfile) {
      try {
        inside(profile,privateRoot);
        if(fs.lstatSync(profile).isSymbolicLink()||!/^home-video-cards-browser-profile-[a-f0-9]{16}$/.test(path.basename(profile))) throw new Error('Owned profile changed unexpectedly; recursive cleanup refused.');
        fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:100});
      } catch(error) {cleanupFailure=error;}
    }
    report.cleanup=cleanupFailure?{completed:false,reason:cleanupFailure.message}:{completed:true};
    const reportPath=path.join(output,'home-video-cards-browser-results.json');inside(reportPath);
    fs.writeFileSync(reportPath,JSON.stringify(report,null,2));
    if(failure||cleanupFailure) throw failure??cleanupFailure;
  }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
