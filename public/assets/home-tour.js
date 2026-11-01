(() => {
  const root = document.querySelector('[data-home-tour]');
  if (!root) return;
  // Keep this non-modal guide out of the application's scrolling/stacking contexts.
  document.body.append(root);
  const replay = document.querySelector('[data-tour-replay]');
  document.body.append(replay);
  const bubble = root.querySelector('[data-tour-bubble]');
  const cursor = root.querySelector('[data-tour-cursor]');
  const text = root.querySelector('[data-tour-text]');
  const announcement = root.querySelector('[data-tour-announcement]');
  const counter = root.querySelector('[data-tour-counter]');
  const previous = root.querySelector('[data-tour-previous]');
  const next = root.querySelector('[data-tour-next]');
  const pause = root.querySelector('[data-tour-pause]');
  const desktop = matchMedia('(min-width:1024px) and (hover:hover) and (pointer:fine)');
  const reduced = matchMedia('(prefers-reduced-motion:reduce)');
  const signedIn = Boolean(document.body.dataset.authUserId);
  const seenKey = 'analista-tour-seen-v1';
  const dismissedKey = 'analista-tour-reminder-hidden-v1';
  const store = {
    get(key) { try { return sessionStorage.getItem(key); } catch { return null; } },
    set(key, value) { try { sessionStorage.setItem(key, value); } catch {} },
  };
  let running = false, steps = [], index = 0, timer, autoStart, revision = 0;
  let manual = false, paused = false, interacting = false, typing = false, focusOrigin = null;
  let currentTarget = null, currentMessage = '', positionFrame = 0;
  const available = element => {
    if (!element || !element.getClientRects().length) return false;
    const rect = element.getBoundingClientRect();
    if (rect.width < 1 || rect.height < 1 || rect.top < 4 || rect.bottom > innerHeight - 4 || rect.left < 0 || rect.right > innerWidth) return false;
    const nav = element.closest('.sidebar nav');
    if (nav) { const clip = nav.getBoundingClientRect(); if (rect.top < clip.top || rect.bottom > clip.bottom) return false; }
    return true;
  };
  const query = selector => document.querySelector(selector);
  const visible = selector => { const element = query(selector); return available(element) ? element : null; };
  function tourSteps() {
    const intro = { target: visible('.home-intro-art [data-tour-mascot]'), message: 'Hi! I’m Analista. Let me show you around.' };
    const navigation = [
      { selector: '[data-tour-link="evaluators"]', message: 'Find volunteer IT professionals here. Evaluators need admin approval before joining the review process.' },
      { selector: '[data-tour-link="projects"]', message: signedIn ? 'Explore community thesis projects. Share your draft from My projects and tell evaluators where you need help.' : 'Explore community thesis projects. Sign in to share your own draft and tell evaluators where you need help.' },
      { selector: '[data-tour-link="tutorials"]', message: 'Need help with a chapter? Watch free thesis tutorials, from research questions to your final presentation.' },
      { selector: '[data-tour-link="workspace"]', message: 'Track your projects and review progress here. Messages brings you review updates and conversations with your evaluator.' },
      { selector: '[data-tour-link="verification"]', message: 'Applying as an evaluator? Submit your professional details and proof here for a private admin review.' },
    ];
    if (!desktop.matches) {
      const menu = visible('[data-menu]');
      return [intro, ...navigation.slice(0, 3).map(step => ({ target: menu, message: step.message }))];
    }
    const items = navigation.map(step => ({ target: visible(step.selector), message: step.message })).filter(step => step.target);
    return [intro, ...items, { target: visible('.sidebar [data-tour-start]'), message: 'You are ready! Explore at your own pace. You can take this tour again from the sidebar anytime.' }];
  }
  function reminder() {
    replay.hidden = running || store.get(dismissedKey) === '1';
  }
  function stop() {
    if (!running) return;
    running = false;
    revision++;
    clearTimeout(timer);
    cancelAnimationFrame(positionFrame);
    positionFrame = 0;
    const restore = root.contains(document.activeElement);
    root.hidden = true;
    announcement.textContent = '';
    currentTarget = null;
    reminder();
    if (restore) {
      const destination = available(focusOrigin) ? focusOrigin : (!replay.hidden ? replay.querySelector('[data-tour-start]') : query('.sidebar [data-tour-start]'));
      if (available(destination)) destination.focus({ preventScroll: true });
      else document.activeElement?.blur();
    }
  }
  function position() {
    if (!running) return;
    const margin = 12, gap = 18;
    const isVisible = available(currentTarget);
    const rect = isVisible ? currentTarget.getBoundingClientRect() : null;
    const mascot = currentTarget?.matches('[data-tour-mascot]');
    const width = bubble.offsetWidth, height = bubble.offsetHeight;
    const controlsHeight = root.querySelector('.home-tour-controls').offsetHeight;
    const clamp = (value, low, high) => Math.max(low, Math.min(value, Math.max(low, high)));
    let pointerX = rect ? rect.right - 8 : null;
    let pointerY = rect ? rect.top + Math.min(rect.height / 2, 20) : null;
    let x, y;
    if (!desktop.matches) {
      // Touch users advance manually; leave the page and navigation fully usable.
      x = (innerWidth - width) / 2;
      y = innerHeight - height - controlsHeight - 20;
    } else if (rect) {
      x = pointerX + 16;
      if (x + width > innerWidth - margin) x = pointerX - width - 16;
      y = pointerY + 19;
      if (y + height + controlsHeight > innerHeight - margin) y = pointerY - height - controlsHeight - gap;
      // Keep the cursor at the cloud's lower body, with the greeting just beneath.
      // SVG coordinates stay stable while the cloud and laptop animate inside it.
      if (mascot) {
        const svg = currentTarget.closest('svg');
        const matrix = svg.getScreenCTM();
        if (matrix) {
          const anchor = new DOMPoint(7.5, 6.6).matrixTransform(matrix);
          pointerX = anchor.x;
          pointerY = anchor.y;
          x = pointerX - width / 2;
          y = pointerY + 32;
        }
      }
    } else {
      x = Math.max(224, (innerWidth - width) / 2);
      y = 24;
    }
    bubble.dataset.side = rect && x + width < pointerX ? 'left' : 'right';
    bubble.style.transform = `translate3d(${clamp(x, margin, innerWidth - width - margin)}px,${clamp(y, margin, innerHeight - height - controlsHeight - margin)}px,0)`;
    cursor.hidden = !desktop.matches || reduced.matches || !rect;
    cursor.style.transform = `translate3d(${clamp(pointerX ?? x - 22, 4, innerWidth - 28)}px,${clamp(pointerY ?? y + 30, 4, innerHeight - 32)}px,0)`;
  }
  function requestPosition() {
    if (!running || positionFrame) return;
    positionFrame = requestAnimationFrame(() => { positionFrame = 0; position(); });
  }
  function schedule() {
    clearTimeout(timer);
    if (!running || manual || paused || interacting || document.hidden) return;
    timer = setTimeout(() => { if (!document.hidden && !interacting) show(index + 1); }, 8000);
  }
  function show(stepIndex) {
    if (!running) return;
    if (stepIndex >= steps.length) { stop(); return; }
    index = Math.max(0, stepIndex);
    const token = ++revision;
    clearTimeout(timer);
    const step = steps[index];
    currentTarget = step.target;
    currentMessage = step.message;
    bubble.style.width = '';
    text.textContent = '';
    // Reserve the full message height so typing never shifts the bubble or page.
    text.textContent = currentMessage;
    text.style.minHeight = '';
    bubble.style.width = `${bubble.offsetWidth}px`;
    text.style.minHeight = `${text.offsetHeight}px`;
    counter.textContent = `${index + 1} / ${steps.length}`;
    previous.disabled = index === 0;
    next.innerHTML = index === steps.length - 1 ? 'Done' : 'Next <span aria-hidden="true">&rarr;</span>';
    announcement.textContent = `Step ${index + 1} of ${steps.length}. ${currentMessage}`;
    position();
    typing = false;
    if (manual || reduced.matches || paused) { schedule(); return; }
    typing = true;
    text.textContent = '';
    let length = 0;
    const type = () => {
      if (!running || token !== revision) return;
      if (paused || document.hidden) { timer = setTimeout(type, 100); return; }
      bubble.dataset.entering = 'false';
      text.textContent = currentMessage.slice(0, ++length);
      if (length < currentMessage.length) timer = setTimeout(type, /[.,!?]/.test(currentMessage[length - 1]) ? 110 : 17);
      else { typing = false; schedule(); }
    };
    // Allow the pointer to reach the new target before speaking.
    timer = setTimeout(type, 650);
  }
  function start(focusControls = false) {
    clearTimeout(autoStart);
    if (running) { show(0); return; }
    focusOrigin = document.activeElement;
    document.body.classList.remove('menu-open');
    query('[data-menu]')?.setAttribute('aria-expanded', 'false');
    manual = !desktop.matches || reduced.matches;
    paused = false;
    interacting = false;
    pause.textContent = 'Pause';
    pause.setAttribute('aria-label', 'Pause the tour');
    root.dataset.manual = String(manual);
    steps = tourSteps();
    store.set(seenKey, '1');
    running = true;
    replay.hidden = true;
    root.hidden = false;
    cursor.style.transition = 'none';
    cursor.style.transform = `translate3d(${innerWidth + 30}px,${innerHeight * .4}px,0)`;
    cursor.getBoundingClientRect();
    cursor.style.transition = '';
    show(0);
    bubble.dataset.entering = String(!manual && !reduced.matches && !focusControls);
    if (focusControls) next.focus({ preventScroll: true });
  }
  function completeText() { revision++; clearTimeout(timer); typing = false; bubble.dataset.entering = 'false'; text.textContent = currentMessage; }
  document.querySelectorAll('[data-tour-start]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    // Keyboard users get focused controls; pointer replays keep auto-advance running.
    start(event.detail === 0);
  }));
  root.querySelector('[data-tour-close]').addEventListener('click', stop);
  next.addEventListener('click', () => show(index + 1));
  previous.addEventListener('click', () => show(index - 1));
  pause.addEventListener('click', () => {
    completeText();
    paused = !paused;
    pause.textContent = paused ? 'Resume' : 'Pause';
    pause.setAttribute('aria-label', paused ? 'Resume the tour' : 'Pause the tour');
    schedule();
  });
  bubble.addEventListener('mouseenter', () => { interacting = true; if (!typing) clearTimeout(timer); });
  bubble.addEventListener('mouseleave', () => { interacting = bubble.contains(document.activeElement); if (!interacting && !typing) schedule(); });
  bubble.addEventListener('focusin', () => { bubble.dataset.entering = 'false'; interacting = true; if (!typing) clearTimeout(timer); });
  bubble.addEventListener('focusout', () => { interacting = bubble.matches(':hover'); if (!interacting && !typing) schedule(); });
  replay.querySelector('[data-tour-dismiss]').addEventListener('click', () => { store.set(dismissedKey, '1'); replay.hidden = true; const launcher = visible('.sidebar [data-tour-start]') || visible('[data-menu]'); launcher?.focus({ preventScroll: true }); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') { clearTimeout(autoStart); stop(); } });
  window.addEventListener('scroll', requestPosition, { passive: true, capture: true });
  window.addEventListener('resize', requestPosition, { passive: true });
  // Opening the site's menu or following a link ends the guide without intercepting it.
  document.addEventListener('click', event => { if (running && !root.contains(event.target) && !event.target.closest('[data-tour-start]') && event.target.closest('a,button,input,select,textarea')) stop(); });
  window.addEventListener('pagehide', () => { clearTimeout(autoStart); stop(); });
  reduced.addEventListener('change', () => { if (running) { manual = reduced.matches || !desktop.matches; root.dataset.manual = String(manual); completeText(); position(); schedule(); } });
  desktop.addEventListener('change', () => { if (running) stop(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && running && text.textContent === currentMessage) schedule(); });
  const explicit = location.hash === '#tour' || new URLSearchParams(location.search).has('tour');
  if (explicit) {
    if (location.hash === '#tour') history.replaceState(null, '', location.pathname + location.search);
    autoStart = setTimeout(() => start(), 300);
  } else if (!store.get(seenKey) && desktop.matches && !reduced.matches && scrollY < 100) {
    autoStart = setTimeout(() => { if (!document.hidden && !document.body.classList.contains('menu-open') && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) && scrollY < 100) start(false); else reminder(); }, 900);
  } else reminder();
})();
