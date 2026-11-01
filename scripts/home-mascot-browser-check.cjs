// Public-only homepage mascot browser QA. Array sessions/cache; no accounts, writes, fixtures or external network calls.
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
const profile = path.join(privateRoot, `home-mascot-browser-profile-${runId}`);
const origin = 'http://127.0.0.1:8018';
const debuggerOrigin = 'http://127.0.0.1:9244';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const exited = child => !!child.qaSpawnError || child.exitCode !== null || child.signalCode !== null;
const report = {
  publicGetOnly: true, authenticationUsed: false, fixturesCreated: false,
  databaseWritesRequested: false, sessionDriver: 'array', cacheStore: 'array',
  externalNetworkAllowed: false, ports: { application: 8018, browser: 9244 }, checks: [],
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
    if (fs.existsSync(profile)) throw new Error('The unique homepage mascot QA profile already exists.');
    if (fs.existsSync(path.join(root, 'bootstrap', 'cache', 'config.php'))) throw new Error('Uncached configuration is required for array-only QA sessions/cache. No live cache was changed.');
    await freePort(8018); await freePort(9244);
    const chrome = [process.env.CHROME_PATH, 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'].find(candidate => candidate && fs.existsSync(candidate));
    if (!chrome) throw new Error('Installed Chrome was not found; no browser was installed.');
    const environment = { ...process.env, APP_URL: origin, APP_DEBUG: 'false', SESSION_DRIVER: 'array', CACHE_STORE: 'array', LOG_CHANNEL: 'stderr', MAIL_MAILER: 'array', SESSION_COOKIE: `analista_home_mascot_qa_${runId}`, SESSION_DOMAIN: 'null', SESSION_SECURE_COOKIE: 'false' };
    server = spawn(process.env.PHP_BINARY ?? 'php', ['-S', '127.0.0.1:8018', '-t', 'public', 'dev-server.php'], { cwd: root, env: environment, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    for (const stream of [server.stdout, server.stderr]) stream.on('data', chunk => { previewLog = (previewLog + chunk.toString()).slice(-2000); });
    server.once('error', error => { server.qaSpawnError = error; failure ??= error; });
    await ready(`${origin}/assets/favicon.svg`, server);
    fs.mkdirSync(profile); createdProfile = true;
    browser = spawn(chrome, ['--headless=new', '--remote-debugging-port=9244', '--remote-debugging-address=127.0.0.1', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-sync', '--disable-extensions', 'about:blank'], { cwd: root, windowsHide: true, stdio: 'ignore' });
    browser.once('error', error => { browser.qaSpawnError = error; failure ??= error; });
    await ready(`${debuggerOrigin}/json/version`, browser);
    console.log('QA: Owned homepage mascot preview and fresh Chrome profile ready');
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
      if (result.exceptionDetails) throw new Error(`Homepage mascot browser evaluation failed: ${result.exceptionDetails.text}`);
      return result.result.value;
    }
    async function wait(expression, label) {
      const end = Date.now() + 18000;
      while (Date.now() < end) { try { if (await evaluate(expression)) return; } catch {} await sleep(125); }
      throw new Error(`Timed out waiting for ${label}.`);
    }
    async function navigate(relative, expression = "!!document.querySelector('.analista-mascot')") {
      await send('Page.navigate', { url: origin + relative });
      await wait(`location.href===${JSON.stringify(origin + relative)} && document.readyState==='complete' && (${expression})`, relative);
      await evaluate('document.fonts.ready.then(() => true)');
      await evaluate("document.documentElement.style.scrollBehavior='auto'");
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
    async function inspect(label,width) {
      await evaluate('scrollTo(0,0)');
      await evaluate("document.querySelectorAll('.home-video-thumbnail img').forEach(img=>img.loading='eager')");
      await wait("document.querySelectorAll('.home-video-card').length===3 && Array.from(document.querySelectorAll('.home-video-thumbnail img')).every(img=>img.complete&&img.naturalWidth>0)",'three preserved video-card thumbnails');
      const result=await evaluate(`(() => {
        const mascot=document.querySelector('.home-intro-art svg.analista-mascot');const mr=mascot?.getBoundingClientRect();const ms=mascot?getComputedStyle(mascot):null;
        const sky=document.querySelector('.home-sky');const skyStyle=sky?getComputedStyle(sky):null;const shellStyle=getComputedStyle(document.querySelector('.app-shell'));
        const hero=document.querySelector('.home-hero');const heroRect=hero?.getBoundingClientRect();const skyRect=sky?.getBoundingClientRect();const introRect=document.querySelector('.home-intro').getBoundingClientRect();
        const bounds=rect=>rect?{left:rect.left,right:rect.right,top:rect.top,bottom:rect.bottom,width:rect.width,height:rect.height}:null;
        const laptopElements=mascot?Array.from(mascot.querySelectorAll('[class*="laptop"],[id*="laptop"]')):[];
        const laptopVisible=laptopElements.some(element=>{const style=getComputedStyle(element),rect=element.getBoundingClientRect();return style.display!=='none'&&style.visibility!=='hidden'&&Number(style.opacity)>0&&rect.width>0&&rect.height>0;});
        const fadeStyles=sky?[getComputedStyle(sky,'::before').backgroundImage,getComputedStyle(sky,'::after').backgroundImage,getComputedStyle(hero,'::before').backgroundImage,getComputedStyle(hero,'::after').backgroundImage]:[];
        const sidebar=document.querySelector('.sidebar');const sr=sidebar.getBoundingClientRect();const ss=getComputedStyle(sidebar);const navStyle=getComputedStyle(sidebar.querySelector('nav'));
        const cards=Array.from(document.querySelectorAll('a.home-video-card')).map(card=>{const r=card.getBoundingClientRect();const img=card.querySelector('.home-video-thumbnail img');return {path:new URL(card.href).pathname,title:card.querySelector('h3')?.textContent.trim(),imageLoaded:!!img?.complete&&img.naturalWidth>0,left:r.left,right:r.right};});
        const main=document.querySelector('.main-content').getBoundingClientRect();
        return {width:innerWidth,scroll:document.documentElement.scrollWidth,theme:document.documentElement.dataset.theme??'light',topbarPresent:!!document.querySelector('.topbar'),oldPaperPresent:!!document.querySelector('.home-draft'),mascotStyles:!!Array.from(document.querySelectorAll('link[rel="stylesheet"]')).find(link=>new URL(link.href).pathname.endsWith('/assets/mascot.css')),mascot:mascot?{role:mascot.getAttribute('role'),title:mascot.querySelector('title')?.textContent.trim(),viewBox:mascot.getAttribute('viewBox'),ariaHidden:mascot.getAttribute('aria-hidden'),visible:ms.display!=='none'&&ms.visibility!=='hidden'&&Number(ms.opacity)>0,width:mr.width,height:mr.height,laptopElements:laptopElements.length,laptopVisible}:null,clouds:Array.from(document.querySelectorAll('.home-sky svg.home-sky-cloud')).map(cloud=>({decorative:!!cloud.closest('[aria-hidden="true"]'),pointerEvents:getComputedStyle(cloud).pointerEvents,viewBox:cloud.getAttribute('viewBox'),rects:cloud.querySelectorAll('rect').length})),hero:hero?{rect:bounds(heroRect),introRect:bounds(introRect),containsTitle:hero.contains(document.querySelector('#home-title')),containsMascot:hero.contains(mascot),containsCommunity:hero.contains(document.querySelector('.home-community'))}:null,sky:sky?{pointerEvents:skyStyle.pointerEvents,zIndex:skyStyle.zIndex,position:skyStyle.position,background:skyStyle.backgroundImage,rect:bounds(skyRect),overflow:skyStyle.overflow,fadeStyles}:null,shellBackground:shellStyle.backgroundImage,colorSamples:{shellBackgroundColor:shellStyle.backgroundColor,pageBackgroundColor:getComputedStyle(document.body).backgroundColor,gradient:skyStyle.backgroundImage,cloudFill:getComputedStyle(document.querySelector('.home-sky-cloud')).fill,bodyText:getComputedStyle(document.querySelector('.home-intro-copy p')).color},main:{left:main.left,right:main.right,width:main.width},cards,sidebar:{left:sr.left,right:sr.right,width:sr.width,position:ss.position,overflow:ss.overflow,navOverflowY:navStyle.overflowY},mobileHeaderVisible:getComputedStyle(document.querySelector('.mobile-header')).display!=='none'};
      })()`);
      const skyExtension=await evaluate(`(() => {const sky=document.querySelector('.home-sky').getBoundingClientRect(),hero=document.querySelector('.home-hero').getBoundingClientRect();return {pixels:sky.bottom-hero.bottom,firstVideoSectionTop:document.querySelector('.home-sections .home-section').getBoundingClientRect().top,communityLinks:Array.from(document.querySelectorAll('.home-community a')).map(link=>{const r=link.getBoundingClientRect(),x=r.left+r.width/2,y=r.top+r.height/2;return {text:link.textContent.trim(),uncovered:link.contains(document.elementFromPoint(x,y))};})};})()`);
      result.skyExtension=skyExtension;
      if(result.width!==width||result.scroll>width+1||result.topbarPresent||result.oldPaperPresent||!result.mascotStyles) throw new Error(label+': incorrect mascot layout '+JSON.stringify(result));
      if(!result.mascot?.visible||result.mascot.width<50||result.mascot.height<50||result.mascot.role!=='img'||!result.mascot.title||result.mascot.ariaHidden==='true') throw new Error(label+': mascot missing or inaccessible '+JSON.stringify(result.mascot));
      if(result.mascot.viewBox?.trim().replace(/\s+/g,' ')!=='-5 -6 19 19') throw new Error(label+': original reference SVG viewBox is missing.');
      if(label.includes('reduced motion')&&(!result.mascot.laptopElements||!result.mascot.laptopVisible)) throw new Error(label+': reduced-motion laptop is not visible.');
      if(!result.hero?.containsTitle||!result.hero.containsMascot||result.hero.containsCommunity||result.hero.rect.top>result.hero.introRect.top+1||result.hero.rect.bottom<result.hero.introRect.bottom-1) throw new Error(label+': hero must contain the intro and exclude lower sections.');
      if(result.clouds.length!==8||result.clouds.some(cloud=>!cloud.decorative||cloud.rects!==5)||result.sky?.pointerEvents!=='none'||result.sky.position!=='absolute'||Number(result.sky.zIndex)>=0||result.sky.overflow!=='hidden') throw new Error(label+': decorative hero clouds/background are incorrect '+JSON.stringify({sky:result.sky,clouds:result.clouds}));
      const expectedExtension=width>760?120:width>520?96:80;
      if(skyExtension.pixels<=0||Math.abs(skyExtension.pixels-expectedExtension)>1||result.sky.rect.bottom>skyExtension.firstVideoSectionTop||result.sky.rect.top<result.hero.rect.top-1||result.sky.rect.width<result.main.width-1) throw new Error(label+': sky extension is missing, incorrectly sized, or reaches video content.');
      if(skyExtension.communityLinks.length!==3||skyExtension.communityLinks.some(link=>!link.uncovered)) throw new Error(label+': extended sky covers community links.');
      if(![result.sky.background,result.shellBackground].some(background=>background.includes('gradient'))) throw new Error(label+': homepage gradient is missing.');
      if(width>760&&(Math.abs(result.sidebar.width-224)>1||Math.abs(result.sidebar.left)>1||result.sidebar.position!=='fixed'||result.sidebar.overflow!=='hidden'||result.sidebar.navOverflowY!=='auto')) throw new Error(label+': desktop sidebar changed.');
      if(width<=760&&(!result.mobileHeaderVisible||result.sidebar.right>1||Math.abs(result.sidebar.width-260)>1||result.sidebar.position!=='fixed')) throw new Error(label+': mobile sidebar overlaps content.');
      if(result.cards.length!==3||result.cards.some(card=>!/^\/tutorials\/[a-z0-9-]+$/.test(card.path)||!card.title||!card.imageLoaded||card.left<result.main.left-1||card.right>result.main.right+1)) throw new Error(label+': existing video cards were not preserved.');
      const cardPaths=result.cards.map(card=>card.path);
      if(report.catalogPaths&&JSON.stringify(cardPaths)!==JSON.stringify(report.catalogPaths)) throw new Error(label+': existing video cards changed across layouts.');
      result.normalFlowProof=await proveSkyExtensionDoesNotMoveContent();
      report.catalogPaths??=cardPaths;report.checks.push({label,...result,horizontalOverflow:false,sidebarUnchanged:true});
    }
    async function proveSkyExtensionDoesNotMoveContent() {
      const proof=await evaluate(`(() => {
        const hero=document.querySelector('.home-hero'),sky=document.querySelector('.home-sky');
        const selectors=['.home-intro','.home-hero','.home-community','.home-stats','.home-sections .home-section'];
        const capture=()=>Object.fromEntries(selectors.map(selector=>{const r=document.querySelector(selector).getBoundingClientRect();return [selector,{left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height}];}));
        const originalStyle=hero.getAttribute('style'),originalExtension=getComputedStyle(hero).getPropertyValue('--home-sky-extension').trim();const before=capture(),skyBefore=sky.getBoundingClientRect().bottom;let withoutExtension,skyWithoutExtension;
        try {hero.style.setProperty('--home-sky-extension','0px');withoutExtension=capture();skyWithoutExtension=sky.getBoundingClientRect().bottom;}
        finally {if(originalStyle===null) hero.removeAttribute('style');else hero.setAttribute('style',originalStyle);}
        const restored=capture(),skyRestored=sky.getBoundingClientRect().bottom;
        const restoredExtension=getComputedStyle(hero).getPropertyValue('--home-sky-extension').trim();
        return {before,withoutExtension,restored,skyBefore,skyWithoutExtension,skyRestored,originalStyle,restoredStyle:hero.getAttribute('style'),originalExtension,restoredExtension,styleRestored:restoredExtension===originalExtension};
      })()`);
      const equalRects=(left,right)=>Object.keys(left).every(selector=>Object.keys(left[selector]).every(field=>left[selector][field]===right[selector][field]));
      const diagnostics={flowIdenticalWithoutExtension:equalRects(proof.before,proof.withoutExtension),flowIdenticalAfterRestore:equalRects(proof.before,proof.restored),styleRestored:proof.styleRestored,skyBefore:proof.skyBefore,skyWithoutExtension:proof.skyWithoutExtension,skyRestored:proof.skyRestored};
      if(!diagnostics.flowIdenticalWithoutExtension||!diagnostics.flowIdenticalAfterRestore||!proof.styleRestored||proof.skyBefore<=proof.skyWithoutExtension||Math.abs(proof.skyRestored-proof.skyBefore)>0.01) {
        report.normalFlowProofFailure={...proof,diagnostics};
        throw new Error('Sky extension flow check failed: '+JSON.stringify(diagnostics));
      }
      return {...proof,allFlowRectsIdentical:true,backgroundHeightChangesWithoutMovingContent:true};
    }
    async function animationSnapshot() {
      return evaluate(`(() => {const capture=selector=>Array.from(document.querySelectorAll(selector)).map((element,index)=>{const style=getComputedStyle(element);return {index,tag:element.tagName,className:element.getAttribute('class'),transform:style.transform,opacity:style.opacity,animationName:style.animationName,animationDuration:style.animationDuration};});return {clouds:capture('.home-sky-cloud'),mascot:capture('svg.analista-mascot *'),reduced:matchMedia('(prefers-reduced-motion: reduce)').matches};})()`);
    }
    async function inspectLowerSections(label) {
      await evaluate("document.querySelector('.home-video-cards').closest('.home-section').scrollIntoView({block:'start'})");await sleep(100);
      const result=await evaluate(`(() => {const sky=document.querySelector('.home-sky').getBoundingClientRect(),hero=document.querySelector('.home-hero').getBoundingClientRect();return {theme:document.documentElement.dataset.theme??'light',bodyBackground:getComputedStyle(document.body).backgroundColor,shellBackground:getComputedStyle(document.querySelector('.app-shell')).backgroundImage,mainBackground:getComputedStyle(document.querySelector('.main-content')).backgroundImage,skyBottom:sky.bottom,heroBottom:hero.bottom,cloudsInsideHero:document.querySelector('.home-hero').contains(document.querySelector('.home-sky')),scrollY};})()`);
      const expected=result.theme==='dark'?'rgb(17, 17, 17)':'rgb(255, 255, 255)';
      if(result.skyBottom>1||result.heroBottom>1||result.bodyBackground!==expected||result.shellBackground!=='none'||result.mainBackground!=='none'||!result.cloudsInsideHero) throw new Error(label+': lower sections still show hero sky '+JSON.stringify(result));
      (report.lowerSections??=[]).push({label,...result,skyScrolledAway:true,themePageBackgroundPreserved:true});
    }
    async function screenshotTop(name) {await evaluate('scrollTo(0,0)');await sleep(120);await screenshot(name);}
    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
    await send('Fetch.enable',{patterns:[{urlPattern:'*',requestStage:'Request'}]});
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'no-preference'}]});
    await viewport(1440);await navigate('/');
    if(await evaluate("document.documentElement.dataset.theme==='dark'")) await evaluate("document.querySelector('[data-theme-preference=light]').click()");
    await inspect('Desktop 1440 light',1440);await screenshotTop('home-mascot-desktop.png');
    const beforeAnimation=await animationSnapshot();let afterAnimation=beforeAnimation;const animationSamples=[];
    const changed=(before,after)=>before.some((element,index)=>element.transform!==after[index]?.transform);
    for(let attempt=1;attempt<=5;attempt++) {await sleep(850);afterAnimation=await animationSnapshot();animationSamples.push({elapsedMs:attempt*850,snapshot:afterAnimation});if(changed(beforeAnimation.clouds,afterAnimation.clouds)&&changed(beforeAnimation.mascot,afterAnimation.mascot)) break;}
    if(beforeAnimation.reduced||beforeAnimation.clouds.length!==8||!beforeAnimation.mascot.length||!changed(beforeAnimation.clouds,afterAnimation.clouds)||!changed(beforeAnimation.mascot,afterAnimation.mascot)) throw new Error('Cloud and mascot transforms did not change during the animation sample.');
    report.animationProof={sampleIntervalMs:850,cloudsChanged:true,mascotChanged:true,animatedMascotElements:beforeAnimation.mascot.filter(element=>element.animationName!=='none'),before:beforeAnimation,after:afterAnimation,samples:animationSamples};
    await inspectLowerSections('Desktop light lower sections');
    await viewport(1920);await inspect('Desktop 1920 light',1920);
    await viewport(900);await inspect('Tablet 900 light',900);
    await viewport(390);await inspect('Mobile 390 light',390);await screenshotTop('home-mascot-mobile.png');await inspectLowerSections('Mobile light lower sections');
    await viewport(320);await inspect('Mobile 320 light',320);
    await viewport(1440);await evaluate("document.querySelector('[data-theme-preference=dark]').click()");await wait("document.documentElement.dataset.theme==='dark'",'dark homepage');
    await inspect('Desktop 1440 dark',1440);await screenshotTop('home-mascot-dark.png');await inspectLowerSections('Desktop dark lower sections');
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});await sleep(100);
    const reducedBefore=await animationSnapshot();await sleep(850);const reducedAfter=await animationSnapshot();
    const namesAreNone=elements=>elements.every(element=>element.animationName.split(',').every(name=>name.trim()==='none'));
    if(!reducedBefore.reduced||!namesAreNone(reducedBefore.clouds)||!namesAreNone(reducedBefore.mascot)||changed(reducedBefore.clouds,reducedAfter.clouds)||changed(reducedBefore.mascot,reducedAfter.mascot)) throw new Error('Reduced-motion mode did not keep clouds and mascot static.');
    await inspect('Desktop 1440 reduced motion',1440);report.reducedMotion={allAnimationsNone:true,staticMascotVisible:true,before:reducedBefore,after:reducedAfter};
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'no-preference'}]});
    const destination=report.checks[0].cards[0];
    await evaluate("document.querySelector('a.home-video-card').scrollIntoView({block:'center'})");await sleep(150);
    const clickPoint=await evaluate("(() => {const card=document.querySelector('a.home-video-card');const r=card.getBoundingClientRect();const x=r.left+r.width/2,y=r.top+r.height/2;return {x,y,covered:!card.contains(document.elementFromPoint(x,y))};})()");
    if(clickPoint.covered) throw new Error('Homepage background covers a video-card link.');
    await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:clickPoint.x,y:clickPoint.y});
    await send('Input.dispatchMouseEvent',{type:'mousePressed',x:clickPoint.x,y:clickPoint.y,button:'left',clickCount:1});
    await send('Input.dispatchMouseEvent',{type:'mouseReleased',x:clickPoint.x,y:clickPoint.y,button:'left',clickCount:1});
    await wait(`location.pathname===${JSON.stringify(destination.path)} && document.readyState==='complete' && !!document.querySelector('[data-video-player]')`,'uncovered video-card navigation');
    report.linkCoverage={backgroundDoesNotCoverLink:true,actualMouseClick:true,path:destination.path};
    await navigate('/guidelines',"!!document.querySelector('#main h1')");
    const guidelines=await evaluate("({homeClass:document.body.classList.contains('home-page'),clouds:!!document.querySelector('.home-sky'),mascot:!!document.querySelector('.analista-mascot'),mascotStyles:Array.from(document.querySelectorAll('link[rel=stylesheet]')).some(link=>new URL(link.href).pathname.endsWith('/assets/mascot.css')),homeStyles:Array.from(document.querySelectorAll('link[rel=stylesheet]')).some(link=>new URL(link.href).pathname.endsWith('/assets/home.css'))})");
    if(guidelines.homeClass||guidelines.clouds||guidelines.mascot||guidelines.mascotStyles||guidelines.homeStyles) throw new Error('Homepage mascot/gradient assets leaked onto Guidelines.');
    report.guidelinesScope={...guidelines,passed:true};report.interceptErrors=interceptErrors;
    if(report.runtimeErrors.length||report.httpErrors.length||report.rejectedMethods.length||report.blockedExternalRequests.length||interceptErrors.length) throw new Error('Browser reported an error, unexpected external request, or write request.');
    report.passed=true;console.log(JSON.stringify({passed:true,layouts:report.checks.length,animatedClouds:8,animationProof:true,reducedMotion:true,publicGetOnly:true,externalNetworkAllowed:false,databaseWritesRequested:false}));
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
        if(fs.lstatSync(profile).isSymbolicLink()||!/^home-mascot-browser-profile-[a-f0-9]{16}$/.test(path.basename(profile))) throw new Error('Owned profile changed unexpectedly; recursive cleanup refused.');
        fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:100});
      } catch(error) {cleanupFailure=error;}
    }
    report.cleanup=cleanupFailure?{completed:false,reason:cleanupFailure.message}:{completed:true};
    const reportPath=path.join(output,'home-mascot-browser-results.json');inside(reportPath);fs.writeFileSync(reportPath,JSON.stringify(report,null,2));
    if(failure||cleanupFailure) throw failure??cleanupFailure;
  }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
