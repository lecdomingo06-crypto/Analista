// Direct-message attachment QA. All accounts, files, sessions and caches belong
// to one fresh directory; the configured application database is never opened.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, execFileSync } = require('node:child_process');

const root = fs.realpathSync(path.resolve(__dirname, '..'));
const privateRoot = path.join(root, 'storage', 'app', 'private');
const output = path.join(root, 'docs', 'preview');
const runId = crypto.randomBytes(8).toString('hex');
const owned = path.join(privateRoot, `message-attachments-browser-${runId}`);
const database = path.join(owned, 'qa.sqlite');
const uploads = path.join(owned, 'upload-disk');
const profile = path.join(owned, 'chrome-profile');
const storage = path.join(owned, 'storage');
const seedScript = path.join(owned, 'seed.php');
const routerScript = path.join(owned, 'router.php');
const png = path.join(owned, 'photo.png');
const text = path.join(owned, 'research-notes.txt');
const origin = 'http://127.0.0.1:8065';
const debuggerOrigin = 'http://127.0.0.1:9295';
const password = crypto.randomBytes(30).toString('base64url');
const checks = [];
const errors = [];
const posts = [];
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let stage = 'Preparing private QA paths';
const progress = label => { stage = label; console.log(`QA: ${label}`); };
const redact = value => String(value?.message || value).replaceAll(password, '[redacted]');
const exited = child => child.exitCode !== null || child.signalCode !== null;
function inside(target, parent = root) {
  const relative = path.relative(fs.realpathSync(parent), path.resolve(target));
  if (!relative || relative.startsWith('..') || path.isAbsolute(relative)) throw new Error('QA path escaped its owned parent.');
  if (fs.existsSync(target)) {
    const actual = path.relative(fs.realpathSync(parent), fs.realpathSync(target));
    if (!actual || actual.startsWith('..') || path.isAbsolute(actual)) throw new Error('QA path resolves outside its owned parent.');
  }
}
async function freePort(port) {
  await new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', () => reject(new Error(`Port ${port} is occupied; existing services were left untouched.`)));
    server.listen(port, '127.0.0.1', () => server.close(resolve));
  });
}
async function ready(url, child) {
  for (let attempt = 0; attempt < 150; attempt++) {
    if (exited(child)) throw new Error('Owned QA service stopped during startup.');
    try { if ((await fetch(url, { signal: AbortSignal.timeout(800) })).ok) return; } catch {}
    await sleep(100);
  }
  throw new Error('Owned QA service did not start.');
}
async function stop(child) {
  if (!child || exited(child)) return;
  child.kill();
  for (let attempt = 0; attempt < 80 && !exited(child); attempt++) await sleep(100);
  if (!exited(child)) throw new Error('Owned service is still running; private artifacts were retained.');
}

