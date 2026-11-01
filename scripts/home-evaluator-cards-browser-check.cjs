// Public GET-only evaluator deck QA: isolated file sessions, query-only SQLite, no fixtures.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),net=require('node:net');
const {spawn}=require('node:child_process');
const root=fs.realpathSync(path.resolve(__dirname,'..'));
const privateRoot=path.join(root,'storage','app','private'),output=path.join(root,'docs','preview');
const runId=crypto.randomBytes(8).toString('hex');
const owned=path.join(privateRoot,`home-evaluator-cards-browser-${runId}`);
const profile=path.join(owned,'chrome'),sessions=path.join(owned,'sessions'),router=path.join(owned,'router.php');
const origin='http://127.0.0.1:8022',debuggerOrigin='http://127.0.0.1:9248';
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const report={publicGetOnly:true,fixturesCreated:false,authenticationUsed:false,databaseWritesRequested:false,sqliteQueryOnly:true,sessionDriver:'file (owned QA directory)',cacheStore:'array',externalNetworkAllowed:false,checks:[],interactions:[],runtimeErrors:[],httpErrors:[],blockedExternalRequests:[],rejectedMethods:[],thumbnailsStubbed:[]};
function inside(target,parent=root){const relative=path.relative(fs.realpathSync(parent),path.resolve(target));if(!relative||relative.startsWith('..')||path.isAbsolute(relative))throw Error('QA path escaped its parent.');if(fs.existsSync(target)){const actual=path.relative(fs.realpathSync(parent),fs.realpathSync(target));if(!actual||actual.startsWith('..')||path.isAbsolute(actual))throw Error('QA path resolves outside its parent.');}}
const exited=child=>!!child.qaSpawnError||child.exitCode!==null||child.signalCode!==null;
async function stop(child){if(!child||exited(child))return;child.kill();for(let i=0;i<60&&!exited(child);i++)await sleep(100);if(!exited(child))throw Error('Owned preview process did not exit.');}
async function freePort(port){await new Promise((resolve,reject)=>{const server=net.createServer();server.once('error',()=>reject(Error(`Port ${port} occupied; existing process left untouched.`)));server.listen(port,'127.0.0.1',()=>server.close(resolve));});}
async function ready(url,child){const end=Date.now()+20000;while(Date.now()<end){if(exited(child))throw child.qaSpawnError??Error('Owned process exited during startup.');try{if((await fetch(url,{signal:AbortSignal.timeout(1000)})).ok)return;}catch{}await sleep(150);}throw Error('Owned process startup timed out.');}
(async()=>{
 let server,browser,socket,failure,created=false,previewLog='';
 try{
  fs.mkdirSync(privateRoot,{recursive:true});fs.mkdirSync(output,{recursive:true});inside(owned,privateRoot);inside(output);
  if(fs.existsSync(owned)||fs.existsSync(path.join(root,'bootstrap','cache','config.php')))throw Error('QA requires a fresh owned directory and uncached configuration.');
  await freePort(8022);await freePort(9248);
  const chrome=[process.env.CHROME_PATH,'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe','C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'].find(p=>p&&fs.existsSync(p));if(!chrome)throw Error('Installed Chrome not found.');
  fs.mkdirSync(owned);created=true;fs.mkdirSync(profile);fs.mkdirSync(sessions);
  fs.writeFileSync(router,`<?php
$root = getenv('ANALISTA_QA_ROOT');
$publicRoot = realpath($root.'/public');
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$requestedFile = realpath($publicRoot.DIRECTORY_SEPARATOR.ltrim($requestPath, '/'));
if ($requestPath !== '/' && $requestedFile && str_starts_with($requestedFile, $publicRoot.DIRECTORY_SEPARATOR) && is_file($requestedFile)) return false;
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
define('LARAVEL_START', microtime(true));
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\\Illuminate\\Contracts\\Http\\Kernel::class)->bootstrap();
config(['session.driver'=>'file','session.files'=>getenv('ANALISTA_QA_SESSION_FILES'),'cache.default'=>'array']);
$connection = $app['db']->connection();
if ($connection->getDriverName() !== 'sqlite') throw new \\RuntimeException('SQLite required for query-only QA.');
$connection->statement('PRAGMA query_only = ON');
$app->handleRequest(\\Illuminate\\Http\\Request::capture());
`);
  const environment={...process.env,ANALISTA_QA_ROOT:root,ANALISTA_QA_SESSION_FILES:sessions,APP_URL:origin,APP_DEBUG:'false',SESSION_DRIVER:'file',CACHE_STORE:'array',LOG_CHANNEL:'stderr',MAIL_MAILER:'array',SESSION_COOKIE:`analista_evaluator_qa_${runId}`,SESSION_DOMAIN:'null',SESSION_SECURE_COOKIE:'false'};
  server=spawn(process.env.PHP_BINARY??'php',['-S','127.0.0.1:8022','-t','public',router],{cwd:root,env:environment,windowsHide:true,stdio:['ignore','pipe','pipe']});
  for(const stream of[server.stdout,server.stderr])stream.on('data',chunk=>previewLog=(previewLog+chunk).slice(-3000));
  server.once('error',error=>server.qaSpawnError=error);await ready(origin+'/assets/favicon.svg',server);
  browser=spawn(chrome,['--headless=new','--remote-debugging-port=9248','--remote-debugging-address=127.0.0.1',`--user-data-dir=${profile}`,'--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-sync','--disable-extensions','about:blank'],{cwd:root,windowsHide:true,stdio:'ignore'});
  browser.once('error',error=>browser.qaSpawnError=error);await ready(debuggerOrigin+'/json/version',browser);
  console.log('QA: owned evaluator-card preview and isolated file session ready');
  const targets=await(await fetch(debuggerOrigin+'/json/list')).json(),target=targets.find(t=>t.type==='page');if(!target)throw Error('Owned Chrome has no page target.');
  socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',()=>reject(Error('Chrome connection failed.')),{once:true});});
  let sequence=0;const pending=new Map(),interceptErrors=[];
  function send(method,params={}){return new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>{pending.delete(id);reject(Error('CDP timeout: '+method));},30000);pending.set(id,{resolve:value=>{clearTimeout(timer);resolve(value);},reject:error=>{clearTimeout(timer);reject(error);}});socket.send(JSON.stringify({id,method,params}));});}
  async function intercept({requestId,request}){
   const url=new URL(request.url);
   if(request.method!=='GET'){report.rejectedMethods.push({method:request.method,path:url.pathname});return send('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'});}
   if(url.origin===origin||url.protocol==='data:'||url.protocol==='about:')return send('Fetch.continueRequest',{requestId});
   if(url.hostname==='i.ytimg.com'&&/^\/vi\/[A-Za-z0-9_-]{11}\/(?:hqdefault|mqdefault|maxresdefault)\.jpg$/.test(url.pathname)){
    const videoId=url.pathname.split('/')[2],cached=path.join(root,'storage','app','tutorial-video-sources',`${videoId}.jpg`);inside(cached);
    const jpeg=fs.readFileSync(cached);if(jpeg[0]!==0xff||jpeg[1]!==0xd8||jpeg[2]!==0xff)throw Error('Cached thumbnail is not JPEG.');
    report.thumbnailsStubbed.push(videoId);return send('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'image/jpeg'}],body:jpeg.toString('base64')});
   }
   report.blockedExternalRequests.push(url.href);return send('Fetch.failRequest',{requestId,errorReason:'BlockedByClient'});
  }
  socket.addEventListener('message',({data})=>{const p=JSON.parse(data);if(p.id&&pending.has(p.id)){const c=pending.get(p.id);pending.delete(p.id);p.error?c.reject(Error(p.error.message)):c.resolve(p.result);}if(p.method==='Runtime.exceptionThrown')report.runtimeErrors.push(p.params.exceptionDetails.text);if(p.method==='Network.responseReceived'&&p.params.response.status>=400)report.httpErrors.push({path:new URL(p.params.response.url).pathname,status:p.params.response.status});if(p.method==='Fetch.requestPaused')intercept(p.params).catch(e=>interceptErrors.push(e.message));});
  async function evaluate(expression){const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error('Browser evaluation: '+r.exceptionDetails.text);return r.result.value;}
  async function wait(expression,label){const end=Date.now()+18000;while(Date.now()<end){try{if(await evaluate(expression))return;}catch{}await sleep(125);}throw Error('Timed out: '+label);}
  async function navigate(relative,expression="document.querySelector('[data-evaluator-deck]')?.dataset.deckReady==='true'"){await send('Page.navigate',{url:origin+relative});await wait(`location.href===${JSON.stringify(origin+relative)}&&document.readyState==='complete'&&(${expression})`,relative);await evaluate('document.fonts.ready.then(()=>{document.documentElement.style.scrollBehavior="auto";return true;})');await sleep(550);}
  async function viewport(width){await send('Emulation.setDeviceMetricsOverride',{width,height:width<=760?844:1000,deviceScaleFactor:1,mobile:width<=760});await sleep(250);}
  async function deckVisible(){await evaluate("document.querySelector('[data-evaluator-deck]').scrollIntoView({block:'center',behavior:'instant'})");await sleep(600);}
  async function screenshotSection(name){await deckVisible();const clip=await evaluate("(()=>{const r=document.querySelector('[data-evaluator-deck]').closest('.home-section').getBoundingClientRect(),x=Math.max(0,r.left+scrollX-70);return{x,y:Math.max(0,r.top+scrollY-12),width:Math.min(innerWidth-x,r.width+140),height:Math.min(1400,r.height+30),scale:1};})()");const r=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip});const destination=path.join(output,name);inside(destination);fs.writeFileSync(destination,Buffer.from(r.data,'base64'));}
  async function getCards(){return evaluate(`(()=>[...document.querySelectorAll('[data-evaluator-card]')].map(c=>{const r=c.getBoundingClientRect();return{name:c.dataset.evaluatorName,center:c.classList.contains('is-center'),left:c.classList.contains('is-left'),right:c.classList.contains('is-right'),role:c.getAttribute('role'),tabIndex:c.tabIndex,transform:getComputedStyle(c).transform,transition:getComputedStyle(c).transitionDuration,rect:{left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height},links:[...c.querySelectorAll('a')].map(a=>({path:new URL(a.href).pathname,tabindex:a.getAttribute('tabindex'),hidden:a.getAttribute('aria-hidden'),pointerEvents:getComputedStyle(a).pointerEvents}))};}))()`);}
  function validateCards(cards,label){
   if(cards.length!==3||new Set(cards.map(c=>c.name)).size!==3||cards.filter(c=>c.center).length!==1||cards.filter(c=>c.left).length!==1||cards.filter(c=>c.right).length!==1)throw Error(label+': expected three distinct center/left/right cards.');
   for(const c of cards){if(c.links.length!==2||!c.name||!c.links[0].path.startsWith('/evaluators/')||!c.links[1].path.startsWith('/messages/to/'))throw Error(label+': missing profile/message actions.');if(c.center){if(c.role!==null||c.links.some(a=>a.tabindex!==null||a.hidden!==null||a.pointerEvents==='none'))throw Error(label+': center actions unusable.');}else if(c.role!=='button'||c.tabIndex!==0||c.links.some(a=>a.tabindex!=='-1'||a.hidden!=='true'||a.pointerEvents!=='none'))throw Error(label+': inactive-card keyboard state incorrect.');}
  }
  async function inspect(label,width){
   await deckVisible();const cards=await getCards();validateCards(cards,label);
   const layout=await evaluate(`(()=>{const d=document.querySelector('[data-evaluator-deck]'),r=d.getBoundingClientRect();return{width:innerWidth,scrollWidth:document.documentElement.scrollWidth,deck:{left:r.left,right:r.right,top:r.top,bottom:r.bottom,height:r.height},theme:document.documentElement.dataset.theme||'light',noTopbar:!document.querySelector('.topbar'),guest:!document.querySelector('.sidebar a[href$="/account/profile"]'),followingSectionTop:d.closest('.home-section').nextElementSibling.getBoundingClientRect().top};})()`);
   if(layout.width!==width||layout.scrollWidth>width+1||!layout.noTopbar||!layout.guest)throw Error(label+': viewport, overflow or guest layout failed '+JSON.stringify(layout));
   const center=cards.find(c=>c.center);if(center.rect.left<0||center.rect.right>width+1||center.rect.width<250||layout.followingSectionTop<Math.max(...cards.map(c=>c.rect.bottom))-1)throw Error(label+': clipping or overlap '+JSON.stringify({cards,layout}));
   const paths=cards.map(c=>c.links[0].path);if(report.catalogPaths&&JSON.stringify(paths)!==JSON.stringify(report.catalogPaths))throw Error(label+': sample changed within the browser session.');report.catalogPaths??=paths;report.checks.push({label,...layout,cards,horizontalOverflow:false,passed:true});
  }
  async function activate(which,key){
   await deckVisible();const before=await getCards(),selected=before.find(c=>c[which]);await evaluate(`document.querySelector('[data-evaluator-card].is-${which}').focus({preventScroll:true})`);
   if(key){const args={key,code:key===' '?'Space':'Enter',windowsVirtualKeyCode:key===' '?32:13};await send('Input.dispatchKeyEvent',{type:'keyDown',...args});await send('Input.dispatchKeyEvent',{type:'keyUp',...args});}
   else{const point=await evaluate(`(()=>{const c=document.querySelector('[data-evaluator-card].is-${which}'),r=c.getBoundingClientRect();for(let y=Math.max(0,r.top+10);y<Math.min(innerHeight,r.bottom-10);y+=8)for(let x=Math.max(0,r.left+5);x<Math.min(innerWidth,r.right-5);x+=8)if(document.elementFromPoint(x,y)?.closest('[data-evaluator-card]')===c)return{x,y};return null;})()`);if(!point)throw Error('Side card has no uncovered click target.');await send('Input.dispatchMouseEvent',{type:'mouseMoved',...point});await send('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});await send('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});}
   await sleep(650);const after=await getCards();validateCards(after,'Activation '+(key??'pointer'));if(!(await evaluate("location.pathname==='/'"))||after.find(c=>c.center).name!==selected.name)throw Error('Activation navigated or did not change center.');report.interactions.push({method:key===' '?'Space':key??'click',name:selected.name,passed:true});
  }
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');await send('Fetch.enable',{patterns:[{urlPattern:'*',requestStage:'Request'}]});
  await viewport(1440);await navigate('/');if(await evaluate("document.documentElement.dataset.theme==='dark'"))await evaluate("document.querySelector('[data-theme-preference=light]').click()");
  await inspect('Desktop 1440 light',1440);await screenshotSection('home-evaluator-cards-desktop.png');
  await activate('left');await activate('right','Enter');await activate('left',' ');
  await send('Page.reload',{ignoreCache:true});await wait("document.readyState==='complete'&&document.querySelector('[data-evaluator-deck]')?.dataset.deckReady==='true'",'homepage reload');await sleep(600);await inspect('Desktop same-session refresh',1440);
  await navigate('/guidelines',"!!document.querySelector('#main h1')");await navigate('/');await inspect('Desktop same-session browse back',1440);
  await viewport(900);await inspect('Tablet 900 light',900);
  await viewport(800);await inspect('Narrow desktop 800 light',800);await activate('left');
  await viewport(761);await inspect('Sidebar breakpoint 761 light',761);await activate('right');
  await viewport(390);await inspect('Mobile 390 light',390);await screenshotSection('home-evaluator-cards-mobile.png');await activate('right');
  await viewport(320);await inspect('Mobile 320 light',320);
  await viewport(1440);await evaluate("document.querySelector('[data-theme-preference=dark]').click()");await wait("document.documentElement.dataset.theme==='dark'",'dark theme');await inspect('Desktop 1440 dark',1440);await screenshotSection('home-evaluator-cards-dark.png');
  await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});await inspect('Desktop reduced motion',1440);if((await getCards()).some(c=>c.transition.split(',').some(v=>parseFloat(v)>0)))throw Error('Transitions remain enabled in reduced motion.');report.reducedMotion=true;
  const center=(await getCards()).find(c=>c.center);await evaluate("document.querySelector('[data-evaluator-card].is-center .home-evaluator-actions a').click()");await wait(`location.pathname===${JSON.stringify(center.links[0].path)}&&document.readyState==='complete'`,'profile navigation');const visibleName=await evaluate("document.querySelector('#main h1')?.textContent.trim()");if(visibleName!==center.name)throw Error('Profile destination name mismatch.');report.profileNavigation={path:center.links[0].path,name:visibleName,passed:true};
  await navigate('/');await evaluate("document.querySelector('[data-evaluator-card].is-center .home-evaluator-actions a:nth-child(2)').click()");await wait("location.pathname==='/login'&&document.readyState==='complete'",'guest message login');report.guestMessageRequiresLogin=true;
  await navigate('/');await inspect('Same-session after profile and message actions',1440);report.ownedSessionFiles=fs.readdirSync(sessions).filter(n=>/^[a-zA-Z0-9]+$/.test(n)).length;if(report.ownedSessionFiles<1)throw Error('No isolated file session was created.');
  report.interceptErrors=interceptErrors;if(report.runtimeErrors.length||report.httpErrors.length||report.blockedExternalRequests.length||report.rejectedMethods.length||interceptErrors.length)throw Error('Browser reported an unexpected error/network/write request.');
  report.passed=true;console.log(JSON.stringify({passed:true,evaluators:3,checks:report.checks.length,interactions:report.interactions.length,sessionStable:true,sqliteQueryOnly:true}));await send('Browser.close').catch(()=>{});
 }catch(error){failure=error;report.passed=false;report.failure=error.message;if(!report.checks.length&&previewLog)report.previewStartupLog=previewLog;}
 finally{
  socket?.close();let cleanupFailure;for(const child of[browser,server])try{await stop(child);}catch(e){cleanupFailure??=e;}
  if(!cleanupFailure&&created)try{inside(owned,privateRoot);if(fs.lstatSync(owned).isSymbolicLink()||!/^home-evaluator-cards-browser-[a-f0-9]{16}$/.test(path.basename(owned)))throw Error('Owned directory changed; cleanup refused.');fs.rmSync(owned,{recursive:true,force:true,maxRetries:5,retryDelay:100});}catch(e){cleanupFailure=e;}
  report.cleanup=cleanupFailure?{completed:false,reason:cleanupFailure.message}:{completed:true};const reportFile=path.join(output,'home-evaluator-cards-browser-results.json');inside(reportFile);fs.writeFileSync(reportFile,JSON.stringify(report,null,2));if(failure||cleanupFailure)throw failure??cleanupFailure;
 }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
