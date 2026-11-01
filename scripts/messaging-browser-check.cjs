// Isolated messaging browser QA. This script never opens the configured live DB.
// Owns only unique messaging-browser-* files and loopback ports 8004 / 9230.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, execFileSync } = require('node:child_process');

const root = fs.realpathSync(path.resolve(__dirname, '..'));
const privateRoot = path.join(root, 'storage', 'app', 'private');
const output = path.join(root, 'docs', 'preview');
const runId = crypto.randomBytes(8).toString('hex');
const database = path.join(privateRoot, `messaging-browser-qa-${runId}.sqlite`);
const profile = path.join(privateRoot, `messaging-browser-profile-${runId}`);
const seedScript = path.join(privateRoot, `messaging-browser-seed-${runId}.php`);
const origin = 'http://127.0.0.1:8004';
const debuggerOrigin = 'http://127.0.0.1:9230';
const studentEmail = 'messaging-qa-student@example.test';
const evaluatorEmail = 'messaging-qa-evaluator@example.test';
const studentPassword = crypto.randomBytes(30).toString('base64url');
const evaluatorPassword = crypto.randomBytes(30).toString('base64url');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const hasExited = child => child.exitCode !== null || child.signalCode !== null;
const checks = [];
const runtimeErrors = [];
const networkPosts = [];
const pages = [];
let lastCompletedStep = 'Preparing isolated QA paths';
function progress(step) { lastCompletedStep = step; console.log(`QA: ${step}`); }

function redact(value) {
  return String(value?.message ?? value).replaceAll(studentPassword, '[redacted]').replaceAll(evaluatorPassword, '[redacted]');
}
function assertInside(target, parent = root) {
  const absolute = path.resolve(target);
  const relative = path.relative(fs.realpathSync(parent), absolute);
  if (!relative || relative.startsWith('..') || path.isAbsolute(relative)) throw new Error('QA path escaped its intended parent.');
  if (fs.existsSync(absolute)) {
    const resolved = fs.realpathSync(absolute);
    const actualRelative = path.relative(fs.realpathSync(parent), resolved);
    if (!actualRelative || actualRelative.startsWith('..') || path.isAbsolute(actualRelative)) throw new Error('QA path resolves outside its intended parent.');
  }
}
function assertOwned(target, expected) {
  assertInside(target, privateRoot);
  if (path.resolve(target).toLowerCase() !== path.resolve(expected).toLowerCase()) throw new Error('Cleanup target is not an owned QA path.');
  if (fs.existsSync(target) && fs.lstatSync(target).isSymbolicLink()) throw new Error('An owned QA path became a link; it was retained.');
}
async function assertPortFree(port) {
  await new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', () => reject(new Error(`Port ${port} is in use. Existing processes were left untouched.`)));
    server.listen(port, '127.0.0.1', () => server.close(resolve));
  });
}
async function waitHttp(url, child) {
  for (let attempt = 0; attempt < 150; attempt++) {
    if (hasExited(child)) throw new Error('An owned QA service exited during startup.');
    try { if ((await fetch(url, { signal: AbortSignal.timeout(800) })).ok) return; } catch {}
    await sleep(100);
  }
  throw new Error('An isolated QA service did not start.');
}
async function stopOwned(child) {
  if (!child || hasExited(child)) return;
  child.kill();
  for (let attempt = 0; attempt < 80 && !hasExited(child); attempt++) await sleep(100);
  if (!hasExited(child)) throw new Error('An owned QA service is still running; its private files were retained.');
}

