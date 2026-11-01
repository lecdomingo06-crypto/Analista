// Isolated end-to-end verification QA. Never opens the configured application DB.
// Requires Node 22+, PHP, and Chrome. Ports 8002 and 9228 must be free.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const privateRoot = path.join(root, 'storage', 'app', 'private');
const database = path.join(privateRoot, 'verification-browser-qa.sqlite');
const runId = crypto.randomBytes(8).toString('hex');
const inspectOnly = process.argv.includes('--inspect-mobile');
const existingProfile = process.argv.find(argument => argument.startsWith('--profile='))?.slice('--profile='.length);
if (inspectOnly && !/^verification-browser-profile-[a-f0-9]{16}$/.test(existingProfile ?? '')) {
  throw new Error('Read-only inspection requires --profile=verification-browser-profile-<existing run id>.');
}
const profile = path.join(privateRoot, inspectOnly ? existingProfile : `verification-browser-profile-${runId}`);
const seedScript = path.join(privateRoot, `verification-browser-seed-${runId}.php`);
const origin = 'http://127.0.0.1:8002';
const debuggerOrigin = 'http://127.0.0.1:9228';
const output = path.join(root, 'docs', 'preview');
const adminPassword = crypto.randomBytes(30).toString('base64url');
const applicantPassword = crypto.randomBytes(30).toString('base64url');
const applicantEmail = 'verification-applicant@example.test';
const adminEmail = 'verification-admin@example.test';
const applicantName = 'QA Verification Applicant';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const hasExited = child => child.exitCode !== null || child.signalCode !== null;

function insideWorkspace(target) {
  const relative = path.relative(fs.realpathSync(root), path.resolve(target));
  return relative !== '' && !relative.startsWith('..') && !path.isAbsolute(relative);
}
function assertOwnedPath(target) {
  if (!insideWorkspace(target)) throw new Error('QA path escaped the intended workspace.');
  if (fs.existsSync(target) && !insideWorkspace(fs.realpathSync(target))) {
    throw new Error('QA path resolves outside the intended workspace.');
  }
}
function cleanMessage(error) {
  return String(error?.message ?? error).replaceAll(adminPassword, '[redacted]').replaceAll(applicantPassword, '[redacted]');
}
async function assertPortFree(port) {
  await new Promise((resolve, reject) => {
    const listener = net.createServer();
    listener.once('error', () => reject(new Error(`QA port ${port} is already in use; existing processes were left untouched.`)));
    listener.listen(port, '127.0.0.1', () => listener.close(resolve));
  });
}
async function waitForHttp(url, child) {
  for (let attempt = 0; attempt < 160; attempt++) {
    if (hasExited(child)) throw new Error('An owned QA process exited during startup.');
    try {
      const response = await fetch(url, { signal: AbortSignal.timeout(1000) });
      if (response.ok) return;
    } catch {}
    await sleep(100);
  }
  throw new Error('An isolated QA service did not become ready.');
}
async function stopOwned(child) {
  if (!child || hasExited(child)) return;
  child.kill();
  for (let attempt = 0; attempt < 60 && !hasExited(child); attempt++) await sleep(100);
  if (!hasExited(child)) throw new Error('An owned QA process did not exit; its files were retained.');
}

