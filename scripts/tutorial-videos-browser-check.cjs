// Public-only tutorial browser QA. Array sessions/cache; no accounts, writes, fixtures or external network calls.
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
const profile = path.join(privateRoot, `tutorials-browser-profile-${runId}`);
const origin = 'http://127.0.0.1:8014';
const debuggerOrigin = 'http://127.0.0.1:9240';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const exited = child => !!child.qaSpawnError || child.exitCode !== null || child.signalCode !== null;
const report = {
  publicGetOnly: true, authenticationUsed: false, fixturesCreated: false,
  databaseWritesRequested: false, sessionDriver: 'array', cacheStore: 'array',
  externalNetworkAllowed: false, ports: { application: 8014, browser: 9240 }, checks: [],
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
    if (fs.existsSync(profile)) throw new Error('The unique tutorial QA profile already exists.');
    if (fs.existsSync(path.join(root, 'bootstrap', 'cache', 'config.php'))) throw new Error('Uncached configuration is required for array-only QA sessions/cache. No live cache was changed.');
    await freePort(8014); await freePort(9240);
    const chrome = [process.env.CHROME_PATH, 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'].find(candidate => candidate && fs.existsSync(candidate));
    if (!chrome) throw new Error('Installed Chrome was not found; no browser was installed.');
    const environment = { ...process.env, APP_URL: origin, APP_DEBUG: 'false', SESSION_DRIVER: 'array', CACHE_STORE: 'array', LOG_CHANNEL: 'stderr', MAIL_MAILER: 'array', SESSION_COOKIE: `analista_tutorial_qa_${runId}`, SESSION_DOMAIN: 'null', SESSION_SECURE_COOKIE: 'false' };
    server = spawn(process.env.PHP_BINARY ?? 'php', ['-S', '127.0.0.1:8014', '-t', 'public', 'dev-server.php'], { cwd: root, env: environment, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    for (const stream of [server.stdout, server.stderr]) stream.on('data', chunk => { previewLog = (previewLog + chunk.toString()).slice(-2000); });
    server.once('error', error => { server.qaSpawnError = error; failure ??= error; });
    await ready(`${origin}/assets/favicon.svg`, server);
    fs.mkdirSync(profile); createdProfile = true;
    browser = spawn(chrome, ['--headless=new', '--remote-debugging-port=9240', '--remote-debugging-address=127.0.0.1', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-sync', '--disable-extensions', 'about:blank'], { cwd: root, windowsHide: true, stdio: 'ignore' });
    browser.once('error', error => { browser.qaSpawnError = error; failure ??= error; });
    await ready(`${debuggerOrigin}/json/version`, browser);
    console.log('QA: Owned tutorial preview and fresh Chrome profile ready');
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
        report.thumbnailsStubbed.push({ url: url.href, videoId, source: 'neutralPreviewFallback' });
        const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="270" viewBox="0 0 480 270"><rect width="480" height="270" fill="#e9e9e9"/><path d="M220 108v54l47-27z" fill="#777"/><text x="240" y="215" text-anchor="middle" font-family="sans-serif" font-size="13" fill="#888">YouTube thumbnail preview</text></svg>';
        return send('Fetch.fulfillRequest', { requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'image/svg+xml' }, { name: 'Cache-Control', value: 'no-store' }], body: Buffer.from(svg).toString('base64') });
      }
      if (url.origin === 'https://www.youtube-nocookie.com' && /^\/embed\/[A-Za-z0-9_-]{11}$/.test(url.pathname)) {
        const headerNames = Object.keys(request.headers).map(name => name.toLowerCase());
        report.embedsStubbed.push({ url: url.href, headerNames, credentialsSent: headerNames.includes('cookie') || headerNames.includes('authorization') });
        const html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Isolated video player preview</title></head><body style="margin:0;display:grid;place-items:center;background:#111;color:#eee;height:100vh;font:16px sans-serif"><p>Video player preview · YouTube connection stubbed for local QA</p></body></html>';
        return send('Fetch.fulfillRequest', { requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }, { name: 'Cache-Control', value: 'no-store' }], body: Buffer.from(html).toString('base64') });
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
      if (result.exceptionDetails) throw new Error(`Tutorial browser evaluation failed: ${result.exceptionDetails.text}`);
      return result.result.value;
    }
    async function wait(expression, label) {
      const end = Date.now() + 18000;
      while (Date.now() < end) { try { if (await evaluate(expression)) return; } catch {} await sleep(125); }
      throw new Error(`Timed out waiting for ${label}.`);
    }
    async function navigate(relative, expression = "!!document.querySelector('[data-video-library]')") {
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
      await send('Emulation.setDeviceMetricsOverride', { width, height: width === 390 ? 844 : 1000, deviceScaleFactor: 1, mobile: width === 390 });
      await sleep(250);
    }
    async function inspect(label, width) {
      const result = await evaluate(`(() => { const items=document.querySelector('[data-video-items]');const sidebar=document.querySelector('.sidebar')?.getBoundingClientRect();return {width:innerWidth,scroll:document.documentElement.scrollWidth,heading:document.querySelector('#main h1')?.textContent.trim(),layout:items?.dataset.layout??null,videos:document.querySelectorAll('.learning-video').length,topbarPresent:!!document.querySelector('.topbar'),mobileHeaderVisible:getComputedStyle(document.querySelector('.mobile-header')).display!=='none',sidebarRight:sidebar?.right,theme:document.documentElement.dataset.theme};})()`);
      if (result.width !== width || result.scroll > width + 1 || !result.heading || result.topbarPresent) throw new Error(`${label}: incorrect layout ${JSON.stringify(result)}`);
      if (width === 390 && (!result.mobileHeaderVisible || result.sidebarRight > 1)) throw new Error(`${label}: mobile navigation overlaps content.`);
      report.checks.push({ label, ...result, horizontalOverflow: false });
    }
    async function chooseLayout(layout) {
      await evaluate(`document.querySelector('[data-video-view="${layout}"]').click()`);
      await wait(`document.querySelector('[data-video-items]').dataset.layout==='${layout}' && document.querySelector('[data-video-view="${layout}"]').getAttribute('aria-pressed')==='true' && Array.from(document.querySelectorAll('[data-video-view]')).filter(button=>button.getAttribute('aria-pressed')==='true').length===1`, `${layout} layout and accessible button state`);
    }
    async function search(q = '', category = '') {
      // Existing filtered links still work, while the library no longer shows a search form.
      await navigate('/tutorials?' + new URLSearchParams({ q, category }));
      await wait(`document.readyState==='complete' && new URL(location.href).searchParams.get('q')===${JSON.stringify(q)} && new URL(location.href).searchParams.get('category')===${JSON.stringify(category)} && !!document.querySelector('[data-video-library]')`, 'filtered GET link');
    }
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    await send('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });
    await viewport(1440);
    await navigate('/tutorials');
    await wait(`!document.querySelector('[data-video-views]').hidden`, 'video view controls');
    const catalog = await evaluate(`Array.from(document.querySelectorAll('.learning-video')).map(a=>({title:a.querySelector('h2').textContent.trim(),creator:a.querySelector('.learning-byline > span:last-child').textContent.trim(),topic:a.querySelector('.learning-topic').textContent.trim(),path:new URL(a.href).pathname,thumbnail:a.querySelector('img').getAttribute('src')}))`);
    if (catalog.length !== 10 || new Set(catalog.map(video => video.path)).size !== 10 || catalog.some(video => !video.creator || !/^https:\/\/i\.ytimg\.com\/vi\/[A-Za-z0-9_-]{11}\//.test(video.thumbnail))) throw new Error('Default tutorial library must contain 10 distinct attributed YouTube videos.');
    report.catalog = catalog;
    await evaluate(`document.querySelectorAll('.learning-thumbnail img').forEach(img=>img.loading='eager')`);
    await wait(`document.querySelectorAll('.learning-thumbnail img').length===10 && Array.from(document.querySelectorAll('.learning-thumbnail img')).every(img=>img.complete&&img.naturalWidth>0)`, '10 loaded video thumbnails');
    report.loadedThumbnailImages = await evaluate(`Array.from(document.querySelectorAll('.learning-thumbnail img')).map(img=>({src:img.src,naturalWidth:img.naturalWidth,naturalHeight:img.naturalHeight,complete:img.complete}))`);
    await wait(`document.querySelector('[data-video-items]').dataset.layout==='grid' && document.querySelector('[data-video-view="grid"]').getAttribute('aria-pressed')==='true'`, 'default grid layout');
    report.defaultLayout = 'grid';
    await inspect('Desktop default grid', 1440);
    await screenshot('tutorial-videos-desktop.png');
    await chooseLayout('list'); await inspect('Desktop list', 1440);
    await chooseLayout('grid');
    await navigate('/tutorials');
    await wait(`document.querySelector('[data-video-items]').dataset.layout==='grid' && document.querySelector('[data-video-view="grid"]').getAttribute('aria-pressed')==='true'`, 'persisted grid preference');
    report.layoutPreferencePersists = true;
    report.searchControlsRemoved = await evaluate(`!document.querySelector('.learning-filters')`);
    if (!report.searchControlsRemoved) throw new Error('Removed tutorial search controls are still visible.');
    await chooseLayout('list');
    await search(catalog[0].creator);
    await wait(`document.querySelectorAll('.learning-video').length>0 && Array.from(document.querySelectorAll('.learning-byline > span:last-child')).every(span=>span.textContent.trim()===${JSON.stringify(catalog[0].creator)})`, 'creator search results');
    report.creatorSearch = { query: catalog[0].creator, results: await evaluate(`document.querySelectorAll('.learning-video').length`) };
    await search('', catalog[0].topic);
    await wait(`document.querySelectorAll('.learning-video').length>0 && Array.from(document.querySelectorAll('.learning-topic')).every(span=>span.textContent.trim()===${JSON.stringify(catalog[0].topic)})`, 'topic filter results');
    report.topicFilter = { topic: catalog[0].topic, results: await evaluate(`document.querySelectorAll('.learning-video').length`) };
    await search('analista-no-results-check-7284');
    await wait(`document.querySelectorAll('.learning-video').length===0 && document.querySelector('#main').textContent.includes('No videos found.')`, 'empty search result');
    await evaluate(`Array.from(document.querySelectorAll('.learning-results a')).find(a=>a.textContent.includes('Clear filters')).click()`);
    await wait(`location.pathname==='/tutorials' && location.search==='' && document.querySelectorAll('.learning-video').length===10`, 'clear search filters');
    report.emptyAndClearFilters = true;
    await viewport(390); await evaluate('scrollTo(0,0)');
    await chooseLayout('list'); await inspect('Mobile list', 390); await screenshot('tutorial-videos-mobile.png');
    await chooseLayout('grid'); await inspect('Mobile grid', 390);
    await evaluate(`document.querySelector('[data-theme-preference=dark]').click()`);
    await wait(`document.documentElement.dataset.theme==='dark'`, 'dark video library');
    await inspect('Mobile dark grid', 390); await screenshot('tutorial-videos-mobile-dark.png');
    await evaluate(`document.querySelector('[data-theme-preference=light]').click()`);
    await wait(`document.documentElement.dataset.theme==='light'`, 'light theme restored');
    await viewport(1440);
    await navigate(catalog[0].path, "!!document.querySelector('[data-video-player]')");
    const beforePlay = await evaluate(`(() => { const player=document.querySelector('[data-video-player]');const link=Array.from(document.querySelectorAll('#main a')).find(a=>a.textContent.includes('Watch on YouTube'));return {iframeCount:player.querySelectorAll('iframe').length,buttonPresent:!!player.querySelector('button[data-video-play]'),embed:player.dataset.videoEmbed,title:player.dataset.videoTitle,externalLink:link?{url:link.href,target:link.target,rel:link.rel}:null};})()`);
    if (beforePlay.iframeCount !== 0 || !beforePlay.buttonPresent || !beforePlay.externalLink || !/^https:\/\/www\.youtube\.com\/watch\?v=[A-Za-z0-9_-]{11}$/.test(beforePlay.externalLink.url) || beforePlay.externalLink.target !== '_blank' || !beforePlay.externalLink.rel.includes('noopener')) throw new Error(`Video detail before playback failed: ${JSON.stringify(beforePlay)}`);
    await inspect('Desktop video detail', 1440);
    await evaluate(`document.querySelector('[data-video-player] button[data-video-play]').click()`);
    await wait(`!!document.querySelector('[data-video-player] iframe')`, 'click-to-load video player');
    const player = await evaluate(`(() => { const iframe=document.querySelector('[data-video-player] iframe');return {src:iframe.src,title:iframe.title,allow:iframe.allow,allowFullscreen:iframe.allowFullscreen};})()`);
    const playerUrl = new URL(player.src);
    if (playerUrl.origin !== 'https://www.youtube-nocookie.com' || !/^\/embed\/[A-Za-z0-9_-]{11}$/.test(playerUrl.pathname) || playerUrl.searchParams.get('autoplay') !== '1' || player.title !== beforePlay.title || !player.title || !player.allowFullscreen) throw new Error(`Video playback URL/title failed: ${JSON.stringify(player)}`);
    await wait(`document.querySelector('[data-video-player] iframe').contentWindow!==null`, 'video iframe preview');
    for (let attempt = 0; attempt < 40 && report.embedsStubbed.length === 0; attempt++) await sleep(100);
    if (report.embedsStubbed.length !== 1 || report.embedsStubbed[0].url !== player.src || report.embedsStubbed[0].credentialsSent) throw new Error('Player request was not safely intercepted without credentials.');
    report.player = { beforePlay, afterPlay: player, clickToLoad: true, youtubeRequestStubbed: true };
    await screenshot('tutorial-videos-player.png');
    await viewport(390); await evaluate('scrollTo(0,0)'); await inspect('Mobile video detail', 390);
    report.interceptErrors = interceptErrors;
    if (report.runtimeErrors.length || report.httpErrors.length || report.rejectedMethods.length || report.blockedExternalRequests.length || interceptErrors.length) throw new Error('Browser reported an error, unexpected external request, or write request.');
    report.passed = true;
    console.log(JSON.stringify({ passed: true, videos: catalog.length, layouts: report.checks.length, publicGetOnly: true, externalNetworkAllowed: false, databaseWritesRequested: false }));
    await send('Browser.close').catch(() => {});
  } catch (error) {
    failure ??= error;
    report.passed = false; report.failure = error.message;
    if (!report.checks.length && previewLog) report.previewStartupLog = previewLog;
  } finally {
    socket?.close();
    let cleanupFailure;
    for (const child of [browser, server]) { try { await stop(child); } catch (error) { cleanupFailure ??= error; } }
    if (!cleanupFailure && createdProfile) {
      try {
        inside(profile, privateRoot);
        if (fs.lstatSync(profile).isSymbolicLink() || !/^tutorials-browser-profile-[a-f0-9]{16}$/.test(path.basename(profile))) throw new Error('Owned profile changed unexpectedly; recursive cleanup refused.');
        fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
      } catch (error) { cleanupFailure = error; }
    }
    report.cleanup = cleanupFailure ? { completed: false, reason: cleanupFailure.message } : { completed: true };
    const reportPath = path.join(output, 'tutorial-videos-browser-results.json'); inside(reportPath);
    fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
    if (failure || cleanupFailure) throw failure ?? cleanupFailure;
  }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
