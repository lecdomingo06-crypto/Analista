// Sidebar fit QA. Three synthetic role fixtures exist only in a new, bound SQLite DB.
// No configured runtime user/data/session is read or changed by this script.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, execFileSync } = require('node:child_process');
const root = fs.realpathSync(path.resolve(__dirname, '..'));
const privateRoot = path.join(root, 'storage', 'app', 'private');
const output = path.join(root, 'docs', 'preview');
const runId = crypto.randomBytes(8).toString('hex');
const database = path.join(privateRoot, `sidebar-fit-qa-${runId}.sqlite`);
const profile = path.join(privateRoot, `sidebar-fit-profile-${runId}`);
const seed = path.join(privateRoot, `sidebar-fit-seed-${runId}.php`);
const origin = 'http://127.0.0.1:8012';
const debug = 'http://127.0.0.1:9238';
const password = crypto.randomBytes(30).toString('base64url');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const exited = child => child.exitCode !== null || child.signalCode !== null;
const report = { isolatedDatabase: true, syntheticAccounts: 3, configuredRuntimeDataUntouched: true, checks: [], scrolling: [], navReachability: [], runtimeErrors: [], httpErrors: [], rejectedRequests: [] };
function inside(target, parent = root) {
  const relative = path.relative(fs.realpathSync(parent), path.resolve(target));
  if (!relative || relative.startsWith('..') || path.isAbsolute(relative)) throw new Error('QA target escaped its parent.');
  if (fs.existsSync(target)) {
    const actual = path.relative(fs.realpathSync(parent), fs.realpathSync(target));
    if (!actual || actual.startsWith('..') || path.isAbsolute(actual) || fs.lstatSync(target).isSymbolicLink()) throw new Error('QA target resolves outside its parent or is a link.');
  }
}
async function free(port) {
  await new Promise((resolve, reject) => { const server=net.createServer();server.once('error',()=>reject(new Error(`Port ${port} is occupied; existing services remain untouched.`)));server.listen(port,'127.0.0.1',()=>server.close(resolve)); });
}
async function ready(url, child) {
  const end=Date.now()+20000;
  while(Date.now()<end){if(exited(child))throw new Error('Owned QA process exited.');try{if((await fetch(url,{signal:AbortSignal.timeout(1000)})).ok)return;}catch{}await sleep(100);}
  throw new Error('Owned QA service startup timed out.');
}
async function stop(child) {
  if(!child||exited(child))return;child.kill();for(let i=0;i<60&&!exited(child);i++)await sleep(100);if(!exited(child))throw new Error('Owned process still runs; private files retained.');
}
(async()=>{
  let server,browser,socket,failure;
  let createdDatabase=false,createdSeed=false,createdProfile=false;
  try{
    fs.mkdirSync(privateRoot,{recursive:true});fs.mkdirSync(output,{recursive:true});
    [database,profile,seed].forEach(target=>inside(target,privateRoot));
    if([database,profile,seed].some(target=>fs.existsSync(target)))throw new Error('A new QA path already exists.');
    if(fs.existsSync(path.join(root,'bootstrap','cache','config.php')))throw new Error('Cached configuration defeats QA isolation; no cache was changed.');
    await free(8012);await free(9238);
    const chrome=[process.env.CHROME_PATH,'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe','C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'].find(candidate=>candidate&&fs.existsSync(candidate));
    if(!chrome)throw new Error('Installed Chrome unavailable.');
    const env={...process.env,APP_ENV:'testing',APP_DEBUG:'false',APP_URL:origin,APP_KEY:`base64:${crypto.randomBytes(32).toString('base64')}`,DB_CONNECTION:'sqlite',DB_DATABASE:database,DB_URL:'',SESSION_DRIVER:'database',SESSION_COOKIE:`analista_sidebar_fit_${runId}`,SESSION_DOMAIN:'null',SESSION_SECURE_COOKIE:'false',CACHE_STORE:'array',MAIL_MAILER:'array',LOG_CHANNEL:'stderr',BCRYPT_ROUNDS:'4',QA_DATABASE:database,QA_PRIVATE_ROOT:privateRoot,QA_PASSWORD:password};
    fs.writeFileSync(database,'',{flag:'wx'});createdDatabase=true;fs.mkdirSync(profile);createdProfile=true;
    const phpRoot=root.replaceAll('\\','/').replaceAll("'","\\'");
    fs.writeFileSync(seed,`<?php
require '${phpRoot}/vendor/autoload.php';
$app=require '${phpRoot}/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$expected=realpath(getenv('QA_DATABASE'));$actual=realpath(Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName());$private=realpath(getenv('QA_PRIVATE_ROOT'));
if(!app()->environment('testing')||config('database.default')!=='sqlite'||!$expected||strcasecmp($actual,$expected)!==0||!str_starts_with(strtolower($actual),strtolower($private.DIRECTORY_SEPARATOR))||!preg_match('/^sidebar-fit-qa-[a-f0-9]{16}[.]sqlite$/',basename($actual))||config('app.url')!=='${origin}')throw new RuntimeException('Refusing to seed outside bound QA database.');
Illuminate\\Support\\Facades\\Artisan::call('migrate',['--force'=>true]);config(['hashing.bcrypt.rounds'=>4]);
foreach(['student','evaluator','admin'] as $role)App\\Models\\User::create(['name'=>'QA Sidebar '.ucfirst($role),'email'=>'sidebar-fit-'.$role.'@example.test','password'=>getenv('QA_PASSWORD'),'role'=>$role,'evaluator_status'=>$role==='evaluator'?'approved':'none','accepting_requests'=>false]);
echo 'ready';
`,{flag:'wx'});createdSeed=true;
    const php=process.env.PHP_BINARY??'php';execFileSync(php,[seed],{cwd:root,env,windowsHide:true,timeout:45000,stdio:['ignore','pipe','pipe']});
    console.log('QA: Three synthetic role users seeded in isolated SQLite database');
    server=spawn(php,['-S','127.0.0.1:8012','-t','public','dev-server.php'],{cwd:root,env,windowsHide:true,stdio:'ignore'});await ready(`${origin}/assets/favicon.svg`,server);
    browser=spawn(chrome,['--headless=new','--remote-debugging-port=9238','--remote-debugging-address=127.0.0.1',`--user-data-dir=${profile}`,'--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-sync','--disable-extensions','about:blank'],{cwd:root,windowsHide:true,stdio:'ignore'});await ready(`${debug}/json/version`,browser);
    const targets=await(await fetch(`${debug}/json/list`)).json();socket=new WebSocket(targets.find(target=>target.type==='page').webSocketDebuggerUrl);
    await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',()=>reject(new Error('Owned Chrome connection failed.')),{once:true});});
    let id=0;const pending=new Map();
    socket.addEventListener('message',({data})=>{
      const packet=JSON.parse(data);
      if(packet.id&&pending.has(packet.id)){const task=pending.get(packet.id);pending.delete(packet.id);packet.error?task.reject(new Error(packet.error.message)):task.resolve(packet.result);}
      if(packet.method==='Runtime.exceptionThrown')report.runtimeErrors.push(packet.params.exceptionDetails.text);
      if(packet.method==='Network.responseReceived'&&packet.params.response.status>=400)report.httpErrors.push({path:new URL(packet.params.response.url).pathname,status:packet.params.response.status});
      if(packet.method==='Fetch.requestPaused'){
        const request=packet.params.request;const url=new URL(request.url);const allowed=url.origin===origin&&(request.method==='GET'||request.method==='POST'&&['/login','/logout'].includes(url.pathname));
        if(!allowed){report.rejectedRequests.push({path:url.pathname,method:request.method});send('Fetch.failRequest',{requestId:packet.params.requestId,errorReason:'BlockedByClient'}).catch(()=>{});}else send('Fetch.continueRequest',{requestId:packet.params.requestId}).catch(()=>{});
      }
    });
    function send(method,params={}){return new Promise((resolve,reject)=>{const next=++id;const timer=setTimeout(()=>{pending.delete(next);reject(new Error(`CDP timeout: ${method}`));},method==='Page.navigate'?30000:10000);pending.set(next,{resolve:value=>{clearTimeout(timer);resolve(value);},reject:error=>{clearTimeout(timer);reject(error);}});socket.send(JSON.stringify({id:next,method,params}));});}
    async function evaluate(expression){const result=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw new Error('Browser evaluation failed.');return result.result.value;}
    async function wait(expression,label){const end=Date.now()+18000;while(Date.now()<end){try{if(await evaluate(expression))return;}catch{}await sleep(100);}throw new Error(`Timed out: ${label}`);}
    async function navigate(route){await send('Page.navigate',{url:origin+route});await wait(`location.pathname===${JSON.stringify(route)}&&document.readyState==='complete'&&!!document.querySelector('#main')`,route);await evaluate('document.fonts.ready.then(()=>true)');}
    async function viewport(width,height){await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<761});await sleep(150);}
    async function menu(open){await evaluate(`if(document.body.classList.contains('menu-open')!==${open})document.querySelector('[data-menu]').click()`);await wait(`document.body.classList.contains('menu-open')===${open}`,'menu state');await sleep(230);}
    async function shot(name){const result=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});const target=path.join(output,name);inside(target);fs.writeFileSync(target,Buffer.from(result.data,'base64'));}
    async function geometry(){return evaluate(`(()=>{const box=node=>{const r=node.getBoundingClientRect();return{top:r.top,bottom:r.bottom,left:r.left,right:r.right,width:r.width,height:r.height};};const sidebar=document.querySelector('.sidebar');const nav=sidebar.querySelector('nav');const style=getComputedStyle(sidebar);const controls=Array.from(sidebar.querySelectorAll(':scope>.brand,.sidebar-bottom a[href],.sidebar-bottom button')).map(node=>({text:node.innerText.trim()||node.getAttribute('aria-label'),display:getComputedStyle(node).display,visibility:getComputedStyle(node).visibility,...box(node)}));return{width:innerWidth,height:innerHeight,scrollY,scrollWidth:document.documentElement.scrollWidth,documentHeight:document.documentElement.scrollHeight,sidebar:box(sidebar),brand:box(sidebar.querySelector(':scope>.brand')),footer:box(sidebar.querySelector('.sidebar-bottom')),scrollHeight:sidebar.scrollHeight,clientHeight:sidebar.clientHeight,scrollTop:sidebar.scrollTop,position:style.position,overflowY:style.overflowY,nav:{...box(nav),scrollTop:nav.scrollTop,scrollHeight:nav.scrollHeight,clientHeight:nav.clientHeight,overflowY:getComputedStyle(nav).overflowY},navFont:getComputedStyle(sidebar.querySelector('.nav-link')).fontSize,navRows:sidebar.querySelectorAll('.nav-link').length,controls,theme:document.documentElement.dataset.theme||'light',accountRole:sidebar.querySelector('.account-chip small')?.textContent.trim()??'guest'};})()`);}
    async function inspect(role,width,height,route,theme='light'){
      const state=await geometry();
      const errors=[];
      if(state.width!==width||state.height!==height||state.scrollWidth>width+1)errors.push('viewport mismatch or horizontal overflow');
      if(state.accountRole.toLowerCase()!==role)errors.push('wrong role/sidebar variant');
      if(['auto','scroll'].includes(state.overflowY))errors.push('sidebar creates an independent scrolling region');
      if(state.position!=='fixed'||state.sidebar.top<-.6||state.sidebar.bottom>height+.6)errors.push('sidebar is not fixed to viewport');
      if(state.scrollHeight>state.clientHeight+1||state.scrollTop!==0)errors.push('whole sidebar overflows or scrolls');
      if(!['auto','scroll'].includes(state.nav.overflowY)||state.nav.height<1)errors.push('navigation is not a bounded scrolling region');
      if(state.nav.top<state.brand.bottom-.6||state.nav.bottom>state.footer.top+.6)errors.push('navigation overlaps fixed brand/footer');
      for(const control of state.controls)if(control.display==='none'||control.visibility==='hidden'||control.width<1||control.height<1||control.top<-.6||control.bottom>height+.6)errors.push(`unreachable fixed sidebar control: ${control.text}`);
      for(let index=1;index<state.controls.length;index++)if(state.controls[index].top<state.controls[index-1].bottom-.6)errors.push(`overlapping sidebar controls: ${state.controls[index-1].text} / ${state.controls[index].text}`);
      if(height===900&&state.nav.scrollHeight>state.nav.clientHeight+1)errors.push('roomy viewport unnecessarily scrolls navigation');
      const row={role,width,height,route,theme,...state,passed:errors.length===0,errors};report.checks.push(row);
      console.log(JSON.stringify({role,width,height,route,navScrollHeight:state.nav.scrollHeight,navClientHeight:state.nav.clientHeight,navFont:state.navFont,passed:row.passed,errors}));
      if(errors.length){await shot(`sidebar-fit-failure-${role}-${width}x${height}.png`);throw new Error(`${role} ${width}x${height} ${route}: ${errors.join('; ')}`);}
      return state;
    }
    function fixedPositionsEqual(before,after){return ['brand','footer','sidebar'].every(part=>Math.abs(before[part].top-after[part].top)<.6&&Math.abs(before[part].bottom-after[part].bottom)<.6)&&after.scrollTop===0;}
    async function wheel(x,y,deltaY){await send('Input.dispatchMouseEvent',{type:'mouseWheel',x,y,deltaX:0,deltaY});await sleep(220);}
    async function checkNavScroll(role,width,height){
      await evaluate(`scrollTo(0,0);document.querySelector('.sidebar>nav').scrollTop=0`);
      const before=await geometry();const nav=before.nav;const overflowing=nav.scrollHeight>nav.clientHeight+1;
      await wheel(nav.left+nav.width/2,nav.top+nav.height/2,250);
      const after=await geometry();
      if(!fixedPositionsEqual(before,after)||after.scrollY!==0||(overflowing?after.nav.scrollTop<1:after.nav.scrollTop!==0))throw new Error(`${role} ${width}x${height}: wheel did not scroll only the middle navigation`);
      if(overflowing){
        await evaluate(`document.querySelector('.sidebar>nav').scrollTop=100000`);await wheel(nav.left+nav.width/2,nav.top+nav.height/2,400);
        const boundary=await geometry();if(!fixedPositionsEqual(before,boundary)||boundary.scrollY!==0)throw new Error(`${role} ${width}x${height}: wheel chained beyond navigation boundary`);
      }
      for(let index=0;index<before.navRows;index++){
        await evaluate(`document.querySelectorAll('.sidebar .nav-link')[${index}].scrollIntoView({block:'nearest',behavior:'instant'})`);await sleep(30);
        const state=await evaluate(`(()=>{const node=document.querySelectorAll('.sidebar .nav-link')[${index}];const nav=node.closest('nav').getBoundingClientRect();const rect=node.getBoundingClientRect();const hit=document.elementFromPoint(rect.left+rect.width/2,rect.top+rect.height/2);return{text:node.innerText.trim(),top:rect.top,bottom:rect.bottom,navTop:nav.top,navBottom:nav.bottom,hit:!!hit&&(hit===node||node.contains(hit))};})()`);
        report.navReachability.push({role,width,height,...state});if(state.top<state.navTop-.6||state.bottom>state.navBottom+.6||!state.hit)throw new Error(`${role} ${width}x${height}: navigation link unreachable: ${state.text}`);
      }
      const final=await geometry();if(!fixedPositionsEqual(before,final)||final.scrollY!==0)throw new Error(`${role} ${width}x${height}: reaching navigation links moved fixed sections or page`);
      report.scrolling.push({kind:'nav-only',role,width,height,overflowing,navScrollTop:after.nav.scrollTop,brandTop:after.brand.top,footerTop:after.footer.top,pageScrollY:after.scrollY});
      await evaluate(`document.querySelector('.sidebar>nav').scrollTop=0`);
    }
    async function checkPageScroll(role,width,height){
      if(width<761)await menu(false);
      await evaluate('scrollTo(0,0)');const before=await geometry();await wheel(width-80,Math.min(height-80,250),300);const after=await geometry();
      if(after.scrollY<1||!fixedPositionsEqual(before,after))throw new Error(`${role} ${width}x${height}: main page does not scroll with fixed sidebar`);
      report.scrolling.push({kind:'main-page',role,width,height,pageScrollY:after.scrollY,sidebarScrollTop:after.scrollTop});await evaluate('scrollTo(0,0)');
      if(width<761)await menu(true);
    }
    await send('Page.enable');await send('Runtime.enable');await send('Network.enable');await send('Fetch.enable',{patterns:[{urlPattern:'*',requestStage:'Request'}]});
    for(const role of ['guest','student','evaluator','admin']){
      await viewport(1440,900);
      if(role!=='guest'){
        await navigate('/login');await evaluate(`document.querySelector('[name=email]').value=${JSON.stringify('sidebar-fit-')}+${JSON.stringify(role)}+'@example.test';document.querySelector('[name=password]').value=${JSON.stringify(password)};document.querySelector('.auth-container form').requestSubmit()`);await wait(`!!document.querySelector('.account-chip')&&document.readyState==='complete'`,'role login');
      }
      const cases=[[1440,900,['/','/evaluators']],[1440,500,['/']],[390,500,['/']]];
      if(role==='guest')cases.push([1440,650,['/']]);
      if(role==='evaluator')cases.push([390,667,['/evaluators']]);
      if(role==='admin')cases.push([1440,650,['/evaluators']],[1440,400,['/']],[390,400,['/']]);
      for(const [width,height,routes]of cases){
        await viewport(width,height);
        for(const route of routes){
          await navigate(route);if(width<761)await menu(true);await inspect(role,width,height,route);
          await checkNavScroll(role,width,height);if(route==='/')await checkPageScroll(role,width,height);
          if((role==='admin'&&height===400)||(role==='evaluator'&&height===667)||(role==='guest'&&height===900)||(role==='admin'&&height===500))await shot(`sidebar-fit-${role}-${width}x${height}.png`);
          if(width<761)await menu(false);
        }
      }
      if(role==='admin'){
        await viewport(1440,650);await navigate('/guidelines');await evaluate(`document.querySelector('[data-theme-preference=dark]').click()`);await wait(`document.documentElement.dataset.theme==='dark'`,'dark theme');await inspect(role,1440,650,'/guidelines','dark');await shot('sidebar-fit-admin-dark.png');
      }
      if(role!=='admin'){
        if(role!=='guest'){await viewport(1440,900);await navigate('/');await evaluate(`Array.from(document.querySelectorAll('.sidebar form')).find(form=>new URL(form.action).pathname==='/logout').requestSubmit()`);await wait(`!!document.querySelector('.sidebar-login')&&document.readyState==='complete'`,'role logout');}
      }
    }
    if(report.runtimeErrors.length||report.httpErrors.length||report.rejectedRequests.length)throw new Error('Browser reported runtime/network errors or an unexpected request.');
    report.passed=true;await send('Browser.close').catch(()=>{});
  }catch(error){failure=error;report.passed=false;report.failure=String(error.message).replaceAll(password,'[redacted]');}
  finally{
    socket?.close();let cleanupFailure;for(const child of[browser,server]){try{await stop(child);}catch(error){cleanupFailure??=error;}}
    if(!cleanupFailure){try{if(createdSeed){inside(seed,privateRoot);fs.unlinkSync(seed);}if(createdDatabase)for(const suffix of['','-journal','-wal','-shm']){const target=database+suffix;inside(target,privateRoot);if(fs.existsSync(target))fs.unlinkSync(target);}if(createdProfile){inside(profile,privateRoot);if(!/^sidebar-fit-profile-[a-f0-9]{16}$/.test(path.basename(profile)))throw new Error('Unexpected profile name; cleanup refused.');fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:100});}}catch(error){cleanupFailure=error;}}
    report.cleanup=cleanupFailure?{completed:false,reason:cleanupFailure.message}:{completed:true};const target=path.join(output,'sidebar-fit-browser-results.json');inside(target);fs.writeFileSync(target,JSON.stringify(report,null,2));
    if(failure||cleanupFailure)throw failure??cleanupFailure;
    console.log(JSON.stringify({passed:report.passed,checks:report.checks.length,scrollingChecks:report.scrolling.length,cleanup:report.cleanup.completed}));
  }
})().catch(error=>{console.error(String(error.message).replaceAll(password,'[redacted]'));process.exitCode=1;});