(async () => {
  let server, browser, socket;
  let createdDatabase = false, createdProfile = false, createdSeed = false;
  let failure;
  const report = { isolatedDatabase: true, isolatedBrowserContexts: true, ports: { application: 8004, browser: 9230 }, checks };
  try {
    fs.mkdirSync(privateRoot, { recursive: true });
    fs.mkdirSync(output, { recursive: true });
    [database, profile, seedScript].forEach(target => assertInside(target, privateRoot));
    assertInside(output);
    if (fs.existsSync(path.join(root, 'bootstrap', 'cache', 'config.php'))) throw new Error('QA requires uncached configuration; no live cache was changed.');
    const envText = fs.readFileSync(path.join(root, '.env'), 'utf8');
    const configured = process.env.DB_DATABASE ?? envText.match(/^DB_DATABASE=(.*)$/m)?.[1]?.trim().replace(/^(['"])(.*)\1$/, '$2');
    const liveDatabase = configured && configured !== ':memory:' ? path.resolve(root, configured) : '';
    if (liveDatabase.toLowerCase() === database.toLowerCase()) throw new Error('The unique QA database matches the configured application DB.');
    if ([database, profile, seedScript].some(target => fs.existsSync(target))) throw new Error('A new QA target already exists; no existing files were touched.');
    await assertPortFree(8004);
    await assertPortFree(9230);
    const chrome = [process.env.CHROME_PATH, 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'].find(candidate => candidate && fs.existsSync(candidate));
    if (!chrome) throw new Error('Installed Chrome was not found. No browser was installed.');
    const php = process.env.PHP_BINARY ?? 'php';
    const qaEnvironment = {
      ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin,
      APP_KEY: `base64:${crypto.randomBytes(32).toString('base64')}`,
      DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '', SESSION_DRIVER: 'database',
      SESSION_COOKIE: `analista_messaging_qa_${runId}`, SESSION_DOMAIN: 'null', SESSION_SECURE_COOKIE: 'false',
      CACHE_STORE: 'array', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr', QUEUE_CONNECTION: 'sync', BCRYPT_ROUNDS: '4',
      ANALISTA_DEMO: 'false', QA_DATABASE: database, QA_PRIVATE_ROOT: privateRoot, QA_LIVE_DATABASE: liveDatabase,
      QA_STUDENT_PASSWORD: studentPassword, QA_EVALUATOR_PASSWORD: evaluatorPassword,
    };
    fs.writeFileSync(database, '', { flag: 'wx' });
    createdDatabase = true;
    fs.mkdirSync(profile);
    createdProfile = true;
    const phpRoot = root.replaceAll('\\', '/').replaceAll("'", "\\'");
    fs.writeFileSync(seedScript, `<?php
require '${phpRoot}/vendor/autoload.php';
$app = require '${phpRoot}/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$expected = realpath(getenv('QA_DATABASE'));
$actual = realpath(Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName());
$private = realpath(getenv('QA_PRIVATE_ROOT'));
if (!app()->environment('testing') || config('database.default') !== 'sqlite'
    || !$expected || !$actual || strcasecmp($actual, $expected) !== 0
    || !str_starts_with(strtolower($actual), strtolower($private.DIRECTORY_SEPARATOR))
    || !preg_match('/^messaging-browser-qa-[a-f0-9]{16}\\.sqlite$/', basename($actual))
    || (getenv('QA_LIVE_DATABASE') && strcasecmp($actual, getenv('QA_LIVE_DATABASE')) === 0)
    || config('app.url') !== '${origin}') throw new RuntimeException('Refusing to seed outside the bound isolated messaging QA database.');
Illuminate\\Support\\Facades\\Artisan::call('migrate', ['--force' => true]);
config(['hashing.bcrypt.rounds' => 4]);
$student = App\\Models\\User::create(['name'=>'QA Messaging Student','email'=>'${studentEmail}','password'=>getenv('QA_STUDENT_PASSWORD'),'role'=>'student','accepting_requests'=>false]);
$evaluator = App\\Models\\User::create(['name'=>'QA Messaging Evaluator','email'=>'${evaluatorEmail}','password'=>getenv('QA_EVALUATOR_PASSWORD'),'role'=>'evaluator','evaluator_status'=>'approved','accepting_requests'=>true,'headline'=>'Isolated browser QA evaluator','bio'=>'This synthetic account exists only in a unique browser QA database and is never created in the runtime application.','expertise'=>['Web','Database'],'skills'=>['PHP','MySQL'],'review_modes'=>['online'],'experience_years'=>5,'capacity'=>3]);
echo json_encode(['studentId'=>$student->id,'evaluatorId'=>$evaluator->id]);
`, { flag: 'wx' });
    createdSeed = true;
    const ids = JSON.parse(execFileSync(php, [seedScript], { cwd: root, env: qaEnvironment, windowsHide: true, encoding: 'utf8', timeout: 45000, stdio: ['ignore', 'pipe', 'pipe'] }));
    progress('Isolated fixture database migrated and seeded');
    server = spawn(php, ['-S', '127.0.0.1:8004', '-t', 'public', 'dev-server.php'], { cwd: root, env: qaEnvironment, windowsHide: true, stdio: 'ignore' });
    await waitHttp(`${origin}/login`, server);
    browser = spawn(chrome, ['--headless=new', '--remote-debugging-port=9230', '--remote-debugging-address=127.0.0.1', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-sync', '--disable-extensions', 'about:blank'], { cwd: root, windowsHide: true, stdio: 'ignore' });
    await waitHttp(`${debuggerOrigin}/json/version`, browser);
    progress('Owned PHP server and Chrome started');
    const version = await (await fetch(`${debuggerOrigin}/json/version`)).json();
    socket = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', () => reject(new Error('Could not connect to the owned Chrome instance.')), { once: true }); });
    let sequence = 0;
    const pending = new Map();
    const requests = new Map();
    socket.addEventListener('message', ({ data }) => {
      const packet = JSON.parse(data);
      if (packet.id && pending.has(packet.id)) {
        const callback = pending.get(packet.id); pending.delete(packet.id);
        packet.error ? callback.reject(new Error(packet.error.message)) : callback.resolve(packet.result);
      }
      if (packet.method === 'Runtime.exceptionThrown') runtimeErrors.push({ session: packet.sessionId, text: packet.params.exceptionDetails.text });
      if (packet.method === 'Network.requestWillBeSent' && packet.params.request.method === 'POST') requests.set(packet.params.requestId, { url: new URL(packet.params.request.url).pathname, type: packet.params.type, session: packet.sessionId });
      if (packet.method === 'Network.responseReceived' && requests.has(packet.params.requestId)) {
        networkPosts.push({ ...requests.get(packet.params.requestId), status: packet.params.response.status, mime: packet.params.response.mimeType });
        requests.delete(packet.params.requestId);
      }
    });
    function send(method, params = {}, sessionId) {
      return new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`Browser command timed out: ${method}`)); }, 12000);
        pending.set(id, { resolve: result => { clearTimeout(timeout); resolve(result); }, reject: error => { clearTimeout(timeout); reject(error); } });
        socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
      });
    }
    async function newPage(label) {
      const context = await send('Target.createBrowserContext');
      const target = await send('Target.createTarget', { url: 'about:blank', browserContextId: context.browserContextId, newWindow: true, background: false });
      const attached = await send('Target.attachToTarget', { targetId: target.targetId, flatten: true });
      const session = attached.sessionId;
      for (const method of ['Page.enable', 'Runtime.enable', 'Network.enable']) await send(method, {}, session);
      await send('Emulation.setFocusEmulationEnabled', { enabled: true }, session);
      const page = { label, session, width: 1440 };
      page.send = (method, params = {}) => send(method, params, session);
      page.evaluate = async expression => {
        const result = await page.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw new Error(`${label}: browser evaluation failed.`);
        return result.result.value;
      };
      page.wait = async (expression, description, timeout = 16000) => {
        const end = Date.now() + timeout;
        while (Date.now() < end) { try { if (await page.evaluate(expression)) return; } catch {} await sleep(125); }
        throw new Error(`${label}: timed out waiting for ${description}.`);
      };
      page.navigate = async route => {
        await page.send('Page.navigate', { url: origin + route });
        await page.wait(`location.pathname === ${JSON.stringify(route.split('?')[0])} && document.readyState === 'complete' && !!document.querySelector('#main')`, `page ${route}`);
        await page.evaluate('document.fonts.ready.then(() => true)');
      };
      page.viewport = async width => {
        page.width = width;
        await page.send('Emulation.setDeviceMetricsOverride', { width, height: width === 390 ? 844 : 1000, deviceScaleFactor: 1, mobile: width === 390 });
        await sleep(300);
      };
      page.layout = async label => {
        const geometry = await page.evaluate(`({width:innerWidth,scroll:document.documentElement.scrollWidth,heading:document.querySelector('h1')?.innerText})`);
        if (geometry.width !== page.width || geometry.scroll > page.width + 1 || !geometry.heading) throw new Error(`${label}: overflow/missing heading ${JSON.stringify(geometry)}`);
        checks.push({ check: label, viewport: page.width, horizontalOverflow: false });
      };
      page.screenshot = async filename => {
        const target = path.join(output, filename); assertInside(target);
        const picture = await page.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        fs.writeFileSync(target, Buffer.from(picture.data, 'base64'));
      };
      page.login = async (email, password) => {
        await page.navigate('/login');
        await page.evaluate(`document.querySelector('[name=email]').value=${JSON.stringify(email)};document.querySelector('[name=password]').value=${JSON.stringify(password)};document.querySelector('.auth-container form').requestSubmit()`);
        await page.wait(`document.readyState==='complete' && location.pathname.startsWith('/account') && !!document.querySelector('#main')`, 'successful isolated login');
      };
      page.submit = async body => {
        await page.evaluate(`(() => { const input=document.querySelector('[data-message-input]');input.value=${JSON.stringify(body)};input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',code:'Enter',bubbles:true,cancelable:true})); })()`);
      };
      await page.viewport(1440);
      pages.push(page);
      return page;
    }
    const student = await newPage('student');
    const evaluator = await newPage('evaluator');
    await student.login(studentEmail, studentPassword);
    await evaluator.login(evaluatorEmail, evaluatorPassword);
    progress('Both isolated user contexts signed in');
    await evaluator.navigate('/messages?list=1');
    await student.navigate(`/evaluators/${ids.evaluatorId}`);
    const composeLink = await student.evaluate(`document.querySelector('a[href*="/messages/to/"]')?.getAttribute('href')`);
    if (!composeLink) throw new Error('Approved evaluator profile has no Message action.');
    await student.evaluate(`document.querySelector('a[href*="/messages/to/"]').click()`);
    await student.wait(`location.pathname==='/messages/to/${ids.evaluatorId}' && document.readyState==='complete' && !!document.querySelector('[data-message-form]')`, 'compose view from evaluator profile');
    progress('Evaluator profile Message action opens composer');
    const first = 'Hello! Could you review our thesis methodology and database design? <b>Keep this text literal.</b>';
    const reply = 'Thanks for reaching out. Please share the objectives and the methodology section you would like checked.';
    await student.submit(first);
    await student.wait(`location.pathname.split('/').length===3 && /^[0-9]+$/.test(location.pathname.split('/').at(-1)) && document.readyState==='complete' && document.querySelector('[data-message-thread]')?.innerText.includes(${JSON.stringify(first)})`, 'AJAX first message and canonical conversation');
    progress('First AJAX message opened the canonical conversation');
    const conversationId = await student.evaluate(`Number(location.pathname.split('/').at(-1))`);
    await student.evaluate(`window.__messagingQaDocument='student-stable-document'`);
    await evaluator.wait(`!!document.querySelector('[data-chat-id="${conversationId}"] .chat-unread') && Number(document.querySelector('[data-message-unread]')?.textContent)===1`, 'recipient unread badge through inbox polling', 22000);
    await evaluator.layout('Recipient unread chat list desktop');
    await evaluator.screenshot('messaging-unread-desktop.png');
    checks.push({ check: 'New conversation sent through AJAX and recipient list/unread badge polled', passed: true });
    progress('Recipient unread badge updated through polling');
    await evaluator.evaluate(`document.querySelector('[data-chat-id="${conversationId}"]').click()`);
    await evaluator.wait(`location.pathname==='/messages/${conversationId}' && document.readyState==='complete' && !!document.querySelector('[data-message-form]')`, 'recipient opening conversation');
    await student.wait(`document.querySelector('[data-message-receipt]')?.textContent==='Seen'`, 'sender Seen receipt polling', 13000);
    if (await evaluator.evaluate(`document.querySelector('.chat-bubble b') !== null`)) throw new Error('Message markup rendered as HTML instead of literal text.');
    await evaluator.evaluate(`window.__messagingQaDocument='evaluator-stable-document'`);
    await evaluator.submit(reply);
    await evaluator.wait(`document.querySelector('[data-message-thread]')?.innerText.includes(${JSON.stringify(reply)})`, 'AJAX reply');
    await student.wait(`document.querySelector('[data-message-thread]')?.innerText.includes(${JSON.stringify(reply)})`, 'incoming reply through polling', 13000);
    await evaluator.wait(`Array.from(document.querySelectorAll('.is-own [data-message-receipt]')).at(-1)?.textContent==='Seen'`, 'reply Seen receipt', 13000);
    if (!await student.evaluate(`window.__messagingQaDocument==='student-stable-document'`) || !await evaluator.evaluate(`window.__messagingQaDocument==='evaluator-stable-document'`)) throw new Error('Reply/polling unexpectedly reloaded a conversation.');
    checks.push({ check: 'AJAX reply, incoming polling, and both Seen receipts without page reload', passed: true });
    progress('Reply polling and Seen receipts passed in both contexts');
    await student.submit('A long identifier to check wrapping: ' + 'thesis'.repeat(40));
    await student.wait(`document.querySelectorAll('[data-message-id]').length===3`, 'long message');
    await student.layout('Conversation desktop light');
    await student.screenshot('messaging-desktop-light.png');
    await student.evaluate(`document.querySelector('[data-theme-preference=dark]').click()`);
    await student.wait(`document.documentElement.dataset.theme==='dark'`, 'desktop dark mode');
    await student.screenshot('messaging-desktop-dark.png');
    await student.viewport(390);
    await student.layout('Conversation mobile dark');
    const composer = await student.evaluate(`(() => { const r=document.querySelector('.chat-send').getBoundingClientRect();return {bottom:r.bottom,right:r.right};})()`);
    if (composer.bottom > 845 || composer.right > 391) throw new Error(`Mobile composer is outside the viewport: ${JSON.stringify(composer)}`);
    await student.screenshot('messaging-mobile-dark.png');
    await student.evaluate(`document.querySelector('[data-theme-preference=light]').click()`);
    await student.wait(`document.documentElement.dataset.theme==='light'`, 'mobile light mode');
    await student.screenshot('messaging-mobile-light.png');
    await student.evaluate(`document.querySelector('.chat-back').click()`);
    await student.wait(`location.pathname==='/messages' && new URLSearchParams(location.search).get('list')==='1' && document.readyState==='complete'`, 'mobile back to all chats');
    await sleep(11000);
    if (!await student.evaluate(`new URLSearchParams(location.search).get('list')==='1' && !document.querySelector('[data-message-room]').classList.contains('has-conversation') && getComputedStyle(document.querySelector('.chat-sidebar')).display!=='none'`)) throw new Error('Mobile Back reopened the conversation automatically.');
    await student.layout('Mobile chat list after one full inbox polling interval');
    await student.screenshot('messaging-mobile-chat-list.png');
    await student.evaluate(`document.querySelector('[data-chat-id="${conversationId}"]').click()`);
    await student.wait(`location.pathname==='/messages/${conversationId}' && document.readyState==='complete' && getComputedStyle(document.querySelector('.chat-panel')).display==='flex'`, 'reopening selected mobile chat');
    checks.push({ check: 'Mobile Back keeps the chat list open across polling and permits reopening', passed: true });
    const messagePosts = networkPosts.filter(post => post.url.startsWith('/messages/') && post.status === 201 && post.type === 'Fetch' && post.mime.includes('json'));
    if (messagePosts.length !== 3) throw new Error(`Expected three successful real AJAX message posts, saw ${messagePosts.length}.`);
    if (runtimeErrors.length) throw new Error('Chrome reported JavaScript runtime exceptions.');
    report.messagePosts = messagePosts.map(({ url, status, type, mime }) => ({ url, status, type, mime }));
    report.runtimeErrors = runtimeErrors.length;
    report.passed = true;
    console.log(JSON.stringify({ passed: true, checks: checks.length, realAjaxPosts: messagePosts.length, runtimeErrors: 0, screenshots: 'docs/preview/messaging-*.png' }));
    await send('Browser.close').catch(() => {});
  } catch (error) {
    failure = error;
    report.passed = false;
    report.failure = redact(error);
    report.lastCompletedStep = lastCompletedStep;
    report.networkPosts = networkPosts.map(({ url, status, type, mime }) => ({ url, status, type, mime }));
    report.runtimeErrors = runtimeErrors.map(error => error.text);
    report.pages = [];
    for (const page of pages) {
      try {
        report.pages.push({ role: page.label, state: await page.evaluate(`({path:location.pathname,query:location.search,ready:document.readyState,chatStatus:document.querySelector('[data-chat-status]')?.textContent,visibleMessages:document.querySelectorAll('[data-message-id]').length,mainText:document.querySelector('#main')?.innerText.slice(-2000)})`) });
        await page.screenshot(`messaging-failure-${page.label}.png`);
      } catch {}
    }
  } finally {
    socket?.close();
    let cleanupFailure;
    for (const child of [browser, server]) { try { await stopOwned(child); } catch (error) { cleanupFailure ??= error; } }
    if (!cleanupFailure) {
      try {
        if (createdSeed) { assertOwned(seedScript, seedScript); fs.unlinkSync(seedScript); }
        if (createdDatabase) for (const suffix of ['', '-journal', '-shm', '-wal']) {
          const target = database + suffix; assertOwned(target, database + suffix);
          if (fs.existsSync(target)) fs.unlinkSync(target);
        }
        if (createdProfile) {
          assertOwned(profile, path.join(privateRoot, `messaging-browser-profile-${runId}`));
          if (!/^messaging-browser-profile-[a-f0-9]{16}$/.test(path.basename(profile))) throw new Error('Refusing recursive cleanup of an unexpected browser profile.');
          fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
        }
      } catch (error) { cleanupFailure = error; }
    }
    report.cleanup = cleanupFailure ? { completed: false, reason: redact(cleanupFailure), ownedArtifactsRetained: true } : { completed: true };
    const reportPath = path.join(output, 'messaging-browser-results.json');
    assertInside(reportPath);
    fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
    if (failure || cleanupFailure) throw failure ?? cleanupFailure;
  }
})().catch(error => { console.error(redact(error)); process.exitCode = 1; });