(async () => {
  let server;
  let browser;
  let ws;
  let createdDatabase = false;
  let createdProfile = false;
  let createdSeedScript = false;
  let failure;
  try {
    fs.mkdirSync(privateRoot, { recursive: true });
    fs.mkdirSync(output, { recursive: true });
    [privateRoot, database, profile, seedScript, output].forEach(assertOwnedPath);
    if (fs.existsSync(path.join(root, 'bootstrap', 'cache', 'config.php'))) {
      throw new Error('QA requires uncached configuration so its DB and cookie overrides are effective.');
    }
    const envText = fs.readFileSync(path.join(root, '.env'), 'utf8');
    const configuredDatabase = process.env.DB_DATABASE ?? envText.match(/^DB_DATABASE=(.*)$/m)?.[1]?.trim().replace(/^(['"])(.*)\1$/, '$2');
    if (configuredDatabase && configuredDatabase !== ':memory:' && path.resolve(root, configuredDatabase).toLowerCase() === database.toLowerCase()) {
      throw new Error('The QA filename matches the configured live DB. Refusing to continue.');
    }
    if (inspectOnly && (!fs.existsSync(database) || !fs.existsSync(profile))) {
      throw new Error('Read-only inspection requires the existing isolated QA database and browser profile.');
    }
    if (!inspectOnly && (fs.existsSync(database) || fs.existsSync(profile) || fs.existsSync(seedScript))) {
      throw new Error('A QA target already exists. Existing files were left untouched.');
    }
    await assertPortFree(8002);
    await assertPortFree(9228);
    const chrome = [
      process.env.CHROME_PATH,
      'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
      'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    ].find(candidate => candidate && fs.existsSync(candidate));
    if (!chrome) throw new Error('Chrome was not found. Set CHROME_PATH to its executable.');

    const qaEnvironment = {
      ...process.env,
      APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: `base64:${crypto.randomBytes(32).toString('base64')}`,
      DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '', SESSION_DRIVER: inspectOnly ? 'array' : 'database',
      SESSION_COOKIE: `analista_verification_qa_${runId}`, SESSION_DOMAIN: 'null', SESSION_SECURE_COOKIE: 'false',
      CACHE_STORE: 'array', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr', APP_DEMO: 'false',
      QUEUE_CONNECTION: 'sync', BCRYPT_ROUNDS: '4',
      QA_DATABASE: database, QA_APPLICANT_PASSWORD: applicantPassword, QA_ADMIN_PASSWORD: adminPassword,
    };
    let ids;
    const php = process.env.PHP_BINARY ?? 'php';
    if (!inspectOnly) {
    fs.writeFileSync(database, '', { flag: 'wx' });
    createdDatabase = true;
    fs.mkdirSync(profile);
    createdProfile = true;
    fs.writeFileSync(seedScript, `<?php
require ${JSON.stringify(path.join(root, 'vendor', 'autoload.php')).replaceAll('\\\\', '/')};
$app = require ${JSON.stringify(path.join(root, 'bootstrap', 'app.php')).replaceAll('\\\\', '/')};
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if (!app()->environment('testing') || config('database.default') !== 'sqlite'
    || Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName() !== getenv('QA_DATABASE')
    || config('app.url') !== '${origin}') { throw new RuntimeException('Isolated QA environment check failed.'); }
Illuminate\\Support\\Facades\\Artisan::call('migrate', ['--force' => true]);
$admin = App\\Models\\User::create(['name' => 'QA Verification Admin', 'email' => '${adminEmail}', 'password' => getenv('QA_ADMIN_PASSWORD'), 'role' => 'admin', 'accepting_requests' => false]);
$applicant = App\\Models\\User::create([
    'name' => '${applicantName}', 'email' => '${applicantEmail}', 'password' => getenv('QA_APPLICANT_PASSWORD'),
    'role' => 'evaluator', 'evaluator_status' => 'pending', 'accepting_requests' => false,
    'headline' => 'QA software engineer and volunteer thesis reviewer',
    'bio' => 'I help student teams clarify database relationships, define useful evaluation plans, and explain the limits of their research evidence.',
    'expertise' => ['Web', 'Database'], 'skills' => ['PHP', 'MySQL'], 'experience_years' => 5,
    'capacity' => 2, 'review_modes' => ['online'], 'portfolio_url' => 'https://example.test/qa-portfolio',
]);
echo json_encode(['applicantId' => $applicant->id, 'adminId' => $admin->id]);
`, { flag: 'wx' });
    createdSeedScript = true;
    ids = JSON.parse(execFileSync(php, [seedScript], { cwd: root, env: qaEnvironment, windowsHide: true, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }));
    }
    server = spawn(php, ['-S', '127.0.0.1:8002', '-t', 'public', 'dev-server.php'], {
      cwd: root, env: qaEnvironment, windowsHide: true, stdio: ['ignore', 'ignore', 'ignore'],
    });
    await waitForHttp(origin, server);
    browser = spawn(chrome, [
      '--headless=new', '--remote-debugging-port=9228', '--remote-debugging-address=127.0.0.1',
      `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check',
      '--disable-background-networking', '--disable-sync', '--disable-extensions', 'about:blank',
    ], { cwd: root, windowsHide: true, stdio: ['ignore', 'ignore', 'ignore'] });
    await waitForHttp(`${debuggerOrigin}/json/version`, browser);
    const targets = await (await fetch(`${debuggerOrigin}/json/list`)).json();
    const target = targets.find(item => item.type === 'page');
    if (!target) throw new Error('The owned QA browser has no page target.');
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
      ws.addEventListener('open', resolve, { once: true });
      ws.addEventListener('error', () => reject(new Error('Could not connect to the owned QA browser.')), { once: true });
    });
    let seq = 0;
    const pending = new Map();
    const runtimeErrors = [];
    ws.addEventListener('message', ({ data }) => {
      const result = JSON.parse(data);
      if (result.id && pending.has(result.id)) {
        const { resolve, reject } = pending.get(result.id);
        pending.delete(result.id);
        result.error ? reject(new Error(result.error.message)) : resolve(result.result);
      }
      if (result.method === 'Runtime.exceptionThrown') runtimeErrors.push(result.params.exceptionDetails.text);
    });
    function send(method, params = {}) {
      return new Promise((resolve, reject) => {
        const id = ++seq;
        const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`Browser command timed out: ${method}`)); }, 15000);
        pending.set(id, { resolve: value => { clearTimeout(timeout); resolve(value); }, reject: error => { clearTimeout(timeout); reject(error); } });
        ws.send(JSON.stringify({ id, method, params }));
      });
    }
    async function evaluate(expression) {
      const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
      if (result.exceptionDetails) throw new Error('A browser QA assertion could not be evaluated.');
      return result.result.value;
    }
    async function waitFor(expression) {
      for (let attempt = 0; attempt < 160; attempt++) {
        try { if (await evaluate(expression)) return; } catch {}
        await sleep(125);
      }
      throw new Error('Timed out waiting for a verification QA page state.');
    }
    async function navigate(route) {
      await send('Page.navigate', { url: origin + route });
      await waitFor(`location.pathname === ${JSON.stringify(route.split('?')[0])} && document.readyState === 'complete' && !!document.querySelector('#main')`);
      await evaluate('document.fonts.ready.then(() => true)');
    }
    async function screenshot(filename, full = true) {
      const metrics = await send('Page.getLayoutMetrics');
      const options = { format: 'png', captureBeyondViewport: full };
      if (full) options.clip = { x: 0, y: 0, width: metrics.cssContentSize.width, height: metrics.cssContentSize.height, scale: 1 };
      const result = await send('Page.captureScreenshot', options);
      const filenamePath = path.join(output, filename);
      assertOwnedPath(filenamePath);
      fs.writeFileSync(filenamePath, Buffer.from(result.data, 'base64'));
    }
    const checks = [];
    let expectedWidth = 1440;
    async function assertLayout(label) {
      const result = await evaluate(`({ width: innerWidth, scroll: document.documentElement.scrollWidth, heading: document.querySelector('h1')?.innerText })`);
      if (!result.heading || result.scroll > expectedWidth + 1 || result.width !== expectedWidth) {
        throw new Error(`${label} has horizontal overflow or a missing heading: ${JSON.stringify(result)}`);
      }
      if (expectedWidth === 390 && !await evaluate(`document.body.classList.contains('menu-open') || document.querySelector('.sidebar').getBoundingClientRect().right <= 1`)) {
        throw new Error(`${label} has an offcanvas sidebar overlapping the mobile viewport.`);
      }
      checks.push({ page: label, viewport: expectedWidth, horizontalOverflow: false });
    }
    async function viewport(width) {
      expectedWidth = width;
      await send('Emulation.setDeviceMetricsOverride', { width, height: width === 390 ? 844 : 1000, deviceScaleFactor: 1, mobile: width === 390 });
      // Let the responsive sidebar transform finish before taking screenshots.
      await sleep(300);
    }
    async function login(email, password) {
      await navigate('/login');
      await evaluate(`document.querySelector('[name=email]').value = ${JSON.stringify(email)}; document.querySelector('[name=password]').value = ${JSON.stringify(password)}; document.querySelector('.auth-container form').requestSubmit();`);
      await waitFor(`['/account', '/admin'].includes(location.pathname) && document.readyState === 'complete' && !!document.querySelector('#main')`);
    }
    async function logout() {
      await evaluate(`document.querySelector('form[action$="/logout"]').requestSubmit()`);
      await waitFor(`location.pathname === '/' && document.readyState === 'complete'`);
    }
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');
    await send('Network.clearBrowserCookies');
    if (inspectOnly) {
      await viewport(390);
      await navigate('/register');
      await assertLayout('Read-only registration sidebar inspection');
      const geometry = `(() => { const sidebar = document.querySelector('.sidebar'); const overlay = document.querySelector('[data-overlay]'); const hit = document.elementFromPoint(10,150); const rect = sidebar.getBoundingClientRect(); return {scrollX,scrollY,documentScrollLeft:document.documentElement.scrollLeft,scrollingElementLeft:document.scrollingElement.scrollLeft,sidebar:{left:rect.left,right:rect.right,width:rect.width},overlayDisplay:getComputedStyle(overlay).display,bodyClass:document.body.className,hit:{tag:hit?.tagName,className:hit?.className,insideSidebar:!!hit?.closest('.sidebar')},mainLeft:document.querySelector('#main').getBoundingClientRect().left}; })()`;
      const before = await evaluate(geometry);
      const metrics = await send('Page.getLayoutMetrics');
      await screenshot('verification-mobile-viewport.png', false);
      await screenshot('verification-mobile-capture-diagnostic.png');
      const after = await evaluate(geometry);
      await screenshot('verification-mobile-viewport-after-full.png', false);
      if (before.scrollX !== 0 || before.documentScrollLeft !== 0 || before.sidebar.right > 1 || before.overlayDisplay !== 'none' || before.hit.insideSidebar) {
        throw new Error(`Mobile sidebar geometry is incorrect: ${JSON.stringify(before)}`);
      }
      fs.writeFileSync(path.join(output, 'verification-mobile-geometry.json'), JSON.stringify({page:'/register',before,after,metrics}, null, 2));
      console.log(JSON.stringify({readOnlyMobileInspection:true,before,after}));
      await send('Browser.close').catch(() => {});
      return;
    }
    await viewport(1440);
    await login(applicantEmail, applicantPassword);
    await navigate('/account/evaluator-verification');
    await assertLayout('Applicant verification desktop');
    for (const kind of ['document', 'reference', 'portfolio']) {
      await evaluate(`document.querySelector('[data-evidence-kind][value=${kind}]').click()`);
      const correct = await evaluate(`Array.from(document.querySelectorAll('[data-evidence-fields]')).every(group => {
        const selected = group.dataset.evidenceFields.split(' ').includes(${JSON.stringify(kind)});
        return group.hidden === !selected && Array.from(group.querySelectorAll('input, textarea, select')).every(input => input.disabled === !selected && (!input.hasAttribute('data-evidence-required') || input.required === selected));
      })`);
      if (!correct) throw new Error(`The ${kind} evidence option did not disable unselected inputs correctly.`);
      checks.push({ interaction: `${kind} evidence fields`, disabledUnselected: true });
    }
    const fields = {
      background_type: 'freelance', organization: 'Isolated QA practice',
      background_summary: 'I have contributed to database-backed web applications and test plans for community projects. I can review schema relationships, access controls, and the connection between research objectives and evaluation tasks.',
      evidence_url: 'https://example.test/qa-portfolio',
      evidence_explanation: 'This isolated QA portfolio explains the contribution being assessed, identifies the author, and provides a clear example of database design decisions that an administrator can check.',
      work_sample_url: 'https://example.test/qa-work-sample',
      work_sample_explanation: 'I designed the sample database relationships and documented validation rules and access permissions. These examples connect directly to the web and database expertise claimed in my professional profile.',
      sample_review: 'The survey of five classmates supports a small preliminary observation, not a claim about every student and teacher. Define a sampling plan and state the limits of the evidence. Replace plain-text passwords with appropriate password hashing and require role-based access permissions. Build functional, access-control, and usability tests linked to each research objective, with clear expected outcomes and a record of the observed results.',
    };
    await evaluate(`Object.entries(${JSON.stringify(fields)}).forEach(([name, value]) => { const input = document.querySelector('[name=' + name + ']'); input.value = value; input.dispatchEvent(new Event('input', {bubbles:true})); }); document.querySelector('[name=attestation]').checked = true;`);
    if (!await evaluate(`document.querySelector('.verification-form').checkValidity()`)) throw new Error('The completed portfolio application was rejected by browser form validation.');
    await screenshot('verification-desktop.png');
    await viewport(390);
    await assertLayout('Applicant verification mobile');
    await screenshot('verification-mobile.png');
    await evaluate(`document.querySelector('.verification-form').requestSubmit()`);
    await waitFor(`document.readyState === 'complete' && !document.querySelector('form.verification-form') && document.querySelector('#main')?.innerText.includes('with the admin team')`);
    await assertLayout('Applicant pending verification mobile');
    await screenshot('verification-pending-mobile.png');
    await logout();
    await viewport(1440);
    await navigate('/evaluators');
    if (await evaluate(`document.querySelectorAll('.reviewer-card').length`)) throw new Error('An unapproved QA evaluator became publicly visible.');
    checks.push({ interaction: 'Pending applicant hidden', passed: true });
    await login(adminEmail, adminPassword);
    await navigate(`/admin/evaluators/${ids.applicantId}`);
    await assertLayout('Admin verification desktop');
    if (!await evaluate(`!!document.querySelector('button[value=approve]') && !document.querySelector('button[value=approve]').disabled`)) throw new Error('A complete QA application could not be reviewed for approval.');
    await screenshot('verification-admin-desktop.png');
    await viewport(390);
    await assertLayout('Admin verification mobile');
    await screenshot('verification-admin-mobile.png');
    await evaluate(`document.querySelectorAll('[name^="checks["]').forEach(input => input.checked = true); document.querySelector('[name=reason]').value = 'Reviewed the professional background, contribution evidence, work sample, and constructive practice feedback. The submitted information supports community review participation.'; const button = document.querySelector('button[value=approve]'); button.form.requestSubmit(button);`);
    await waitFor(`document.readyState === 'complete' && !document.querySelector('button[value=approve]') && document.querySelector('#main')?.innerText.includes('review record has been saved')`);
    await assertLayout('Admin approved verification mobile');
    await logout();
    await navigate('/evaluators');
    const listedNames = await evaluate(`Array.from(document.querySelectorAll('.reviewer-card .card-title')).map(link => link.innerText.trim())`);
    if (listedNames.length !== 1 || !listedNames[0].includes(applicantName)) throw new Error('The isolated directory did not contain exactly its approved QA applicant.');
    await assertLayout('Approved evaluator directory mobile');
    await screenshot('verification-approved-directory-mobile.png');
    checks.push({ interaction: 'Admin approval publishes only QA applicant', passed: true });
    if (runtimeErrors.length) throw new Error('The owned QA browser reported JavaScript runtime errors.');
    const results = { isolatedDatabase: true, ports: { application: 8002, browser: 9228 }, runtimeErrors: runtimeErrors.length, checks };
    fs.writeFileSync(path.join(output, 'verification-browser-results.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify({ passed: checks.length, runtimeErrors: runtimeErrors.length, isolatedDatabase: true, screenshots: 'docs/preview/verification-*.png' }));
    await send('Browser.close').catch(() => {});
  } catch (error) {
    failure = error;
  } finally {
    ws?.close();
    let cleanupFailure;
    for (const child of [browser, server]) {
      try { await stopOwned(child); } catch (error) { cleanupFailure ??= error; }
    }
    if (!cleanupFailure) {
      if (createdSeedScript) { assertOwnedPath(seedScript); fs.unlinkSync(seedScript); }
      if (createdDatabase) {
        for (const suffix of ['', '-journal', '-shm', '-wal']) {
          const target = database + suffix;
          assertOwnedPath(target);
          if (fs.existsSync(target)) fs.unlinkSync(target);
        }
      }
      if (createdProfile) { assertOwnedPath(profile); fs.rmSync(profile, { recursive: true, force: true }); }
    }
    if (failure || cleanupFailure) throw failure ?? cleanupFailure;
  }
})().catch(error => { console.error(cleanMessage(error)); process.exitCode = 1; });
