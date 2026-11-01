// Real profile form browser QA, confined to a fresh database, upload disk, and
// Chrome profile inside one uniquely named owned directory. Never seeds live DB.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const zlib = require('node:zlib');
const { spawn, execFileSync } = require('node:child_process');

const root = fs.realpathSync(path.resolve(__dirname, '..'));
const privateRoot = path.join(root, 'storage', 'app', 'private');
const output = path.join(root, 'docs', 'preview');
const runId = crypto.randomBytes(8).toString('hex');
const owned = path.join(privateRoot, `profile-settings-browser-${runId}`);
const database = path.join(owned, 'qa.sqlite');
const uploadDisk = path.join(owned, 'upload-disk');
const browserProfile = path.join(owned, 'chrome-profile');
const seedScript = path.join(owned, 'seed.php');
const routerScript = path.join(owned, 'router.php');
const fixture = path.join(owned, 'profile.png');
const origin = 'http://127.0.0.1:8020';
const debuggerOrigin = 'http://127.0.0.1:9246';
const email = 'profile-browser-qa@example.test';
const initialPassword = crypto.randomBytes(30).toString('base64url');
const nextPassword = crypto.randomBytes(30).toString('base64url');
const wrongPassword = crypto.randomBytes(30).toString('base64url');
const updatedName = 'QA Updated Member';
const checks = [];
const posts = [];
const runtimeErrors = [];
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const hasExited = child => child.exitCode !== null || child.signalCode !== null;
let step = 'Preparing isolated profile QA';
function progress(message) { step = message; console.log(`QA: ${message}`); }
function redact(value) {
  return String(value?.message ?? value).replaceAll(initialPassword, '[redacted]')
    .replaceAll(nextPassword, '[redacted]').replaceAll(wrongPassword, '[redacted]').replaceAll(email, '[QA account]');
}
function assertInside(target, parent = root) {
  const absolute = path.resolve(target);
  const relative = path.relative(fs.realpathSync(parent), absolute);
  if (!relative || relative.startsWith('..') || path.isAbsolute(relative)) throw new Error('QA path escaped its intended parent.');
  if (fs.existsSync(absolute)) {
    const actual = path.relative(fs.realpathSync(parent), fs.realpathSync(absolute));
    if (!actual || actual.startsWith('..') || path.isAbsolute(actual)) throw new Error('QA path resolves outside its intended parent.');
  }
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
function pngFixture() {
  const size = 96;
  const raw = Buffer.alloc(size * (1 + size * 4));
  for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) {
    const at = y * (1 + size * 4) + 1 + x * 4;
    const inCircle = (x - 48) ** 2 + (y - 48) ** 2 < 42 ** 2;
    const eye = y >= 34 && y < 44 && ((x >= 30 && x < 37) || (x >= 59 && x < 66));
    const smile = y >= 60 && y < 66 && x >= 34 && x < 63;
    raw[at] = eye || smile ? 255 : 86;
    raw[at + 1] = eye || smile ? 255 : 159;
    raw[at + 2] = eye || smile ? 255 : 208;
    raw[at + 3] = inCircle ? 255 : 0;
  }
  const crc = bytes => {
    let value = 0xffffffff;
    for (const byte of bytes) {
      value ^= byte;
      for (let bit = 0; bit < 8; bit++) value = (value >>> 1) ^ ((value & 1) ? 0xedb88320 : 0);
    }
    return (value ^ 0xffffffff) >>> 0;
  };
  const chunk = (type, data) => {
    const name = Buffer.from(type);
    const length = Buffer.alloc(4); length.writeUInt32BE(data.length);
    const checksum = Buffer.alloc(4); checksum.writeUInt32BE(crc(Buffer.concat([name, data])));
    return Buffer.concat([length, name, data, checksum]);
  };
  const header = Buffer.alloc(13); header.writeUInt32BE(size); header.writeUInt32BE(size, 4); header[8] = 8; header[9] = 6;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', header), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

(async () => {
  let server, browser, socket, page;
  let createdOwned = false;
  let failure;
  const report = { isolatedDatabase: true, isolatedUploadDisk: true, realCsrfForms: true, ports: { application: 8020, browser: 9246 }, checks };
  try {
    fs.mkdirSync(privateRoot, { recursive: true }); fs.mkdirSync(output, { recursive: true });
    assertInside(owned, privateRoot); assertInside(output);
    if (fs.existsSync(owned)) throw new Error('The unique QA directory already exists; existing files were left untouched.');
    if (fs.existsSync(path.join(root, 'bootstrap', 'cache', 'config.php'))) throw new Error('QA requires uncached configuration; no live cache was changed.');
    const envText = fs.readFileSync(path.join(root, '.env'), 'utf8');
    const configured = process.env.DB_DATABASE ?? envText.match(/^DB_DATABASE=(.*)$/m)?.[1]?.trim().replace(/^(['"])(.*)\1$/, '$2');
    const liveDatabase = configured && configured !== ':memory:' ? path.resolve(root, configured) : '';
    if (liveDatabase.toLowerCase() === database.toLowerCase()) throw new Error('QA database unexpectedly matches the configured application database.');
    await assertPortFree(8020); await assertPortFree(9246);
    const chrome = [process.env.CHROME_PATH, 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe'].find(candidate => candidate && fs.existsSync(candidate));
    if (!chrome) throw new Error('Installed Chrome was not found. No browser was installed.');
    fs.mkdirSync(owned); createdOwned = true;
    fs.mkdirSync(uploadDisk); fs.mkdirSync(browserProfile); fs.writeFileSync(database, '', { flag: 'wx' }); fs.writeFileSync(fixture, pngFixture(), { flag: 'wx' });
    const phpRoot = root.replaceAll('\\', '/').replaceAll("'", "\\'");
    const guard = `
$expected = realpath(getenv('QA_DATABASE'));
$actual = realpath(Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName());
$owned = realpath(getenv('QA_OWNED_ROOT'));
$disk = realpath(getenv('QA_LOCAL_ROOT'));
if (!app()->environment('testing') || config('database.default') !== 'sqlite'
    || !$expected || !$actual || strcasecmp($actual, $expected) !== 0
    || !$owned || !preg_match('/^profile-settings-browser-[a-f0-9]{16}$/', basename($owned))
    || strcasecmp(dirname($actual), $owned) !== 0 || basename($actual) !== 'qa.sqlite'
    || !$disk || strcasecmp(dirname($disk), $owned) !== 0 || basename($disk) !== 'upload-disk'
    || (getenv('QA_LIVE_DATABASE') && strcasecmp($actual, getenv('QA_LIVE_DATABASE')) === 0)
    || config('app.url') !== '${origin}') throw new RuntimeException('Refusing access outside the isolated profile QA database and upload disk.');
config(['filesystems.disks.local.root' => $disk]);
`;
    fs.writeFileSync(seedScript, `<?php
require '${phpRoot}/vendor/autoload.php';
$app = require '${phpRoot}/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
${guard}
Illuminate\\Support\\Facades\\Artisan::call('migrate', ['--force' => true]);
config(['hashing.bcrypt.rounds' => 4]);
$student = App\\Models\\User::create(['name'=>'QA Profile Student','email'=>'${email}','password'=>getenv('QA_INITIAL_PASSWORD'),'role'=>'student','accepting_requests'=>false]);
echo json_encode(['seededStudent'=>true]);
`, { flag: 'wx' });
    fs.writeFileSync(routerScript, `<?php
$publicRoot = realpath('${phpRoot}/public');
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$requestedFile = realpath($publicRoot.DIRECTORY_SEPARATOR.ltrim($requestPath, '/'));
if ($requestPath !== '/' && $requestedFile && str_starts_with($requestedFile, $publicRoot.DIRECTORY_SEPARATOR) && is_file($requestedFile)) return false;
define('LARAVEL_START', microtime(true));
require '${phpRoot}/vendor/autoload.php';
$app = require '${phpRoot}/bootstrap/app.php';
$kernel = $app->make(Illuminate\\Contracts\\Http\\Kernel::class);
$kernel->bootstrap();
${guard}
$response = $kernel->handle($request = Illuminate\\Http\\Request::capture());
$response->send();
$kernel->terminate($request, $response);
`, { flag: 'wx' });
    const environment = {
      ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin,
      APP_KEY: `base64:${crypto.randomBytes(32).toString('base64')}`,
      DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '', SESSION_DRIVER: 'database',
      SESSION_COOKIE: `analista_profile_qa_${runId}`, SESSION_DOMAIN: 'null', SESSION_SECURE_COOKIE: 'false',
      CACHE_STORE: 'array', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr', QUEUE_CONNECTION: 'sync', BCRYPT_ROUNDS: '4',
      ANALISTA_DEMO: 'false', QA_DATABASE: database, QA_LIVE_DATABASE: liveDatabase,
      QA_OWNED_ROOT: owned, QA_LOCAL_ROOT: uploadDisk, QA_INITIAL_PASSWORD: initialPassword,
    };
    const php = process.env.PHP_BINARY ?? 'php';
    const seeded = JSON.parse(execFileSync(php, [seedScript], { cwd: root, env: environment, windowsHide: true, encoding: 'utf8', timeout: 45000, stdio: ['ignore', 'pipe', 'pipe'] }));
    if (!seeded.seededStudent) throw new Error('Isolated student fixture did not initialize.');
    progress('Fresh isolated student database and upload disk ready');
    server = spawn(php, ['-S', '127.0.0.1:8020', '-t', 'public', routerScript], { cwd: root, env: environment, windowsHide: true, stdio: 'ignore' });
    await waitHttp(`${origin}/login`, server);
    browser = spawn(chrome, ['--headless=new', '--remote-debugging-port=9246', '--remote-debugging-address=127.0.0.1', `--user-data-dir=${browserProfile}`, '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-sync', '--disable-extensions', 'about:blank'], { cwd: root, windowsHide: true, stdio: 'ignore' });
    await waitHttp(`${debuggerOrigin}/json/version`, browser);
    const version = await (await fetch(`${debuggerOrigin}/json/version`)).json();
    socket = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', () => reject(new Error('Could not connect to owned Chrome.')), { once: true }); });
    let sequence = 0;
    const pending = new Map(); const requests = new Map();
    function send(method, params = {}, sessionId) {
      return new Promise((resolve, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Browser command timed out: ${method}`)); }, 12000);
        pending.set(id, { resolve: result => { clearTimeout(timer); resolve(result); }, reject: error => { clearTimeout(timer); reject(error); } });
        socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
      });
    }
    socket.addEventListener('message', ({ data }) => {
      const packet = JSON.parse(data);
      if (packet.id && pending.has(packet.id)) { const callback = pending.get(packet.id); pending.delete(packet.id); packet.error ? callback.reject(new Error(packet.error.message)) : callback.resolve(packet.result); }
      if (packet.method === 'Runtime.exceptionThrown') runtimeErrors.push(packet.params.exceptionDetails.text);
      if (packet.method === 'Network.requestWillBeSent' && packet.params.request.method === 'POST') requests.set(packet.params.requestId, { path: new URL(packet.params.request.url).pathname, type: packet.params.type });
      if (packet.method === 'Network.responseReceived' && requests.has(packet.params.requestId)) { posts.push({ ...requests.get(packet.params.requestId), status: packet.params.response.status }); requests.delete(packet.params.requestId); }
      if (packet.method === 'Fetch.requestPaused') {
        const request = packet.params;
        if (request.request.url.startsWith(`${origin}/`)) send('Fetch.continueRequest', { requestId: request.requestId }, packet.sessionId).catch(() => {});
        else send('Fetch.failRequest', { requestId: request.requestId, errorReason: 'BlockedByClient' }, packet.sessionId).catch(() => {});
      }
    });
    const target = await send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await send('Target.attachToTarget', { targetId: target.targetId, flatten: true });
    page = { width: 1440, send: (method, params = {}) => send(method, params, sessionId) };
    for (const method of ['Page.enable', 'Runtime.enable', 'Network.enable']) await page.send(method);
    await page.send('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
    await page.send('Emulation.setFocusEmulationEnabled', { enabled: true });
    page.evaluate = async expression => {
      const value = await page.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
      if (value.exceptionDetails) throw new Error('Profile browser evaluation failed.');
      return value.result.value;
    };
    page.wait = async (expression, description, timeout = 16000) => {
      const end = Date.now() + timeout;
      while (Date.now() < end) { try { if (await page.evaluate(expression)) return; } catch {} await sleep(125); }
      throw new Error(`Timed out waiting for ${description}.`);
    };
    page.navigate = async route => {
      await page.send('Page.navigate', { url: origin + route });
      await page.wait(`location.pathname===${JSON.stringify(route.split('#')[0])} && document.readyState==='complete' && !!document.querySelector('#main')`, 'page navigation');
      await page.evaluate('document.fonts.ready.then(() => {document.documentElement.style.scrollBehavior="auto";return true})');
    };
    page.viewport = async width => { page.width = width; await page.send('Emulation.setDeviceMetricsOverride', { width, height: width === 390 ? 844 : 1000, deviceScaleFactor: 1, mobile: width === 390 }); await sleep(250); };
    page.layout = async label => {
      const geometry = await page.evaluate(`({width:innerWidth,scroll:document.documentElement.scrollWidth,photo:document.querySelector('[data-profile-photo-preview]').getBoundingClientRect().width,details:document.querySelector('.profile-details-form').getBoundingClientRect().width,password:document.querySelector('.profile-password-form').getBoundingClientRect().width})`);
      if (geometry.width !== page.width || geometry.scroll > page.width + 1 || Math.min(geometry.photo, geometry.details, geometry.password) < 1) throw new Error('Profile layout has horizontal overflow or missing content.');
      checks.push({ check: label, viewport: page.width, horizontalOverflow: false });
    };
    page.screenshot = async name => { const filename = path.join(output, name); assertInside(filename); const capture = await page.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); fs.writeFileSync(filename, Buffer.from(capture.data, 'base64')); };
    page.submit = async selector => {
      const current = await page.evaluate('window.__profileQaDocument');
      const tokenPresent = await page.evaluate(`!!document.querySelector(${JSON.stringify(selector)}+' [name="_token"]')?.value`);
      if (!tokenPresent) throw new Error('Profile form is missing its real CSRF token.');
      await page.evaluate(`document.querySelector(${JSON.stringify(selector)}).requestSubmit()`);
      await page.wait(`document.readyState==='complete' && window.__profileQaDocument!==${JSON.stringify(current)} && !!document.querySelector('#main')`, 'real form response');
      await page.evaluate(`window.__profileQaDocument=${JSON.stringify(crypto.randomBytes(6).toString('hex'))};document.documentElement.style.scrollBehavior='auto'`);
    };
    page.login = async (password, expected = true) => {
      await page.navigate('/login');
      await page.evaluate(`window.__profileQaDocument=${JSON.stringify(crypto.randomBytes(6).toString('hex'))};document.querySelector('[name=email]').value=${JSON.stringify(email)};document.querySelector('[name=password]').value=${JSON.stringify(password)}`);
      await page.submit('.auth-container form');
      await page.wait(expected
        ? `location.pathname.startsWith('/account') && !!document.querySelector('.account-chip')`
        : `location.pathname==='/login' && !!document.querySelector('.alert[role="alert"]') && !document.querySelector('.account-chip')`, expected ? 'successful student login' : 'old password rejection');
    };
    await page.viewport(1440); await page.login(initialPassword); await page.navigate('/account/profile');
    const csrfStatus = await page.evaluate(`fetch('/account/profile',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'text/html'},body:'_method=PUT&_token=invalid-qa-token&name=Blocked+Attempt'}).then(response=>response.status)`);
    if (csrfStatus !== 419) throw new Error('The isolated browser server did not enforce CSRF tokens.');
    checks.push({ check: 'Real HTTP server rejects missing or invalid CSRF token', status: csrfStatus });
    await page.evaluate(`window.__profileQaDocument='initial-profile';document.querySelector('[name=name]').value=${JSON.stringify(updatedName)};document.querySelector('[name=city]').value='Quezon City'`);
    if (await page.evaluate(`!!document.querySelector('a[href="${origin}/account/evaluator-verification"]')`)) throw new Error('Student has an evaluator verification action.');
    const dom = await page.send('DOM.getDocument');
    const fileInput = await page.send('DOM.querySelector', { nodeId: dom.root.nodeId, selector: '#profile_photo' });
    await page.send('DOM.setFileInputFiles', { nodeId: fileInput.nodeId, files: [fixture] });
    await page.wait(`document.querySelector('[data-profile-photo-preview] img')?.src.startsWith('blob:')`, 'client-side photo preview');
    await page.submit('.profile-details-form');
    await page.wait(`document.querySelector('.account-chip strong')?.textContent===${JSON.stringify(updatedName)} && !!document.querySelector('.account-chip img') && document.querySelector('[data-profile-photo-preview] img')?.complete && document.querySelector('[data-profile-photo-preview] img')?.naturalWidth>0 && !!document.querySelector('.alert.success')`, 'saved name and uploaded photo');
    const uploadedPhoto = await page.evaluate(`document.querySelector('[data-profile-photo-preview] img').src`);
    if (!uploadedPhoto.startsWith(`${origin}/profile-photos/`)) throw new Error('Saved avatar does not use the controlled profile-photo route.');
    const uploadedFiles = fs.readdirSync(uploadDisk, { recursive: true }).filter(name => /\.(png|jpg|webp)$/.test(name));
    if (uploadedFiles.length !== 1) throw new Error('Expected one isolated uploaded photo.');
    checks.push({ check: 'Real multipart form saved display name, city and photo; sidebar updated; isolated disk received one image', passed: true });
    progress('Real name and photo form passed');
    await page.layout('Desktop profile settings'); await page.screenshot('profile-settings-desktop.png');
    await page.viewport(390); await page.layout('Mobile profile settings'); await page.screenshot('profile-settings-mobile.png');
    await page.viewport(1440);
    await page.evaluate(`if(document.documentElement.dataset.theme!=='dark')document.querySelector('[data-theme-preference=dark]').click()`);
    await page.layout('Dark profile settings'); await page.screenshot('profile-settings-dark.png');
    await page.evaluate(`document.querySelector('[data-theme-preference=light]').click();document.querySelector('#current_password').value=${JSON.stringify(wrongPassword)};document.querySelector('#new_password').value=${JSON.stringify(nextPassword)};document.querySelector('#password_confirmation').value=${JSON.stringify(nextPassword)}`);
    await page.submit('.profile-password-form');
    await page.wait(`!!document.querySelector('#current-password-error') && !!document.querySelector('.account-chip') && document.querySelector('#current_password')?.value==='' && document.querySelector('#new_password')?.value===''`, 'wrong-current-password validation');
    checks.push({ check: 'Incorrect current password rejected; password inputs cleared; session retained', passed: true });
    await page.evaluate(`document.querySelector('#current_password').value=${JSON.stringify(initialPassword)};document.querySelector('#new_password').value=${JSON.stringify(nextPassword)};document.querySelector('#password_confirmation').value=${JSON.stringify(nextPassword)};document.querySelector('[data-password-toggle="new_password"]').click()`);
    if (!await page.evaluate(`document.querySelector('#new_password').type==='text' && document.querySelector('[data-password-toggle="new_password"]').getAttribute('aria-pressed')==='true'`)) throw new Error('Password visibility toggle did not work.');
    await page.submit('.profile-password-form');
    await page.wait(`document.querySelector('.alert.success')?.textContent.includes('password has been changed') && !!document.querySelector('.account-chip') && document.querySelector('#current_password')?.value==='' && document.querySelector('#new_password')?.value===''`, 'successful password update without logout');
    checks.push({ check: 'Correct current password changed password; visibility toggle works; current session remains authenticated', passed: true });
    progress('Wrong and correct current-password forms passed');
    await page.evaluate(`document.querySelector('[name="remove_profile_photo"]').click()`);
    if (!await page.evaluate(`!document.querySelector('[data-profile-photo-preview] img')`)) throw new Error('Remove-photo preview did not restore initials.');
    await page.submit('.profile-details-form');
    await page.wait(`!document.querySelector('.account-chip img') && !document.querySelector('[data-profile-photo-preview] img') && !document.querySelector('[name="remove_profile_photo"]') && !!document.querySelector('.alert.success')`, 'saved photo removal');
    const removedResponse = await fetch(uploadedPhoto);
    if (removedResponse.status !== 404) throw new Error('Removed photo URL remains available.');
    const remainingFiles = fs.readdirSync(uploadDisk, { recursive: true }).filter(name => /\.(png|jpg|webp)$/.test(name));
    if (remainingFiles.length) throw new Error('Removed photo remains on isolated disk.');
    checks.push({ check: 'Photo removal restores initials, deletes old image and retires old URL', passed: true });
    await page.submit('.sidebar-bottom form');
    await page.wait(`!document.querySelector('.account-chip') && !!document.querySelector('.sidebar-login')`, 'logout');
    await page.login(initialPassword, false);
    await page.login(nextPassword);
    await page.navigate('/account/profile');
    await page.wait(`document.querySelector('[name=name]').value===${JSON.stringify(updatedName)} && !document.querySelector('[data-profile-photo-preview] img') && !!document.querySelector('.account-chip')`, 'persisted profile after new-password login');
    checks.push({ check: 'Old password rejected after logout; new password signs in; changed name and photo removal persisted', passed: true });
    if (runtimeErrors.length) throw new Error('Chrome reported JavaScript runtime exceptions.');
    report.passed = true;
    progress('New-password login and persisted profile passed');
    console.log(JSON.stringify({ passed: true, checks: checks.length, runtimeErrors: 0, screenshots: 'docs/preview/profile-settings-*.png' }));
    await send('Browser.close').catch(() => {});
  } catch (error) {
    failure = error;
    report.passed = false; report.failure = redact(error); report.lastCompletedStep = step;
  } finally {
    socket?.close();
    let cleanupFailure;
    for (const child of [browser, server]) { try { await stopOwned(child); } catch (error) { cleanupFailure ??= error; } }
    if (!cleanupFailure && createdOwned) {
      try {
        assertInside(owned, privateRoot);
        if (path.resolve(owned).toLowerCase() !== path.join(privateRoot, `profile-settings-browser-${runId}`).toLowerCase()
          || !/^profile-settings-browser-[a-f0-9]{16}$/.test(path.basename(owned)) || fs.lstatSync(owned).isSymbolicLink()) throw new Error('Refusing cleanup of an unexpected QA directory.');
        fs.rmSync(owned, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
      } catch (error) { cleanupFailure = error; }
    }
    report.realFormResponses = posts;
    report.runtimeErrors = runtimeErrors.length;
    report.cleanup = cleanupFailure ? { completed: false, reason: redact(cleanupFailure), ownedArtifactsRetained: true } : { completed: true };
    const reportPath = path.join(output, 'profile-settings-browser-results.json'); assertInside(reportPath);
    fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
    if (failure || cleanupFailure) throw failure ?? cleanupFailure;
  }
})().catch(error => { console.error(redact(error)); process.exitCode = 1; });