(async () => {
  let server, browser, socket, created = false, failure;
  const report = { isolatedDatabase: true, isolatedUploads: true, isolatedSessionsAndCaches: true, externalRequestsBlocked: true, realCsrf: true, checks };
  try {
    fs.mkdirSync(privateRoot, { recursive: true }); fs.mkdirSync(output, { recursive: true });
    inside(owned, privateRoot); inside(output);
    if (fs.existsSync(owned)) throw new Error('Owned QA directory already exists.');
    await freePort(8065); await freePort(9295);
    const chrome = ['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'].find(file => fs.existsSync(file));
    if (!chrome) throw new Error('Installed Chrome was not found.');
    fs.mkdirSync(owned); created = true;
    for (const directory of [uploads, profile, storage, path.join(storage, 'framework', 'views'), path.join(storage, 'framework', 'sessions'), path.join(storage, 'framework', 'cache', 'data'), path.join(storage, 'logs'), path.join(owned, 'bootstrap')]) fs.mkdirSync(directory, { recursive: true });
    fs.writeFileSync(database, '', { flag: 'wx' });
    fs.writeFileSync(png, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6DhQAAAAASUVORK5CYII=', 'base64'), { flag: 'wx' });
    fs.writeFileSync(text, 'Isolated direct-message attachment notes.\n', { flag: 'wx' });
    const phpRoot = root.replaceAll('\\', '/').replaceAll("'", "\\'");
    const guard = `
$expected = realpath(getenv('QA_DATABASE'));
$actual = realpath(Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName());
$owned = realpath(getenv('QA_OWNED_ROOT'));
$disk = realpath(getenv('QA_LOCAL_ROOT'));
if (!app()->environment('testing') || config('database.default') !== 'sqlite'
    || !$expected || !$actual || strcasecmp($actual, $expected) !== 0
    || !$owned || !preg_match('/^message-attachments-browser-[a-f0-9]{16}$/', basename($owned))
    || strcasecmp(dirname($actual), $owned) !== 0 || basename($actual) !== 'qa.sqlite'
    || !$disk || strcasecmp(dirname($disk), $owned) !== 0 || basename($disk) !== 'upload-disk'
    || config('app.url') !== '${origin}') throw new RuntimeException('Refusing access outside isolated message attachment QA.');
config(['filesystems.disks.local.root' => $disk, 'filesystems.disks.public.root' => $disk, 'session.files' => $owned.'/storage/framework/sessions']);
`;
    const bootstrap = `require '${phpRoot}/vendor/autoload.php';
$app = require '${phpRoot}/bootstrap/app.php';
$app->useStoragePath(getenv('QA_STORAGE_PATH'));
`;
    fs.writeFileSync(seedScript, `<?php
${bootstrap}
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
${guard}
Illuminate\\Support\\Facades\\Artisan::call('migrate', ['--force' => true]);
config(['hashing.bcrypt.rounds' => 4]);
$student = App\\Models\\User::create(['name'=>'QA Attachments Student','email'=>'attachments-student@example.test','password'=>getenv('QA_PASSWORD'),'role'=>'student','accepting_requests'=>false]);
$evaluator = App\\Models\\User::create(['name'=>'QA Attachments Evaluator','email'=>'attachments-evaluator@example.test','password'=>getenv('QA_PASSWORD'),'role'=>'evaluator','evaluator_status'=>'approved','accepting_requests'=>true]);
echo json_encode(['student'=>$student->id,'evaluator'=>$evaluator->id]);
`, { flag: 'wx' });
    fs.writeFileSync(routerScript, `<?php
$publicRoot = realpath('${phpRoot}/public');
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = realpath($publicRoot.DIRECTORY_SEPARATOR.ltrim($requestPath, '/'));
if ($requestPath !== '/' && $file && str_starts_with($file, $publicRoot.DIRECTORY_SEPARATOR) && is_file($file)) return false;
define('LARAVEL_START', microtime(true));
${bootstrap}
$kernel = $app->make(Illuminate\\Contracts\\Http\\Kernel::class);
$kernel->bootstrap();
${guard}
$response = $kernel->handle($request = Illuminate\\Http\\Request::capture());
$response->send();
$kernel->terminate($request, $response);
`, { flag: 'wx' });
    const environment = {
      ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: `base64:${crypto.randomBytes(32).toString('base64')}`,
      APP_CONFIG_CACHE: path.relative(root, path.join(owned, 'bootstrap', 'config.php')), APP_ROUTES_CACHE: path.relative(root, path.join(owned, 'bootstrap', 'routes.php')),
      APP_EVENTS_CACHE: path.relative(root, path.join(owned, 'bootstrap', 'events.php')), APP_SERVICES_CACHE: path.relative(root, path.join(owned, 'bootstrap', 'services.php')), APP_PACKAGES_CACHE: path.relative(root, path.join(owned, 'bootstrap', 'packages.php')),
      VIEW_COMPILED_PATH: path.join(storage, 'framework', 'views'), DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '',
      SESSION_DRIVER: 'database', SESSION_COOKIE: `analista_attachment_qa_${runId}`, SESSION_DOMAIN: 'null', SESSION_SECURE_COOKIE: 'false',
      CACHE_STORE: 'array', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr', QUEUE_CONNECTION: 'sync', BCRYPT_ROUNDS: '4', ANALISTA_DEMO: 'false',
      GOOGLE_CLIENT_ID: '', GOOGLE_CLIENT_SECRET: '', GOOGLE_REDIRECT_URI: '', QA_DATABASE: database, QA_OWNED_ROOT: owned, QA_LOCAL_ROOT: uploads, QA_STORAGE_PATH: storage, QA_PASSWORD: password,
    };
    const php = 'C:\\xampp\\php\\php.exe';
    const seedOutput = execFileSync(php, [seedScript], { cwd: root, env: environment, windowsHide: true, encoding: 'utf8', timeout: 60000, stdio: ['ignore', 'pipe', 'pipe'] }).trim();
    if (!seedOutput.startsWith('{')) throw new Error(`Isolated fixture bootstrap failed: ${seedOutput.slice(0, 1200)}`);
    const ids = JSON.parse(seedOutput);
    progress('Fresh isolated database, uploads, sessions and caches ready');
    server = spawn(php, ['-S', '127.0.0.1:8065', '-t', 'public', routerScript], { cwd: root, env: environment, windowsHide: true, stdio: 'ignore' });
    await ready(`${origin}/login`, server);
    browser = spawn(chrome, ['--headless=new', '--remote-debugging-port=9295', '--remote-debugging-address=127.0.0.1', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-sync', '--disable-extensions', 'about:blank'], { cwd: root, windowsHide: true, stdio: 'ignore' });
    await ready(`${debuggerOrigin}/json/version`, browser);
    const version = await (await fetch(`${debuggerOrigin}/json/version`)).json();
    socket = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
    let sequence = 0;
    const pending = new Map(); const requests = new Map();
    function send(method, params = {}, sessionId) {
      return new Promise((resolve, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Browser command timed out: ${method}`)); }, 16000);
        pending.set(id, { resolve: value => { clearTimeout(timer); resolve(value); }, reject: error => { clearTimeout(timer); reject(error); } });
        socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
      });
    }
    socket.addEventListener('message', ({ data }) => {
      const packet = JSON.parse(data);
      if (packet.id && pending.has(packet.id)) { const callback = pending.get(packet.id); pending.delete(packet.id); packet.error ? callback.reject(new Error(packet.error.message)) : callback.resolve(packet.result); }
      if (packet.method === 'Runtime.exceptionThrown') errors.push(packet.params.exceptionDetails.text);
      if (packet.method === 'Network.requestWillBeSent' && packet.params.request.method === 'POST') requests.set(`${packet.sessionId}:${packet.params.requestId}`, { path: new URL(packet.params.request.url).pathname });
      if (packet.method === 'Network.responseReceived') {
        const key = `${packet.sessionId}:${packet.params.requestId}`;
        if (requests.has(key)) { posts.push({ ...requests.get(key), status: packet.params.response.status }); requests.delete(key); }
      }
      if (packet.method === 'Fetch.requestPaused') {
        const request = packet.params;
        send(request.request.url.startsWith(`${origin}/`) ? 'Fetch.continueRequest' : 'Fetch.failRequest', { requestId: request.requestId, ...(!request.request.url.startsWith(`${origin}/`) ? { errorReason: 'BlockedByClient' } : {}) }, packet.sessionId).catch(() => {});
      }
    });
    async function newPage() {
      const context = await send('Target.createBrowserContext');
      const target = await send('Target.createTarget', { url: 'about:blank', browserContextId: context.browserContextId });
      const { sessionId } = await send('Target.attachToTarget', { targetId: target.targetId, flatten: true });
      const page = { width: 1440, height: 1000, send: (method, params = {}) => send(method, params, sessionId) };
      for (const method of ['Page.enable', 'Runtime.enable', 'Network.enable']) await page.send(method);
      await page.send('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
      await page.send('Emulation.setFocusEmulationEnabled', { enabled: true });
      page.eval = async expression => {
        const result = await page.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw new Error(`Browser evaluation failed at ${stage}.`);
        return result.result.value;
      };
      page.wait = async (expression, description, timeout = 18000) => {
        const end = Date.now() + timeout;
        while (Date.now() < end) { try { if (await page.eval(expression)) return; } catch {} await sleep(125); }
        throw new Error(`Timed out waiting for ${description}.`);
      };
      page.navigate = async route => {
        await page.send('Page.navigate', { url: origin + route });
        await page.wait(`location.pathname===${JSON.stringify(route.split('?')[0])} && document.readyState==='complete' && !!document.querySelector('#main')`, 'page navigation');
      };
      page.viewport = async width => { page.width = width; page.height = width === 390 ? 844 : 1000; await page.send('Emulation.setDeviceMetricsOverride', { width, height: page.height, deviceScaleFactor: 1, mobile: width === 390 }); await sleep(200); };
      page.login = async email => {
        await page.navigate('/login');
        await page.eval(`document.querySelector('[name=email]').value=${JSON.stringify(email)};document.querySelector('[name=password]').value=${JSON.stringify(password)};document.querySelector('.registration-card').requestSubmit()`);
        await page.wait(`location.pathname!=='/login' && document.readyState==='complete' && !!document.querySelector('.account-chip')`, 'real local account login');
      };
      page.files = async filenames => {
        const { root: document } = await page.send('DOM.getDocument');
        const { nodeId } = await page.send('DOM.querySelector', { nodeId: document.nodeId, selector: '[data-message-files]' });
        await page.send('DOM.setFileInputFiles', { nodeId, files: filenames });
      };
      page.screenshot = async name => { const file = path.join(output, name); inside(file); const image = await page.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); fs.writeFileSync(file, Buffer.from(image.data, 'base64')); };
      page.layout = async label => {
        const geometry = await page.eval(`(()=>{const c=document.querySelector('.chat-composer').getBoundingClientRect();const t=document.querySelector('[data-message-thread]');return{width:innerWidth,height:innerHeight,scroll:document.documentElement.scrollWidth,outerScroll:document.documentElement.scrollHeight,composerBottom:c.bottom,composerTop:c.top,threadHeight:t.clientHeight,threadScroll:t.scrollHeight}})()`);
        if (geometry.scroll > page.width + 1 || geometry.composerBottom > page.height + 1 || geometry.composerTop < 0 || geometry.threadHeight < 60 || geometry.outerScroll > page.height + 1) throw new Error(`Composer layout overflow at ${label}: ${JSON.stringify(geometry)}`);
        checks.push({ check: label, horizontalOverflow: false, composerVisible: true, outerPageScroll: false });
      };
      await page.viewport(1440);
      return page;
    }
    const student = await newPage(); const evaluator = await newPage();
    await student.login('attachments-student@example.test'); await evaluator.login('attachments-evaluator@example.test');
    await student.navigate(`/messages/to/${ids.evaluator}`);
    await student.wait(`!!document.querySelector('.has-attachment-picker')`, 'attachment picker enhancement');
    checks.push({ check: 'Two isolated users sign in through real CSRF-protected local forms', passed: true });
    const csrf = await student.eval(`fetch(document.querySelector('[data-message-form]').action,{method:'POST',headers:{Accept:'application/json'},body:new URLSearchParams({_token:'invalid-qa-token',body:'Must not send'})}).then(response=>response.status)`);
    if (csrf !== 419) throw new Error('Real invalid CSRF token was not rejected.');
    checks.push({ check: 'Real browser requests enforce CSRF', passed: true });
    await student.files([png, text]);
    await student.wait(`document.querySelectorAll('[data-message-selected-file]').length===2 && !document.querySelector('.chat-send').disabled && document.querySelector('[data-message-files]').files.length===2`, 'two selected previews');
    await student.layout('Desktop selected photos and files');
    await student.eval(`document.querySelector('[data-remove-attachment]').click()`);
    if (!await student.eval(`document.querySelectorAll('[data-message-selected-file]').length===1 && document.querySelector('[data-message-files]').files.length===1`)) throw new Error('Removing one preview did not synchronize native input.');
    await student.eval(`document.querySelector('[data-clear-attachments]').click()`);
    if (!await student.eval(`document.querySelector('[data-message-attachments-preview]').hidden && document.querySelector('[data-message-files]').files.length===0 && document.querySelector('.chat-send').disabled`)) throw new Error('Remove all did not reset preview and native input.');
    checks.push({ check: 'Selection preview, individual remove, remove all and attachment-only send availability', passed: true });
    await student.files([png, text]);
    await student.eval(`document.querySelector('[data-message-form]').requestSubmit()`);
    await student.wait(`!!document.querySelector('[data-message-id] .chat-attachments') && document.querySelector('[data-message-attachments-preview]').hidden && /\\/messages\\/\\d+$/.test(location.pathname)`, 'attachment-only first message');
    const conversation = await student.eval('location.pathname');
    const firstId = await student.eval(`Number(document.querySelector('[data-message-id]').dataset.messageId)`);
    await student.wait(`document.querySelector('.chat-attachment-image img')?.complete && document.querySelector('.chat-attachment-image img')?.naturalWidth>0`, 'sender private image loads');
    if (!await student.eval(`document.querySelectorAll('.chat-attachment-download').length===2 && !document.querySelector('.chat-bubble')`)) throw new Error('Attachment-only message rendered an empty text bubble.');
    checks.push({ check: 'Real multipart attachment-only message creates a new conversation, image preview and two private downloads', passed: true });
    progress('Real first message saved and private image rendered');
    await evaluator.navigate(conversation);
    await evaluator.wait(`document.querySelectorAll('.chat-attachment-download').length===2 && document.querySelector('.chat-attachment-image img')?.naturalWidth>0`, 'recipient sees persisted attachments');
    const download = await evaluator.eval(`fetch(document.querySelectorAll('.chat-attachment-download')[1].href).then(async response=>({status:response.status,type:response.headers.get('content-type'),disposition:response.headers.get('content-disposition'),body:await response.text()}))`);
    if (download.status !== 200 || !download.disposition?.startsWith('attachment;') || !download.body.includes('Isolated direct-message attachment notes.')) throw new Error('Authorized download did not deliver attachment bytes.');
    const url = await evaluator.eval(`document.querySelector('.chat-attachment-download').href`);
    const guestStatus = (await fetch(url, { redirect: 'manual' })).status;
    if (![302, 401].includes(guestStatus)) throw new Error('Guest could access a private message attachment.');
    checks.push({ check: 'Recipient image loads and file downloads actual bytes; guest denied private URL', passed: true });
    await evaluator.files([png]);
    await evaluator.eval(`document.querySelector('[data-message-input]').value='Image and constructive feedback';document.querySelector('[data-message-input]').dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-message-form]').requestSubmit()`);
    await evaluator.wait(`document.querySelectorAll('[data-message-id]').length===2 && document.querySelector('[data-message-attachments-preview]').hidden`, 'real existing conversation reply');
    await student.wait(`document.querySelectorAll('[data-message-id]').length===2 && Array.from(document.querySelectorAll('.chat-bubble')).some(node=>node.textContent==='Image and constructive feedback')`, 'recipient polling receives image and text', 22000);
    await student.wait(`Array.from(document.querySelectorAll('.chat-attachment-image img')).every(image=>image.complete && image.naturalWidth>0)`, 'polled private image loads');
    checks.push({ check: 'Real multipart image with text reply arrives through polling and loads in both browser contexts', passed: true });
    progress('Real recipient reply and polling passed');
    await student.files([text]);
    await student.eval(`document.querySelector('[data-message-input]').value='Retained attachment retry';document.querySelector('[data-message-input]').dispatchEvent(new Event('input',{bubbles:true}));window.__attachmentQaFetch=window.fetch;window.__attachmentQaFail=true;window.fetch=function(url,options){if(window.__attachmentQaFail&&options?.method==='POST'&&String(url).includes('/messages/')){window.__attachmentQaFail=false;return Promise.resolve(new Response(JSON.stringify({errors:{attachments:['Synthetic attachment validation error']}}),{status:422,headers:{'Content-Type':'application/json'}}))}return window.__attachmentQaFetch.apply(this,arguments)};document.querySelector('[data-message-form]').requestSubmit()`);
    await student.wait(`document.querySelector('[data-chat-status]').textContent.includes('Synthetic attachment validation error') && !document.querySelector('.chat-send').disabled`, 'synthetic 422 response');
    await sleep(5500);
    if (!await student.eval(`document.querySelector('[data-message-input]').value==='Retained attachment retry' && document.querySelectorAll('[data-message-selected-file]').length===1 && document.querySelector('[data-message-files]').files.length===1 && document.querySelector('[data-chat-status]').textContent.includes('Synthetic attachment validation error')`)) throw new Error('Failed-send draft or attachments were lost after polling.');
    await student.eval(`window.fetch=window.__attachmentQaFetch;document.querySelector('[data-message-form]').requestSubmit()`);
    await student.wait(`document.querySelectorAll('[data-message-id]').length===3 && document.querySelector('[data-message-attachments-preview]').hidden && document.querySelector('[data-message-input]').value===''`, 'confirmed retry clears draft');
    checks.push({ check: 'Synthetic 422 preserves text and selected native files across polling; confirmed real retry clears both', passed: true });
    await student.eval(`(()=>{const files=new DataTransfer();files.items.add(new File(['safe notes'],'<img src=x onerror=alert(1)>.txt',{type:'text/plain'}));document.querySelector('[data-message-files]').files=files.files;document.querySelector('[data-message-files]').dispatchEvent(new Event('change',{bubbles:true}))})()`);
    if (!await student.eval(`document.querySelector('[data-message-selected-file] strong').textContent.startsWith('<img') && !document.querySelector('[data-message-selected-file] strong img')`)) throw new Error('Selected filename was interpreted as HTML.');
    await student.eval(`document.querySelector('[data-clear-attachments]').click()`);
    checks.push({ check: 'HTML-like filename stays text in selected file preview', passed: true });
    const rejected = await student.eval(`(()=>{const input=document.querySelector('[data-message-files]');const status=document.querySelector('[data-chat-status]');const choose=files=>{const transfer=new DataTransfer();files.forEach(file=>transfer.items.add(file));input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));return input.files.length===0&&document.querySelector('[data-message-attachments-preview]').hidden};const type=choose([new File(['x'],'blocked.exe')])&&status.textContent.includes('choose a photo');const count=choose(Array.from({length:6},(_,i)=>new File(['x'],'notes-'+i+'.txt')))&&status.textContent.includes('up to 5');const size=choose([new File([new Uint8Array(10*1024*1024+1)],'large.txt')])&&status.textContent.includes('larger than 10 MB');const total=choose(Array.from({length:3},(_,i)=>new File([new Uint8Array(8*1024*1024)],'part-'+i+'.txt')))&&status.textContent.includes('20 MB in total');return{type,count,size,total}})()`);
    if (!Object.values(rejected).every(Boolean)) throw new Error('Client attachment limits did not reject an invalid selection.');
    checks.push({ check: 'Client picker rejects unsupported extensions, more than five files, oversized file and oversized combined selection', passed: true });
    await student.layout('Desktop conversation and composer'); await student.screenshot('message-attachments-desktop.png');
    await student.viewport(390);
    await student.eval(`document.documentElement.dataset.theme='dark'`);
    await student.files([png, text]);
    await student.layout('Mobile dark conversation with bounded selected previews'); await student.screenshot('message-attachments-mobile-dark.png');
    const downloads = await student.eval(`Array.from(document.querySelectorAll('.chat-attachment-download')).every(link=>new URL(link.href).origin===location.origin)`);
    if (!downloads) throw new Error('An attachment link leaves the application origin.');
    await student.eval(`document.querySelector('[data-clear-attachments]').click()`);
    await student.send('Emulation.setScriptExecutionDisabled', { value: true });
    await student.navigate(conversation);
    const { root: doc } = await student.send('DOM.getDocument');
    const { nodeId } = await student.send('DOM.querySelector', { nodeId: doc.nodeId, selector: '[data-message-files]' });
    const box = await student.send('DOM.getBoxModel', { nodeId });
    if (box.model.width < 40 || box.model.height < 20) throw new Error('Native file input fallback is not visible without JavaScript.');
    await student.send('Emulation.setScriptExecutionDisabled', { value: false });
    checks.push({ check: 'Attachment URLs remain same-origin; native file input is visible with JavaScript disabled', passed: true });
    if (errors.length) throw new Error('Chrome reported JavaScript runtime exceptions.');
    const actualPosts = posts.filter(post => /^\/messages\//.test(post.path) && post.status >= 200 && post.status < 300);
    if (actualPosts.length < 3) throw new Error('Expected real multipart first-message, reply and retry responses.');
    report.passed = true; report.firstMessageId = firstId;
    progress('Attachment UI checks passed');
    console.log(JSON.stringify({ passed: true, checks: checks.length, realMessagePosts: actualPosts.length, runtimeErrors: 0, screenshots: 'docs/preview/message-attachments-*.png' }));
    await send('Browser.close').catch(() => {});
  } catch (error) {
    failure = error; report.passed = false; report.failure = redact(error); report.lastStep = stage;
  } finally {
    socket?.close();
    let cleanupFailure;
    for (const child of [browser, server]) { try { await stop(child); } catch (error) { cleanupFailure ??= error; } }
    if (!cleanupFailure && created) {
      try {
        inside(owned, privateRoot);
        if (path.resolve(owned).toLowerCase() !== path.join(privateRoot, `message-attachments-browser-${runId}`).toLowerCase() || !/^message-attachments-browser-[a-f0-9]{16}$/.test(path.basename(owned)) || fs.lstatSync(owned).isSymbolicLink()) throw new Error('Refusing cleanup of unexpected QA directory.');
        fs.rmSync(owned, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
      } catch (error) { cleanupFailure = error; }
    }
    report.posts = posts; report.runtimeErrors = errors.length;
    report.cleanup = cleanupFailure ? { completed: false, reason: redact(cleanupFailure) } : { completed: true };
    const file = path.join(output, 'message-attachments-browser-results.json'); inside(file); fs.writeFileSync(file, JSON.stringify(report, null, 2));
    if (failure || cleanupFailure) throw failure ?? cleanupFailure;
  }
})().catch(error => { console.error(redact(error)); process.exitCode = 1; });
