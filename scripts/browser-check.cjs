// Uses an isolated Chrome debugging session against the local application.
const fs = require('node:fs');
const path = require('node:path');
const origin = 'http://127.0.0.1:8000';
const output = path.resolve('docs/preview');
fs.mkdirSync(output, { recursive: true });
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
  const targets = await (await fetch('http://127.0.0.1:9227/json/list')).json();
  const target = targets.find(t => t.type === 'page');
  if (!target) throw new Error('No local Chrome page found.');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
  let seq = 0;
  const pending = new Map();
  const errors = [];
  ws.addEventListener('message', ({ data }) => {
    const result = JSON.parse(data);
    if (result.id && pending.has(result.id)) {
      const { resolve, reject } = pending.get(result.id); pending.delete(result.id);
      result.error ? reject(new Error(JSON.stringify(result.error))) : resolve(result.result);
    }
    if (result.method === 'Runtime.exceptionThrown') errors.push(result.params.exceptionDetails.text);
  });
  function send(method, params = {}) {
    return new Promise((resolve, reject) => { const id = ++seq; pending.set(id, { resolve, reject }); ws.send(JSON.stringify({ id, method, params })); });
  }
  async function evaluate(expression) {
    const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error(expression + ' => ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
    return r.result.value;
  }
  async function waitFor(expression) {
    for (let attempt = 0; attempt < 80; attempt++) {
      try { if (await evaluate(expression)) return; } catch {}
      await sleep(125);
    }
    throw new Error('Timed out waiting for: ' + expression);
  }
  async function navigate(url) {
    await send('Page.navigate', { url: origin + url });
    await waitFor('document.readyState === "complete" && !!document.querySelector("#main")');
    await evaluate('document.fonts.ready.then(() => true)');
    await sleep(100);
  }
  async function screenshot(name, full = false) {
    const metrics = await send('Page.getLayoutMetrics');
    const options = { format: 'png', captureBeyondViewport: full };
    if (full) options.clip = { x: 0, y: 0, width: metrics.cssContentSize.width, height: metrics.cssContentSize.height, scale: 1 };
    const r = await send('Page.captureScreenshot', options);
    fs.writeFileSync(path.join(output, name), Buffer.from(r.data, 'base64'));
  }
  const results = [];
  let expectedWidth = 1440;
  async function assertLayout(label) {
    const r = await evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth,title:document.querySelector("h1")?.innerText})');
    if (!r.title || r.scroll > expectedWidth + 1 || r.width > expectedWidth + 1) throw new Error(label + ' layout failure: ' + JSON.stringify(r));
    results.push({ page: label, width: r.width, overflow: false });
  }
  async function login(email, password) {
    await navigate('/login');
    await evaluate('document.querySelector("[name=email]").value=' + JSON.stringify(email));
    await evaluate('document.querySelector("[name=password]").value=' + JSON.stringify(password));
    await evaluate('document.querySelector(".auth-container form").requestSubmit()');
    await waitFor('location.pathname === "/account" || location.pathname === "/admin"');
    await waitFor('document.readyState === "complete" && !!document.querySelector("#main")');
  }
  async function logout() {
    await evaluate(`document.querySelector('form[action$="/logout"]').requestSubmit()`);
    await waitFor('location.pathname === "/"');
  }
  try {
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    await send('Network.clearBrowserCookies');
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await navigate('/'); await assertLayout('home desktop');
    const fonts = await evaluate('Promise.all([document.fonts.load("16px Geist"),document.fonts.load("42px \'Geist Pixel\'"),document.fonts.load("12px \'Geist Mono\'")]).then(v=>v.every(a=>a.length>0))');
    if (!fonts) throw new Error('Required Geist fonts did not load.');
    await screenshot('home-desktop.png', true);
    for (const [url,label] of [['/evaluators','evaluators'],['/projects','projects'],['/tutorials','tutorials']]) {
      await navigate(url); await assertLayout(label + ' desktop'); await screenshot(label + '-desktop.png');
    }
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    expectedWidth = 390;
    await navigate('/'); await assertLayout('home mobile'); await screenshot('home-mobile.png', true);
    await evaluate('document.querySelector("[data-menu]").click()');
    if (!await evaluate('document.body.classList.contains("menu-open")')) throw new Error('Mobile menu did not open.');
    await evaluate('document.querySelector("[data-overlay]").click()');
    for (const url of ['/evaluators','/projects','/tutorials','/register']) { await navigate(url); await assertLayout(url + ' mobile'); }
    await screenshot('register-mobile.png', true);
    const studentEmail = process.env.ANALISTA_QA_STUDENT_EMAIL;
    const studentPassword = process.env.ANALISTA_QA_STUDENT_PASSWORD;
    if (studentEmail && studentPassword) {
      await login(studentEmail, studentPassword); await assertLayout('student workspace mobile'); await screenshot('workspace-mobile.png', true);
      await navigate('/account/profile'); await assertLayout('student profile mobile');
      await logout();
    }
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    expectedWidth = 1440;
    const adminEmail = process.env.ANALISTA_QA_ADMIN_EMAIL;
    const adminPassword = process.env.ANALISTA_QA_ADMIN_PASSWORD;
    if (adminEmail && adminPassword) {
      await login(adminEmail, adminPassword); await assertLayout('admin overview'); await screenshot('admin-desktop.png', true);
      for (const section of ['evaluators','projects','reports','users','tutorials','invitations']) { await navigate('/admin?section=' + section); await assertLayout('admin ' + section); }
      await logout();
    }
    if (errors.length) throw new Error('Browser runtime errors: ' + errors.join(', '));
    fs.writeFileSync(path.join(output, 'browser-results.json'), JSON.stringify({ fontsLoaded: fonts, runtimeErrors: errors, checks: results }, null, 2));
    console.log(JSON.stringify({ passed: results.length, fontsLoaded: fonts, runtimeErrors: errors.length, screenshots: output }));
  } finally { ws.close(); }
})().catch(e => { console.error(e.message); process.exitCode = 1; });
