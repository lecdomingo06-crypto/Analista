// Isolated guest/login/Home history reproduction. Never seeds or logs into the configured runtime DB.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, execFileSync } = require('node:child_process');
const root = fs.realpathSync(path.resolve(__dirname, '..'));
const privateRoot = path.join(root, 'storage', 'app', 'private');
const output = path.join(root, 'docs', 'preview');
const runId = crypto.randomBytes(8).toString('hex');
const database = path.join(privateRoot, `home-auth-qa-${runId}.sqlite`);
const profile = path.join(privateRoot, `home-auth-profile-${runId}`);
const seed = path.join(privateRoot, `home-auth-seed-${runId}.php`);
const origin = 'http://127.0.0.1:8008';
const debug = 'http://127.0.0.1:9234';
const email = 'home-auth-qa@example.test';
const password = crypto.randomBytes(30).toString('base64url');
const expectIdentityRefresh = process.env.QA_EXPECT_IDENTITY_REFRESH === '1';
const artifactLabel = expectIdentityRefresh ? 'fixed' : 'baseline';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const exited = child => child.exitCode !== null || child.signalCode !== null;
const report = { isolatedDatabase: true, syntheticAccounts: 1, expectIdentityRefresh, states: [], documentResponses: [], sessionChecks: [], backForwardCacheNotUsed: [] };
function inside(target, parent = root) {
  const relative = path.relative(fs.realpathSync(parent), path.resolve(target));
  if (!relative || relative.startsWith('..') || path.isAbsolute(relative)) throw new Error('QA target escaped its parent.');
  if (fs.existsSync(target)) {
    const actual = path.relative(fs.realpathSync(parent), fs.realpathSync(target));
    if (!actual || actual.startsWith('..') || path.isAbsolute(actual) || fs.lstatSync(target).isSymbolicLink()) throw new Error('QA target resolves outside its parent or is a link.');
  }
}
async function free(port) {
  await new Promise((resolve, reject) => { const server = net.createServer(); server.once('error', () => reject(new Error(`Port${port} is occupied; existing services remain untouched.`))); server.listen(port, '127.0.0.1', () => server.close(resolve)); });
}
async function ready(url, child) {
  const end = Date.now() + 20000;
  while (Date.now() < end) { if (exited(child)) throw new Error('Owned QA process exited.'); try { if ((await fetch(url, { signal: AbortSignal.timeout(1000) })).ok) return; } catch {} await sleep(100); }
  throw new Error('Owned QA service startup timed out.');
}
async function stop(child) {
  if (!child || exited(child)) return;
  child.kill();
  for (let i = 0; i < 60 && !exited(child); i++) await sleep(100);
  if (!exited(child)) throw new Error('Owned process still runs; its private files were retained.');
}
(async () => {
  let server, browser, socket, failure;
  let createdDatabase = false, createdSeed = false, createdProfile = false;
  try {
    fs.mkdirSync(privateRoot, { recursive: true }); fs.mkdirSync(output, { recursive: true });
    [database, profile, seed].forEach(target => inside(target, privateRoot));
    if ([database, profile, seed].some(target => fs.existsSync(target))) throw new Error('A new QA path already exists.');
    if (fs.existsSync(path.join(root, 'bootstrap', 'cache', 'config.php'))) throw new Error('Cached configuration would defeat QA isolation; no cache was changed.');
    await free(8008); await free(9234);
    const chrome = [process.env.CHROME_PATH, 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'].find(candidate => candidate && fs.existsSync(candidate));
    if (!chrome) throw new Error('Installed Chrome unavailable.');
    const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: `base64:${crypto.randomBytes(32).toString('base64')}`, DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '', SESSION_DRIVER: 'database', SESSION_COOKIE: `analista_home_auth_${runId}`, SESSION_DOMAIN: 'null', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'array', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr', BCRYPT_ROUNDS: '4', QA_DATABASE: database, QA_PRIVATE_ROOT: privateRoot, QA_PASSWORD: password };
    fs.writeFileSync(database, '', { flag: 'wx' }); createdDatabase = true;
    fs.mkdirSync(profile); createdProfile = true;
    const phpRoot = root.replaceAll('\\', '/').replaceAll("'", "\\'");
    fs.writeFileSync(seed, `<?php
require '${phpRoot}/vendor/autoload.php';
$app = require '${phpRoot}/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$expected=realpath(getenv('QA_DATABASE'));$actual=realpath(Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName());$private=realpath(getenv('QA_PRIVATE_ROOT'));
if (!app()->environment('testing') || config('database.default')!=='sqlite' || !$expected || strcasecmp($actual,$expected)!==0 || !str_starts_with(strtolower($actual),strtolower($private.DIRECTORY_SEPARATOR)) || !preg_match('/^home-auth-qa-[a-f0-9]{16}[.]sqlite$/',basename($actual)) || config('app.url')!=='${origin}') throw new RuntimeException('Refusing to seed outside bound QA database.');
Illuminate\\Support\\Facades\\Artisan::call('migrate',['--force'=>true]);
config(['hashing.bcrypt.rounds'=>4]);
App\\Models\\User::create(['name'=>'QA Home Student','email'=>'${email}','password'=>getenv('QA_PASSWORD'),'role'=>'student','accepting_requests'=>false]);
echo 'ready';
`, { flag: 'wx' }); createdSeed = true;
    const php = process.env.PHP_BINARY ?? 'php';
    execFileSync(php, [seed], { cwd: root, env, windowsHide: true, timeout: 45000, stdio: ['ignore', 'pipe', 'pipe'] });
    console.log('QA: One synthetic student seeded in isolated database');
    server = spawn(php, ['-S', '127.0.0.1:8008', '-t', 'public', 'dev-server.php'], { cwd: root, env, windowsHide: true, stdio: 'ignore' });
    await ready(`${origin}/assets/favicon.svg`, server);
    browser = spawn(chrome, ['--headless=new','--remote-debugging-port=9234','--remote-debugging-address=127.0.0.1',`--user-data-dir=${profile}`,'--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-sync','--disable-extensions','about:blank'], { cwd: root, windowsHide: true, stdio: 'ignore' });
    await ready(`${debug}/json/version`, browser);
    const targets = await (await fetch(`${debug}/json/list`)).json();
    socket = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', () => reject(new Error('Owned Chrome connection failed.')), { once: true }); });
    let id = 0; const pending = new Map();
    socket.addEventListener('message', ({data}) => {
      const packet=JSON.parse(data);
      if (packet.id && pending.has(packet.id)) { const task=pending.get(packet.id);pending.delete(packet.id);packet.error?task.reject(new Error(packet.error.message)):task.resolve(packet.result); }
      if (packet.method==='Network.responseReceived' && packet.params.type==='Document') {
        const response=packet.params.response;
        const header = name => Object.entries(response.headers).find(([key])=>key.toLowerCase()===name)?.[1] ?? null;
        report.documentResponses.push({path:new URL(response.url).pathname,status:response.status,cacheControl:header('cache-control'),pragma:header('pragma'),vary:header('vary'),fromDiskCache:!!response.fromDiskCache});
      }
      if(packet.method==='Network.responseReceived'&&new URL(packet.params.response.url).pathname==='/session-status')report.sessionChecks.push({status:packet.params.response.status});
      if (packet.method==='Page.backForwardCacheNotUsed') report.backForwardCacheNotUsed.push(packet.params.notRestoredExplanations);
    });
    function send(method,params={}) {
      return new Promise((resolve,reject)=>{const next=++id;const timeout=setTimeout(()=>{pending.delete(next);reject(new Error(`CDP timeout:${method}`));},method==='Page.navigate'?30000:12000);pending.set(next,{resolve:value=>{clearTimeout(timeout);resolve(value);},reject:error=>{clearTimeout(timeout);reject(error);}});socket.send(JSON.stringify({id:next,method,params}));});
    }
    async function evaluate(expression) { const result=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw new Error('Browser evaluation failed.');return result.result.value; }
    async function wait(expression,label) { const end=Date.now()+18000;while(Date.now()<end){try{if(await evaluate(expression))return;}catch{}await sleep(100);}throw new Error(`Timed out:${label}`); }
    async function navigate(route) {await send('Page.navigate',{url:origin+route});await wait(`location.pathname===${JSON.stringify(route)} && document.readyState==='complete' && !!document.querySelector('#main')`,route);}
    async function inspect(label) {
      const state=await evaluate(`(()=>{const links=Array.from(document.querySelectorAll('.sidebar nav a'));const workspace=links.find(a=>new URL(a.href).pathname==='/account');const rect=workspace?.getBoundingClientRect();return{path:location.pathname,workspaceDom:!!workspace,workspaceLabel:Array.from(document.querySelectorAll('.nav-label')).some(p=>p.textContent.trim()==='YOUR WORKSPACE'),workspaceStyle:workspace?{display:getComputedStyle(workspace).display,visibility:getComputedStyle(workspace).visibility,opacity:getComputedStyle(workspace).opacity,rect:{top:rect.top,bottom:rect.bottom,width:rect.width,height:rect.height}}:null,accountChip:document.querySelector('.account-chip')?.innerText??null,guestSignIn:!!document.querySelector('.sidebar-login'),guestJoin:!!Array.from(document.querySelectorAll('.sidebar a')).find(a=>a.textContent.includes('Join the community')),sidebarScrollTop:document.querySelector('.sidebar').scrollTop,pageshow:window.__homeAuthPageShows??[],navigationType:performance.getEntriesByType('navigation')[0]?.type};})()`);
      report.states.push({label,...state});console.log(JSON.stringify({stage:label,path:state.path,workspaceDom:state.workspaceDom,accountChip:!!state.accountChip,persisted:state.pageshow.at(-1)?.persisted}));return state;
    }
    async function clickSidebar(destination) {await evaluate(`Array.from(document.querySelectorAll('.sidebar nav a')).find(a=>new URL(a.href).pathname===${JSON.stringify(destination)}).click()`);await wait(`location.pathname===${JSON.stringify(destination)} && document.readyState==='complete' && !!document.querySelector('#main')`,destination);}
    async function shot(name) {const result=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});const target=path.join(output,name);inside(target);fs.writeFileSync(target,Buffer.from(result.data,'base64'));}
    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
    await send('Page.addScriptToEvaluateOnNewDocument',{source:`window.__homeAuthPageShows=[];addEventListener('pageshow',event=>window.__homeAuthPageShows.push({persisted:event.persisted,at:Date.now()}));`});
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await navigate('/');await inspect('Original guest Home');
    const history=await send('Page.getNavigationHistory');const originalGuestId=history.entries[history.currentIndex].id;
    await evaluate(`document.querySelector('.sidebar-login').click()`);await wait(`location.pathname==='/login' && document.readyState==='complete'`, 'login form');
    await evaluate(`document.querySelector('[name=email]').value=${JSON.stringify(email)};document.querySelector('[name=password]').value=${JSON.stringify(password)};document.querySelector('.auth-container form').requestSubmit()`);
    await wait(`location.pathname.startsWith('/account') && document.readyState==='complete' && !!document.querySelector('.account-chip')`,'student login');
    await inspect('Authenticated workspace after login');
    await clickSidebar('/');await inspect('Fresh authenticated Home');await shot(`home-auth-${artifactLabel}-fresh-home.png`);
    await clickSidebar('/guidelines');await inspect('Authenticated Guidelines');
    await clickSidebar('/');await inspect('Home after Guidelines');
    await send('Page.navigateToHistoryEntry',{entryId:originalGuestId});
    await wait(`location.pathname==='/' && document.readyState==='complete' && !!document.querySelector('#main')`,'original guest history entry');
    if(expectIdentityRefresh)await wait(`!!document.querySelector('.account-chip')`,'authenticated identity refreshed after history');
    await sleep(500);const restored=await inspect('Original guest Home restored from history');await shot(`home-auth-${artifactLabel}-history-home.png`);
    report.staleGuestRestored=!restored.workspaceDom&&restored.guestSignIn;
    await send('Page.reload');await wait(`location.pathname==='/' && document.readyState==='complete' && !!document.querySelector('.account-chip')`,'fresh authenticated reload');await inspect('Reload after history restoration');
    await clickSidebar('/guidelines');await inspect('Authenticated Guidelines before logout');
    const authenticatedHistory=await send('Page.getNavigationHistory');const authenticatedGuidelinesId=authenticatedHistory.entries[authenticatedHistory.currentIndex].id;
    await clickSidebar('/');
    const beforeSameIdentity=report.documentResponses.length;
    await send('Page.navigateToHistoryEntry',{entryId:authenticatedGuidelinesId});await wait(`location.pathname==='/guidelines' && document.readyState==='complete'`,'same-identity Guidelines history');await sleep(700);
    const unchanged=await inspect('Guidelines history with unchanged identity');report.sameIdentityHistory={persisted:unchanged.pageshow.at(-1)?.persisted,workspaceDom:unchanged.workspaceDom,documentRequests:report.documentResponses.length-beforeSameIdentity};
    await clickSidebar('/');
    await evaluate(`Array.from(document.querySelectorAll('.sidebar form')).find(form=>new URL(form.action).pathname==='/logout').requestSubmit()`);
    await wait(`location.pathname==='/' && document.readyState==='complete' && !!document.querySelector('.sidebar-login')`,'guest Home after actual logout');await inspect('Fresh guest Home after logout');
    await send('Page.navigateToHistoryEntry',{entryId:authenticatedGuidelinesId});
    await wait(`location.pathname==='/guidelines' && document.readyState==='complete' && !!document.querySelector('#main')`,'authenticated Guidelines history entry');
    if(expectIdentityRefresh)await wait(`!!document.querySelector('.sidebar-login')`,'guest identity refreshed after history');
    await sleep(500);const loggedOutRestored=await inspect('Authenticated Guidelines restored after logout');await shot(`home-auth-${artifactLabel}-history-guidelines.png`);
    report.staleAuthenticatedRestored=loggedOutRestored.workspaceDom&&!!loggedOutRestored.accountChip;
    if(expectIdentityRefresh&&(report.staleGuestRestored||report.staleAuthenticatedRestored))throw new Error('History restored an outdated session identity.');
    report.completed=true;
    await send('Browser.close').catch(()=>{});
  } catch(error) {failure=error;report.completed=false;report.failure=String(error.message).replaceAll(password,'[redacted]');}
  finally {
    socket?.close();let cleanupFailure;
    for(const child of[browser,server]){try{await stop(child);}catch(error){cleanupFailure??=error;}}
    if(!cleanupFailure){try{
      if(createdSeed){inside(seed,privateRoot);fs.unlinkSync(seed);}
      if(createdDatabase)for(const suffix of['','-journal','-wal','-shm']){const target=database+suffix;inside(target,privateRoot);if(fs.existsSync(target))fs.unlinkSync(target);}
      if(createdProfile){inside(profile,privateRoot);if(!/^home-auth-profile-[a-f0-9]{16}$/.test(path.basename(profile)))throw new Error('Unexpected profile name; cleanup refused.');fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:100});}
    }catch(error){cleanupFailure=error;}}
    report.cleanup=cleanupFailure?{completed:false,reason:cleanupFailure.message}:{completed:true};
    const target=path.join(output,`home-auth-browser-${artifactLabel}-results.json`);inside(target);fs.writeFileSync(target,JSON.stringify(report,null,2));
    if(failure||cleanupFailure)throw failure??cleanupFailure;
    console.log(JSON.stringify({completed:true,staleGuestRestored:report.staleGuestRestored,staleAuthenticatedRestored:report.staleAuthenticatedRestored,states:report.states.length,cleanup:report.cleanup.completed}));
  }
})().catch(error=>{console.error(String(error.message).replaceAll(password,'[redacted]'));process.exitCode=1;});
