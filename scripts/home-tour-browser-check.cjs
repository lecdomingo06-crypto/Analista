// Public GET-only homepage tour QA: isolated file sessions, query-only SQLite, no fixtures.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),net=require('node:net');
const {spawn}=require('node:child_process');
const root=fs.realpathSync(path.resolve(__dirname,'..'));
const privateRoot=path.join(root,'storage','app','private'),output=path.join(root,'docs','preview');
const runId=crypto.randomBytes(8).toString('hex');
const owned=path.join(privateRoot,`home-tour-browser-${runId}`);
const profile=path.join(owned,'chrome'),sessions=path.join(owned,'sessions'),router=path.join(owned,'router.php');
const origin='http://127.0.0.1:8032',debuggerOrigin='http://127.0.0.1:9258';
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
  await freePort(8032);await freePort(9258);
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
  const environment={...process.env,ANALISTA_QA_ROOT:root,ANALISTA_QA_SESSION_FILES:sessions,APP_URL:origin,APP_DEBUG:'false',SESSION_DRIVER:'file',CACHE_STORE:'array',LOG_CHANNEL:'stderr',MAIL_MAILER:'array',SESSION_COOKIE:`analista_tour_qa_${runId}`,SESSION_DOMAIN:'null',SESSION_SECURE_COOKIE:'false'};
  server=spawn(process.env.PHP_BINARY??'php',['-S','127.0.0.1:8032','-t','public',router],{cwd:root,env:environment,windowsHide:true,stdio:['ignore','pipe','pipe']});
  for(const stream of[server.stdout,server.stderr])stream.on('data',chunk=>previewLog=(previewLog+chunk).slice(-3000));
  server.once('error',error=>server.qaSpawnError=error);await ready(origin+'/assets/favicon.svg',server);
  browser=spawn(chrome,['--headless=new','--remote-debugging-port=9258','--remote-debugging-address=127.0.0.1',`--user-data-dir=${profile}`,'--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-sync','--disable-extensions','about:blank'],{cwd:root,windowsHide:true,stdio:'ignore'});
  browser.once('error',error=>browser.qaSpawnError=error);await ready(debuggerOrigin+'/json/version',browser);
  console.log('QA: owned homepage tour preview and isolated file session ready');
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
  async function navigate(relative,expression="document.querySelector('[data-evaluator-deck]')?.dataset.deckReady==='true'"){await send('Page.navigate',{url:origin+relative});await wait(`location.href===${JSON.stringify(origin+relative)}&&document.readyState==='complete'&&(${expression})`,relative);await evaluate('document.fonts.ready.then(()=>{document.documentElement.style.scrollBehavior="auto";return true;})');await sleep(100);}
  async function viewport(width){await send('Emulation.setDeviceMetricsOverride',{width,height:width<=760?844:1000,deviceScaleFactor:1,mobile:width<=760});await sleep(250);}
  function assert(value,message){if(!value)throw Error(message);}
  const active="(()=>{const t=document.querySelector('[data-home-tour]');return !!t&&!t.hidden&&getComputedStyle(t).display!=='none'})()";
  const inactive=`!(${active})`;
  async function state(){return evaluate(`(()=>{const t=document.querySelector('[data-home-tour]'),b=document.querySelector('[data-tour-bubble]'),c=document.querySelector('[data-tour-cursor]'),r=b?.getBoundingClientRect(),s=c?.getBoundingClientRect();return{active:!!t&&!t.hidden&&getComputedStyle(t).display!=='none',text:document.querySelector('[data-tour-text]')?.textContent.trim(),announcement:document.querySelector('[data-tour-announcement]')?.textContent.trim(),bubble:b?.textContent.trim(),counter:document.querySelector('[data-tour-counter]')?.textContent.trim(),pauseLabel:document.querySelector('[data-tour-pause]')?.getAttribute('aria-label'),pauseText:document.querySelector('[data-tour-pause]')?.textContent.trim(),cursor:{left:s?.left,top:s?.top,transform:c&&getComputedStyle(c).transform,display:c&&getComputedStyle(c).display},rect:r&&{left:r.left,top:r.top,right:r.right,bottom:r.bottom,width:r.width,height:r.height},width:innerWidth,height:innerHeight,scrollY,scrollWidth:document.documentElement.scrollWidth,theme:document.documentElement.dataset.theme||'light',reduced:matchMedia('(prefers-reduced-motion:reduce)').matches,hover:matchMedia('(hover:hover)').matches,fine:matchMedia('(pointer:fine)').matches};})()`);}
  async function geometry(){return evaluate(`(()=>{const selectors=['#home-title','.home-hero','.home-community','.home-stats','.home-video-cards'];return selectors.map(selector=>{const r=document.querySelector(selector).getBoundingClientRect();return{selector,left:r.left+scrollX,top:r.top+scrollY,width:r.width,height:r.height};});})()`);}
  async function click(selector){await wait(`!!document.querySelector(${JSON.stringify(selector)})`,'click target '+selector);let previousRect,stable=0;for(let i=0;i<30&&stable<2;i++){const rect=await evaluate(`(()=>{const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return[r.left,r.top,r.width,r.height].map(n=>Math.round(n*10)/10).join(',')})()`);stable=rect===previousRect?stable+1:0;previousRect=rect;await sleep(40);}const p=await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)}),r=e.getBoundingClientRect();return{x:r.left+r.width/2,y:r.top+r.height/2,covered:!e.contains(document.elementFromPoint(r.left+r.width/2,r.top+r.height/2)),hit:document.elementFromPoint(r.left+r.width/2,r.top+r.height/2)?.outerHTML.slice(0,250)};})()`);assert(!p.covered,'Click target is covered: '+selector+' '+JSON.stringify(p));await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:p.x,y:p.y});await send('Input.dispatchMouseEvent',{type:'mousePressed',x:p.x,y:p.y,button:'left',clickCount:1});await send('Input.dispatchMouseEvent',{type:'mouseReleased',x:p.x,y:p.y,button:'left',clickCount:1});}
  async function key(key,code,n){await send('Input.dispatchKeyEvent',{type:'keyDown',key,code,windowsVirtualKeyCode:n});await send('Input.dispatchKeyEvent',{type:'keyUp',key,code,windowsVirtualKeyCode:n});}
  async function screenshot(name){const r=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});const dest=path.join(output,name);inside(dest);fs.writeFileSync(dest,Buffer.from(r.data,'base64'));}
  function validateBounds(s,label){assert(s.active,label+': tour inactive.');assert(s.scrollWidth<=s.width+1,label+': page horizontal overflow.');assert(s.rect&&s.rect.width>120&&s.rect.height>40,label+': bubble has no visible area.');assert(s.rect.left>=0&&s.rect.right<=s.width+1&&s.rect.top>=0&&s.rect.bottom<=s.height+1,label+': bubble clipped '+JSON.stringify(s.rect));assert(s.scrollY===0,label+': tour unexpectedly scrolled the page.');}
  async function pauseIfPlaying(){const p=await state();if(!/resume/i.test((p.pauseLabel||'')+' '+(p.pauseText||'')))await click('[data-tour-pause]');await wait("/Resume/.test(document.querySelector('[data-tour-pause]').textContent)",'pause control confirms');await sleep(100);}
  async function next(){const before=(await state()).counter;await click('[data-tour-next]');await wait(`document.querySelector('[data-tour-counter]')?.textContent.trim()!==${JSON.stringify(before)}`,'tour next step');await sleep(750);}
  async function inspect(label){const s=await state();validateBounds(s,label);report.checks.push({label,...s,passed:true});console.log('QA: '+label);return s;}
  async function replay(){await click('[data-tour-replay] [data-tour-start]');await wait(active,'replay starts tour');await sleep(750);}
  async function close(){await click('[data-tour-close]');await wait(inactive,'close tour');}
  await send('Page.enable');await send('Runtime.enable');await send('Network.enable');await send('Fetch.enable',{patterns:[{urlPattern:'*',requestStage:'Request'}]});
  if(!process.argv.includes('--responsive-only')){
  await viewport(1440);await navigate('/',"!!document.querySelector('[data-home-tour]')");
  const beforeAuto=await geometry();await wait(active,'desktop first-session auto start');
  const first=await state();validateBounds(first,'Desktop automatic welcome');
  const samples=[];for(let i=0;i<8;i++){samples.push(await state());await sleep(180);}
  report.welcomeSamples=samples;assert(samples.every(s=>s.announcement&&/^Step 1 of \d+\. .+/.test(s.announcement)),'Welcome has no accessible full text.');assert(new Set(samples.map(s=>s.text.length)).size>2,'Welcome typewriter did not advance.');assert(new Set(samples.map(s=>s.announcement)).size===1,'Live-region announcement changed per typed character.');
  assert(JSON.stringify(beforeAuto)===JSON.stringify(await geometry()),'Starting the tour shifted homepage layout.');
  assert(new Set(samples.map(s=>s.cursor.left+','+s.cursor.top+','+s.cursor.transform)).size>1,'Tour cursor did not visibly move.');
  await wait("document.querySelector('[data-tour-text]')?.textContent === document.querySelector('[data-tour-announcement]')?.textContent.replace(/^Step \\d+ of \\d+\\. /, '')",'complete welcome text');await inspect('Desktop first-session welcome');await screenshot('home-tour-desktop.png');
  const automaticCounter=(await state()).counter;await wait(`document.querySelector('[data-tour-counter]')?.textContent.trim()!==${JSON.stringify(automaticCounter)}`,'automatic next step');await sleep(750);await inspect('Desktop automatic next without hover');report.automaticProgression=true;
  await pauseIfPlaying();const paused=await state();await sleep(1800);assert((await state()).counter===paused.counter,'Pause did not stop automatic step advancement.');report.interactions.push({method:'Pause',passed:true});
  await next();const second=await inspect('Desktop manual next');await click('[data-tour-previous]');await wait(`document.querySelector('[data-tour-counter]')?.textContent.trim()!==${JSON.stringify(second.counter)}`,'previous step');assert((await state()).counter===paused.counter,'Previous did not return to the preceding step.');report.interactions.push({method:'Next and previous',passed:true});
  await key('Escape','Escape',27);await wait(inactive,'Escape dismisses tour');report.interactions.push({method:'Escape',passed:true});
  await send('Page.reload',{ignoreCache:true});await wait("document.readyState==='complete'&&!!document.querySelector('[data-home-tour]')",'same-session reload');await sleep(1800);assert(await evaluate(inactive),'Automatic tour repeated within the same session.');report.sessionAutoOnce=true;
  await replay();await pauseIfPlaying();await inspect('Desktop replay in same session');await close();
  await navigate('/guidelines',"!!document.querySelector('[data-tour-start]')");await click('[data-tour-start]');await wait("location.pathname==='/'&&document.readyState==='complete'",'sidebar tour link returns home');await wait(active,'cross-page replay starts');await pauseIfPlaying();await inspect('Replay from guidelines');
  // Every paused step must remain inside the viewport, without changing document geometry.
  const stepped=[];for(let i=0;i<12;i++){const s=await inspect('Desktop tour step '+(i+1));stepped.push(s.counter);const disabled=await evaluate("document.querySelector('[data-tour-next]').disabled");if(disabled||/finish|done/i.test(await evaluate("document.querySelector('[data-tour-next]').textContent")))break;await next();}
  report.desktopSteps=stepped;assert(new Set(stepped).size>=3,'Tour did not cover the main navigation.');assert(JSON.stringify(beforeAuto)===JSON.stringify(await geometry()),'Tour steps shifted document layout.');
  // Link clicks must pass through the non-modal tour layer.
  await click('.sidebar a[href$="/guidelines"]');await wait("location.pathname==='/guidelines'&&document.readyState==='complete'",'underlying navigation remains clickable');report.pageRemainsClickable=true;
  await navigate('/',"!!document.querySelector('[data-home-tour]')");await evaluate("document.querySelector('[data-theme-preference=dark]').click()");await wait("document.documentElement.dataset.theme==='dark'",'dark mode');await replay();await pauseIfPlaying();await inspect('Desktop dark tour');await screenshot('home-tour-dark.png');await viewport(1100);await sleep(750);await inspect('Active replay resized to 1100');await viewport(1440);await sleep(750);await inspect('Active replay resized back to 1440');await close();
  }else{
   const previous=JSON.parse(fs.readFileSync(path.join(output,'home-tour-browser-results.json'),'utf8'));
   assert(previous.checks.some(c=>c.label==='Active replay resized back to 1440')&&!previous.runtimeErrors.length&&!previous.httpErrors.length&&previous.cleanup?.completed,'Responsive-only follow-up requires the completed clean desktop run.');
   report.priorDesktopVerification={checks:previous.checks,interactions:previous.interactions,welcomeSamples:previous.welcomeSamples,automaticProgression:previous.automaticProgression,sessionAutoOnce:previous.sessionAutoOnce,pageRemainsClickable:previous.pageRemainsClickable,reason:'Desktop checks passed before correcting the mobile menu/replay overlap; only responsive follow-up repeated.'};
   await navigate('/guidelines',"!!document.querySelector('[data-tour-start]')");
  }
  for(const width of[390,320]){
   await viewport(width);await evaluate('sessionStorage.clear()');await navigate('/',"!!document.querySelector('[data-home-tour]')");await sleep(1200);assert(await evaluate(inactive),'Mobile tour started automatically.');
   await click('[data-menu]');await wait("document.querySelector('[data-menu]').getAttribute('aria-expanded')==='true'",'mobile menu opens');await click('[data-tour-start]');await wait(active,'mobile explicit start');await sleep(200);const initial=await inspect('Mobile '+width+' explicit tour');await sleep(1800);assert((await state()).counter===initial.counter,'Mobile tour advanced without Next.');
   if(width===390)await screenshot('home-tour-mobile.png');await next();await inspect('Mobile '+width+' next step');await close();
  }
  await viewport(1440);await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});await evaluate('sessionStorage.clear()');await navigate('/',"!!document.querySelector('[data-home-tour]')");await sleep(1400);assert(await evaluate(inactive),'Reduced-motion tour started automatically.');await replay();const reduced=await inspect('Reduced-motion explicit tour');await sleep(1000);const reducedAfter=await state();assert(reducedAfter.counter===reduced.counter,'Reduced-motion tour advanced automatically.');assert(JSON.stringify(reduced.cursor)===JSON.stringify(reducedAfter.cursor),'Reduced-motion cursor moved.');report.reducedMotion={manual:true,cursorStatic:true,passed:true};await screenshot('home-tour-reduced-motion.png');await next();await inspect('Reduced-motion manual next');await close();
  await click('[data-tour-dismiss]');assert(await evaluate("document.querySelector('[data-tour-replay]')?.hidden||getComputedStyle(document.querySelector('[data-tour-replay]')).display==='none'"),'Replay pill dismiss did not hide it.');report.replayPillDismissed=true;
  report.ownedSessionFiles=fs.readdirSync(sessions).filter(n=>/^[a-zA-Z0-9]+$/.test(n)).length;assert(report.ownedSessionFiles>0,'No isolated session file created.');
  report.interceptErrors=interceptErrors;if(report.runtimeErrors.length||report.httpErrors.length||report.blockedExternalRequests.length||report.rejectedMethods.length||interceptErrors.length)throw Error('Browser reported an unexpected error/network/write request.');
  report.passed=true;console.log(JSON.stringify({passed:true,checks:report.checks.length,interactions:report.interactions.length,autoOnce:true,sqliteQueryOnly:true}));await send('Browser.close').catch(()=>{});
 }catch(error){failure=error;report.passed=false;report.failure=error.message;if(!report.checks.length&&previewLog)report.previewStartupLog=previewLog;}
 finally{
  socket?.close();let cleanupFailure;for(const child of[browser,server])try{await stop(child);}catch(e){cleanupFailure??=e;}
  if(!cleanupFailure&&created)try{inside(owned,privateRoot);if(fs.lstatSync(owned).isSymbolicLink()||!/^home-tour-browser-[a-f0-9]{16}$/.test(path.basename(owned)))throw Error('Owned directory changed; cleanup refused.');fs.rmSync(owned,{recursive:true,force:true,maxRetries:5,retryDelay:100});}catch(e){cleanupFailure=e;}
  report.cleanup=cleanupFailure?{completed:false,reason:cleanupFailure.message}:{completed:true};const reportFile=path.join(output,'home-tour-browser-results.json');inside(reportFile);fs.writeFileSync(reportFile,JSON.stringify(report,null,2));if(failure||cleanupFailure)throw failure??cleanupFailure;
 }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
