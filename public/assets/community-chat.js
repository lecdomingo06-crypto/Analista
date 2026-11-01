(() => {
  const root = document.querySelector('[data-community-chat]');
  const dialog = document.querySelector('[data-community-dialog]');
  if (!root || !(dialog instanceof HTMLDialogElement)) return;

  const viewers = root.querySelector('[data-community-viewers]');
  const viewing = root.querySelector('[data-community-viewing]');
  const messages = dialog.querySelector('[data-community-messages]');
  const empty = dialog.querySelector('[data-community-empty]');
  const roomStatus = dialog.querySelector('[data-community-room-status]');
  const feedback = dialog.querySelector('[data-community-feedback]');
  const retry = dialog.querySelector('[data-community-retry]');
  const identityLabel = dialog.querySelector('[data-community-as]');
  const identityForm = dialog.querySelector('[data-community-identity-form]');
  const messageForm = dialog.querySelector('[data-community-message-form]');
  const nameInput = identityForm?.querySelector('[name="name"]');
  const bodyInput = messageForm?.querySelector('[name="body"]');
  const identityButton = identityForm?.querySelector('[type="submit"]');
  const sendButton = messageForm?.querySelector('[type="submit"]');
  if (!viewers || !viewing || !messages || !identityForm || !messageForm || !nameInput || !bodyInput) return;

  const endpoints = {};
  for (const key of ['stateUrl', 'presenceUrl', 'identityUrl', 'sendUrl']) {
    try {
      const url = new URL(root.dataset[key], location.href);
      if (!root.dataset[key] || url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return;
      endpoints[key] = url;
    } catch { return; }
  }

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  const rows = new Map();
  const nicknameKey = 'analista-community-nickname';
  const originalSendLabel = sendButton?.textContent || 'Send';
  const originalIdentityLabel = identityButton?.textContent || 'Next';
  let viewer = null;
  let viewerRevision = 0;
  let cursor = 0;
  let loaded = false;
  let stateBusy = false;
  let presenceBusy = false;
  let presenceReady = false;
  let stateAfterPresence = false;
  let identityBusy = false;
  let sendBusy = false;
  let stateController = null;
  let stateTimer = null;
  let presenceTimer = null;
  let retryTimer = null;
  let writeRetryAt = 0;
  let retryAt = 0;
  let feedbackScope = '';
  let nicknameRestored = false;
  let returnFocus = null;
  let previousOverflow = '';
  let stopped = false;

  const canPoll = () => !stopped && !document.hidden && navigator.onLine !== false;
  const positiveId = value => Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : 0;
  const priority = scope => scope === 'message' || scope === 'identity' ? 2 : scope === 'offline' ? 1 : 0;

  function refreshButtons() {
    const waiting = Date.now() < writeRetryAt;
    if (identityButton) identityButton.disabled = identityBusy || waiting;
    if (sendButton) sendButton.disabled = sendBusy || waiting || viewer?.can_send === false;
    if (retry) retry.disabled = Date.now() < retryAt;
  }

  function setFeedback(text, scope = '', until = 0) {
    if (feedbackScope && priority(feedbackScope) > priority(scope)) return;
    feedbackScope = scope;
    if (feedback) feedback.textContent = text;
    retryAt = until;
    if (retry) {
      retry.hidden = !scope;
      retry.textContent = scope === 'message' ? 'Review message' : scope === 'identity' ? 'Review name' : 'Retry connection';
    }
    clearTimeout(retryTimer);
    if (until > Date.now()) retryTimer = setTimeout(refreshButtons, until - Date.now() + 30);
    refreshButtons();
  }

  function clearFeedback(scope) {
    if (feedbackScope !== scope && !(scope === 'state' && feedbackScope === 'offline')) return;
    feedbackScope = '';
    if (feedback) feedback.textContent = '';
    if (retry) retry.hidden = true;
  }

  function report(error, scope) {
    let text = 'The chat could not connect. Please try again.';
    if (scope === 'message') text = 'Your message could not be sent. Your text is still here; please try again.';
    if (scope === 'identity') text = 'Your name could not be saved. Please try again.';
    if (error.status === 419 || error.status === 401) text = 'Your session changed or expired. Reload this page before trying again.';
    if (error.status === 403) text = 'Sending is unavailable for this account. You can still read the conversation.';
    if (error.status === 422) {
      const field = scope === 'identity' ? 'name' : 'body';
      const validation = error.data?.errors?.[field];
      text = Array.isArray(validation) && typeof validation[0] === 'string' ? validation[0] : 'Please check your input and try again.';
    }
    let until = 0;
    if (error.status === 429) {
      const wait = error.retryAfter || 10000;
      until = Date.now() + wait;
      if (scope === 'message' || scope === 'identity') writeRetryAt = until;
      text = `Please wait ${Math.ceil(wait / 1000)} seconds before trying again.`;
    }
    setFeedback(text, scope, until);
  }

  async function request(url, { method = 'GET', body, signal } = {}) {
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') {
      if (!csrf) throw { status: 419 };
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-TOKEN'] = csrf;
    }
    const response = await fetch(url, {
      method, credentials: 'same-origin', cache: 'no-store', headers, signal,
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || response.redirected) {
      const rawWait = response.headers.get('Retry-After');
      const seconds = Number(rawWait);
      const wait = rawWait && Number.isFinite(seconds) ? seconds * 1000 : Date.parse(rawWait || '') - Date.now();
      throw { status: response.redirected ? 401 : response.status, data, retryAfter: Math.min(300000, Math.max(1000, wait || 10000)) };
    }
    if (!data || typeof data !== 'object' || Array.isArray(data)) throw { status: 0 };
    return data;
  }

  function face(seed) {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 32 32');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('class', 'community-face');
    let hash = 0;
    for (const character of String(seed || 'guest')) hash = (Math.imul(hash, 31) + character.codePointAt(0)) >>> 0;
    const shape = (tag, attributes) => {
      const element = document.createElementNS(ns, tag);
      Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, value));
      svg.append(element);
    };
    shape('circle', { cx: 16, cy: 16, r: 16, fill: '#f2f2f2' });
    shape('path', { d: 'M6 32c0-7 4-11 10-11s10 4 10 11', fill: hash % 2 ? '#d7d7d7' : '#424242' });
    shape('ellipse', { cx: 16, cy: 14, rx: 7, ry: 9, fill: '#fff', stroke: '#333', 'stroke-width': 1 });
    const hair = [
      'M9 14V9c0-7 14-7 14 0v5l-3-7c-3 4-7 2-11 7Z',
      'M9 15C4 3 19 0 23 8v8l-3-8c-3 3-7 3-10 3Z',
      'M8 17V9c0-8 16-8 16 0v10l-3-10-5-3-5 6-1 5Z',
      'M9 11C5 5 11 2 14 5c3-5 11-1 9 6l-5-4-9 6Z',
    ];
    shape('path', { d: hair[hash % hair.length], fill: '#303030' });
    shape('path', { d: 'M13 14v1m6-1v1', stroke: '#222', 'stroke-width': 1.5, 'stroke-linecap': 'round' });
    shape('path', { d: hash % 3 === 0 ? 'M13 18q3 3 6 0' : 'M14 19h4', fill: 'none', stroke: '#444', 'stroke-width': 1, 'stroke-linecap': 'round' });
    return svg;
  }

  function avatar(person, className) {
    const wrapper = document.createElement('span');
    wrapper.className = className;
    wrapper.setAttribute('aria-hidden', 'true');
    const fallback = () => {
      if (person?.guest) wrapper.replaceChildren(face(person.id || person.name));
      else {
        const initials = document.createElement('span');
        initials.className = 'community-avatar-initials';
        initials.textContent = String(person?.name || 'Member').trim().split(/\s+/).slice(0, 2).map(word => Array.from(word)[0] || '').join('').toUpperCase();
        wrapper.replaceChildren(initials);
      }
    };
    let imageUrl = null;
    try {
      if (person?.avatar_url) {
        const url = new URL(person.avatar_url, location.href);
        if (url.origin === location.origin && /^https?:$/.test(url.protocol)) imageUrl = url.href;
      }
    } catch { /* Invalid or external image URLs use a local avatar. */ }
    if (imageUrl) {
      const image = document.createElement('img');
      image.alt = '';
      image.loading = 'lazy';
      image.decoding = 'async';
      image.addEventListener('error', fallback, { once: true });
      image.src = imageUrl;
      wrapper.append(image);
    } else fallback();
    return wrapper;
  }

  function applyPresence(presence) {
    if (!presence || !Number.isSafeInteger(Number(presence.count)) || Number(presence.count) < 0) return;
    const count = Number(presence.count);
    const number = document.createElement('strong');
    number.textContent = String(count);
    viewing.replaceChildren(number, document.createTextNode(` ${count === 1 ? 'person' : 'people'} viewing now`));
    viewers.replaceChildren(...(Array.isArray(presence.visitors) ? presence.visitors.slice(0, 4) : []).map(person => avatar(person, 'community-viewer-avatar')));
    if (roomStatus) roomStatus.textContent = `Public room · ${count} ${count === 1 ? 'person' : 'people'} online`;
  }

  function applyViewer(nextViewer, revision) {
    if (!nextViewer || typeof nextViewer !== 'object' || revision !== viewerRevision) return;
    viewer = nextViewer;
    const needsName = Boolean(viewer.needs_name);
    identityForm.hidden = !needsName;
    messageForm.hidden = needsName || viewer.can_send === false;
    if (identityLabel) identityLabel.textContent = needsName
      ? 'Choose a guest name to join the conversation.'
      : viewer.can_send === false ? 'You can read this conversation. Sending is unavailable for your account.'
        : `Chatting as ${String(viewer.name || 'Guest')}${viewer.guest ? ' · Guest' : ''}`;
    if (needsName && !nicknameRestored) {
      nicknameRestored = true;
      try { if (!nameInput.value) nameInput.value = (localStorage.getItem(nicknameKey) || '').slice(0, 40); } catch {}
    }
    refreshButtons();
  }

  function applyData(data, revision) {
    applyViewer(data.viewer, revision);
    applyPresence(data.presence);
  }

  function relativeTime(iso) {
    const date = new Date(iso);
    if (!Number.isFinite(date.getTime())) return '';
    const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }

  function renderMessage(message) {
    const row = document.createElement('article');
    row.className = 'community-message';
    row.classList.toggle('is-own', Boolean(message.own));
    row.dataset.messageId = String(message.id);
    const author = message.author || {};
    const content = document.createElement('div');
    content.className = 'community-message-content';
    const meta = document.createElement('div');
    meta.className = 'community-message-meta';
    const name = document.createElement('strong');
    name.textContent = String(author.name || 'Guest');
    meta.append(name);
    if (author.guest) {
      const guest = document.createElement('span');
      guest.className = 'community-guest';
      guest.textContent = 'Guest';
      meta.append(guest);
    }
    const date = new Date(message.created_at);
    if (Number.isFinite(date.getTime())) {
      const time = document.createElement('time');
      time.dateTime = date.toISOString();
      time.title = date.toLocaleString();
      time.textContent = relativeTime(time.dateTime);
      meta.append(time);
    }
    const body = document.createElement('p');
    body.className = 'community-message-body';
    body.textContent = String(message.body || '');
    content.append(meta, body);
    row.append(avatar(author, 'community-avatar'), content);
    return row;
  }

  function mergeMessages(batch, forceBottom = false) {
    const nearBottom = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 48;
    const oldHeight = messages.scrollHeight;
    const oldTop = messages.scrollTop;
    let removedHeight = 0;
    const ordered = Array.isArray(batch) ? batch.filter(message => positiveId(message.id)).sort((a, b) => a.id - b.id) : [];
    for (const message of ordered) {
      const id = positiveId(message.id);
      if (rows.has(id)) continue;
      const row = renderMessage(message);
      rows.set(id, row);
      const following = [...rows.keys()].filter(key => key > id).sort((a, b) => a - b)[0];
      messages.insertBefore(row, following ? rows.get(following) : null);
    }
    while (rows.size > 100) {
      const oldest = Math.min(...rows.keys());
      const row = rows.get(oldest);
      const next = row.nextElementSibling;
      removedHeight += next ? next.offsetTop - row.offsetTop : row.offsetHeight;
      row.remove();
      rows.delete(oldest);
    }
    if (empty) {
      // Keep the first real message as the first child so its auto margin can bottom-anchor short histories.
      if (rows.size > 0) empty.remove();
      else if (loaded) empty.textContent = 'No messages yet. Say hello.';
    }
    if (forceBottom || nearBottom) messages.scrollTop = messages.scrollHeight;
    else if (removedHeight) messages.scrollTop = Math.max(0, oldTop - removedHeight);
    else if (messages.scrollHeight !== oldHeight) messages.scrollTop = oldTop;
    messages.querySelectorAll('time[datetime]').forEach(time => { time.textContent = relativeTime(time.dateTime); });
  }

  function scheduleState(delay = 8000) {
    clearTimeout(stateTimer);
    if (dialog.open && canPoll()) stateTimer = setTimeout(loadState, delay);
  }

  async function loadState() {
    if (!dialog.open || !canPoll() || stateBusy) return;
    // The first presence request establishes the guest session. A concurrent first read could create a second guest key.
    if (!presenceReady && presenceBusy) { stateAfterPresence = true; return; }
    stateBusy = true;
    const controller = new AbortController();
    stateController = controller;
    const timeout = setTimeout(() => controller.abort('timeout'), 12000);
    const revision = viewerRevision;
    const initial = !loaded;
    const url = new URL(endpoints.stateUrl);
    if (cursor) url.searchParams.set('after', String(cursor));
    let delay = 8000;
    messages.setAttribute('aria-busy', 'true');
    try {
      const data = await request(url, { signal: controller.signal });
      if (!dialog.open || controller.signal.aborted) return;
      applyData(data, revision);
      loaded = true;
      mergeMessages(data.messages, initial);
      const next = positiveId(data.next_after_id);
      if (next) cursor = Math.max(cursor, next);
      clearFeedback('state');
      const active = document.activeElement;
      if (initial && !identityBusy && !sendBusy && (active === dialog || active?.matches('[data-community-close]') || !dialog.contains(active))) focusComposer();
    } catch (error) {
      if (dialog.open && !(controller.signal.aborted && controller.signal.reason !== 'timeout')) {
        report(error, 'state');
        if (!loaded && empty) empty.textContent = 'The conversation is unavailable. Please try again.';
        delay = Math.max(delay, error.retryAfter || 0);
      }
    } finally {
      clearTimeout(timeout);
      if (stateController === controller) stateController = null;
      stateBusy = false;
      messages.setAttribute('aria-busy', 'false');
      scheduleState(controller.signal.aborted && controller.signal.reason !== 'timeout' ? 0 : delay);
    }
  }

  function schedulePresence(delay = 30000) {
    clearTimeout(presenceTimer);
    if (canPoll()) presenceTimer = setTimeout(heartbeat, delay);
  }

  async function heartbeat() {
    if (!canPoll() || presenceBusy) return;
    presenceBusy = true;
    const revision = viewerRevision;
    let delay = 30000;
    try {
      applyData(await request(endpoints.presenceUrl, { method: 'POST', body: {} }), revision);
      clearFeedback('presence');
    } catch (error) {
      if (!viewing.querySelector('strong')) viewing.textContent = 'Viewer count unavailable';
      if (dialog.open) report(error, 'presence');
      delay = Math.max(delay, error.retryAfter || 0);
    } finally {
      presenceReady = true;
      presenceBusy = false;
      schedulePresence(delay);
      if (stateAfterPresence) {
        stateAfterPresence = false;
        if (dialog.open) loadState();
      }
    }
  }

  function focusComposer() {
    const field = !identityForm.hidden ? nameInput : !messageForm.hidden ? bodyInput : dialog.querySelector('[data-community-close]');
    field?.focus({ preventScroll: true });
  }

  function visibleControl(control) {
    if (!control?.isConnected || control.disabled) return false;
    const bounds = control.getBoundingClientRect();
    const style = getComputedStyle(control);
    return bounds.width > 0 && bounds.height > 0 && bounds.right > 0 && bounds.left < innerWidth && bounds.bottom > 0 && bounds.top < innerHeight && style.visibility !== 'hidden' && style.display !== 'none';
  }

  function openChat(event) {
    event.preventDefault();
    if (dialog.open) { focusComposer(); return; }
    returnFocus = event.currentTarget;
    document.body.classList.remove('menu-open');
    document.querySelector('[data-menu]')?.setAttribute('aria-expanded', 'false');
    previousOverflow = document.documentElement.style.overflow;
    try { dialog.showModal(); } catch { return; }
    document.querySelectorAll('[data-community-open]').forEach(button => button.setAttribute('aria-expanded', 'true'));
    document.documentElement.style.overflow = 'hidden';
    if (!canPoll()) setFeedback('You are offline. Reconnect to load the conversation.', 'offline');
    else loadState();
    if (viewer) focusComposer();
  }

  document.querySelectorAll('[data-community-open]').forEach(button => button.addEventListener('click', openChat));
  dialog.querySelectorAll('[data-community-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('close', () => {
    clearTimeout(stateTimer);
    stateAfterPresence = false;
    stateController?.abort('closed');
    document.querySelectorAll('[data-community-open]').forEach(button => button.setAttribute('aria-expanded', 'false'));
    document.documentElement.style.overflow = previousOverflow;
    const target = visibleControl(returnFocus) ? returnFocus : document.querySelector('[data-menu]');
    if (visibleControl(target)) target.focus({ preventScroll: true });
  });

  identityForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (identityBusy || Date.now() < writeRetryAt || identityForm.hidden) return;
    const name = nameInput.value.trim();
    if (!name) { setFeedback('Please enter a guest name.', 'identity'); nameInput.focus(); return; }
    if (Array.from(name).length > 40) { setFeedback('Please use a name of 40 characters or fewer.', 'identity'); return; }
    identityBusy = true;
    const revision = ++viewerRevision;
    const slowRequest = setTimeout(() => {
      setFeedback('Still waiting for your name to be saved. Please keep this page open.', 'identity');
    }, 15000);
    if (identityButton) identityButton.textContent = 'Saving…';
    refreshButtons();
    try {
      const data = await request(endpoints.identityUrl, { method: 'POST', body: { name } });
      applyData(data, revision);
      try { localStorage.setItem(nicknameKey, name); } catch {}
      clearFeedback('identity');
      if (dialog.open && revision === viewerRevision) focusComposer();
    } catch (error) { report(error, 'identity'); }
    finally {
      clearTimeout(slowRequest);
      identityBusy = false;
      if (identityButton) identityButton.textContent = originalIdentityLabel;
      refreshButtons();
    }
  });

  function resizeComposer() {
    bodyInput.style.height = 'auto';
    bodyInput.style.height = `${Math.min(Math.max(bodyInput.scrollHeight, 32), 120)}px`;
  }

  messageForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (sendBusy || Date.now() < writeRetryAt || messageForm.hidden || viewer?.can_send === false) return;
    const body = bodyInput.value.trim();
    if (!body) { setFeedback('Write a message first.', 'message'); bodyInput.focus(); return; }
    if (Array.from(body).length > 500) { setFeedback('Please keep your message to 500 characters.', 'message'); return; }
    sendBusy = true;
    if (sendButton) sendButton.textContent = 'Sending…';
    refreshButtons();
    const revision = viewerRevision;
    const originalValue = bodyInput.value;
    const slowRequest = setTimeout(() => {
      setFeedback('Still waiting for confirmation. Your message is kept here; please keep this page open.', 'message');
    }, 15000);
    try {
      const data = await request(endpoints.sendUrl, { method: 'POST', body: { body } });
      applyData(data, revision);
      if (data.message) mergeMessages([data.message], true);
      if (bodyInput.value === originalValue) { bodyInput.value = ''; resizeComposer(); }
      clearFeedback('message');
      if (dialog.open && !messageForm.hidden) bodyInput.focus({ preventScroll: true });
      // Fetch from the last read cursor: a sent message must not skip unseen replies.
      if (!stateBusy) loadState();
    } catch (error) { report(error, 'message'); }
    finally {
      clearTimeout(slowRequest);
      sendBusy = false;
      if (sendButton) sendButton.textContent = originalSendLabel;
      refreshButtons();
    }
  });

  bodyInput.addEventListener('input', resizeComposer);
  bodyInput.addEventListener('keydown', event => {
    if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) return;
    event.preventDefault();
    messageForm.requestSubmit();
  });

  retry?.addEventListener('click', () => {
    if (Date.now() < retryAt) return;
    if (feedbackScope === 'identity') { nameInput.focus(); return; }
    if (feedbackScope === 'message') { bodyInput.focus(); return; }
    if (navigator.onLine === false) { setFeedback('You are offline. Reconnect and try again.', 'offline'); return; }
    loadState();
    if (!presenceBusy) heartbeat();
  });

  function pausePolling() {
    clearTimeout(stateTimer);
    clearTimeout(presenceTimer);
    stateController?.abort('paused');
  }

  function resumePolling() {
    if (!canPoll()) return;
    if (!presenceBusy) heartbeat();
    if (dialog.open && !stateBusy) loadState();
  }

  document.addEventListener('visibilitychange', () => { if (document.hidden) pausePolling(); else resumePolling(); });
  window.addEventListener('offline', () => {
    pausePolling();
    if (dialog.open) setFeedback('You are offline. Your draft is still here. Reconnect to continue.', 'offline');
  });
  window.addEventListener('online', resumePolling);
  window.addEventListener('pagehide', () => { stopped = true; pausePolling(); clearTimeout(retryTimer); });
  window.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; resumePolling(); refreshButtons(); } });
  heartbeat();
})();
