(() => {
  const dialog = document.querySelector('[data-notifications-dialog]');
  const openers = [...document.querySelectorAll('[data-notifications-open]')];
  if (!(dialog instanceof HTMLDialogElement) || !dialog.showModal || !openers.length) return;

  const userId = Number(document.body.dataset.authUserId);
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  const history = dialog.querySelector('[data-notifications-history]');
  const items = dialog.querySelector('[data-notifications-items]');
  const empty = dialog.querySelector('[data-notifications-empty]');
  const conversationSection = dialog.querySelector('[data-notifications-conversations-section]');
  const conversations = dialog.querySelector('[data-notifications-conversations]');
  const unreadLabel = dialog.querySelector('[data-notifications-unread]');
  const readAll = dialog.querySelector('[data-notifications-read-all]');
  const refresh = dialog.querySelector('[data-notifications-refresh]');
  const more = dialog.querySelector('[data-notifications-more]');
  const status = dialog.querySelector('[data-notifications-status]');
  const retry = dialog.querySelector('[data-notifications-retry]');
  if (!userId || !history || !items || !empty || !conversations || !conversationSection || !unreadLabel || !readAll || !refresh || !more || !status || !retry) return;

  function localUrl(value) {
    if (typeof value !== 'string' || !value || /[\\\u0000-\u0020]/.test(value)) return null;
    try {
      const url = new URL(value, location.href);
      return url.origin === location.origin && /^https?:$/.test(url.protocol) && !url.username && !url.password ? url.href : null;
    } catch { return null; }
  }
  const stateUrl = localUrl(dialog.dataset.stateUrl);
  const readAllUrl = localUrl(dialog.dataset.readAllUrl);
  if (!stateUrl || !readAllUrl) return;

  const rows = new Map();
  let unreadCount = 0;
  let nextPage = null;
  let loading = false;
  let writing = false;
  let opening = false;
  let expired = false;
  let returnFocus = null;
  let previousOverflow = '';
  let stateController = null;
  let pendingReload = false;
  let failedPage = null;

  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const visible = node => {
    if (!node?.isConnected || node.disabled) return false;
    const rect = node.getBoundingClientRect();
    const style = getComputedStyle(node);
    return rect.width > 0 && rect.height > 0 && rect.right > 0 && rect.left < innerWidth && rect.bottom > 0 && rect.top < innerHeight && style.visibility !== 'hidden' && style.display !== 'none';
  };

  function setCount(value) {
    unreadCount = Math.max(0, Number(value) || 0);
    unreadLabel.textContent = `${unreadCount} unread`;
    document.querySelectorAll('[data-notifications-badge]').forEach(badge => {
      badge.textContent = String(unreadCount);
      badge.hidden = !unreadCount;
    });
    readAll.hidden = !unreadCount || expired;
  }

  function controls() {
    history.setAttribute('aria-busy', String(loading));
    refresh.disabled = loading || writing || expired;
    more.disabled = loading || writing || expired;
    more.hidden = !nextPage || expired;
    readAll.disabled = loading || writing || expired;
    items.querySelectorAll('[data-notification-read]').forEach(button => { button.disabled = loading || writing || expired; });
  }

  function sessionChanged() {
    expired = true;
    stateController?.abort();
    rows.clear();
    items.replaceChildren();
    conversations.replaceChildren();
    conversationSection.hidden = true;
    empty.hidden = true;
    nextPage = null;
    setCount(0);
    status.textContent = 'Your session changed or expired. Reload this page to continue.';
    retry.textContent = 'Reload page';
    retry.hidden = false;
    controls();
  }

  async function request(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'same-origin', cache: 'no-store',
      ...options,
      headers: { Accept: 'application/json', ...(options.method === 'POST' ? { 'X-CSRF-TOKEN': csrf || '' } : {}), ...options.headers },
    });
    if (response.redirected || [401, 419].includes(response.status)) throw { sessionChanged: true };
    const data = await response.json().catch(() => null);
    if (!response.ok || !data || typeof data !== 'object') throw new Error('Request failed.');
    if (Number(data.user_id) !== userId) throw { sessionChanged: true };
    return data;
  }

  function avatar(sender) {
    const node = element('span', 'notification-avatar');
    node.setAttribute('aria-hidden', 'true');
    if (sender?.kind === 'system') {
      node.classList.add('notification-avatar-system');
      const icon = dialog.querySelector('[data-notifications-system-icon]')?.content.firstElementChild;
      node.append(icon ? icon.cloneNode(true) : element('span', '', 'A'));
      return node;
    }
    const initials = typeof sender?.initials === 'string' && sender.initials ? sender.initials : '?';
    node.textContent = initials;
    const photo = localUrl(sender?.avatar_url);
    if (photo) {
      const img = document.createElement('img');
      img.alt = '';
      img.width = 48;
      img.height = 48;
      img.loading = 'lazy';
      img.decoding = 'async';
      img.addEventListener('error', () => { node.textContent = initials; }, { once: true });
      img.src = photo;
      node.replaceChildren(img);
    }
    return node;
  }

  function renderActivity(activity) {
    const article = element('article', `notification-item${activity.read_at ? '' : ' notification-is-unread'}`);
    article.dataset.notificationId = String(activity.id);
    article.tabIndex = -1;
    const sender = activity.sender || { name: 'Analista', kind: 'system' };
    const content = element('div', 'notification-item-content');
    content.append(element('strong', 'notification-sender', sender.name));
    const heading = element('div', 'notification-item-heading');
    heading.append(element('h4', '', activity.title));
    if (!activity.read_at) {
      const dot = element('span', 'notification-unread-dot');
      dot.append(element('span', 'sr-only', 'Unread'));
      heading.append(dot);
    }
    const time = element('time', 'notification-meta', activity.created_label);
    if (activity.created_at) time.dateTime = activity.created_at;
    const actions = element('div', 'notification-actions');
    const target = localUrl(activity.url);
    if (target) {
      const link = element('a', 'notification-open', 'Open ↗');
      link.href = target;
      actions.append(link);
    }
    if (!activity.read_at && localUrl(activity.read_url)) {
      const button = element('button', 'notification-read', 'Mark read');
      button.type = 'button';
      button.dataset.notificationRead = String(activity.id);
      button.setAttribute('aria-label', `Mark ${activity.title} as read`);
      actions.append(button);
    }
    content.append(heading, element('p', 'notification-body', activity.body), time, actions);
    article.append(avatar(sender), content);
    return article;
  }

  function renderActivities() {
    const activeRow = document.activeElement?.closest('[data-notification-id]');
    const activeId = activeRow?.dataset.notificationId;
    const activeRead = document.activeElement?.matches('[data-notification-read]');
    items.replaceChildren(...[...rows.values()].map(renderActivity));
    empty.hidden = rows.size > 0;
    if (activeId) {
      const row = items.querySelector(`[data-notification-id="${activeId}"]`);
      (activeRead ? row?.querySelector('[data-notification-read]') || row?.querySelector('a') || row : row)?.focus({ preventScroll: true });
    }
    controls();
  }

  function renderConversations(list) {
    const nodes = [];
    for (const conversation of list) {
      const target = localUrl(conversation.url);
      if (!target) continue;
      const link = element('a', 'notification-conversation');
      link.href = target;
      const content = element('div', 'notification-conversation-content');
      content.append(element('strong', 'notification-sender', conversation.participant_name));
      content.append(element('p', 'notification-conversation-title', conversation.title));
      const meta = element('div', 'notification-conversation-meta');
      meta.append(element('span', 'notification-conversation-status', conversation.status_label));
      const time = element('time', '', conversation.updated_label);
      if (conversation.updated_at) time.dateTime = conversation.updated_at;
      meta.append(time);
      content.append(meta);
      link.append(avatar({ name: conversation.participant_name, avatar_url: conversation.participant_avatar_url, initials: conversation.participant_initials, kind: 'person' }), content);
      nodes.push(link);
    }
    conversations.replaceChildren(...nodes);
    conversationSection.hidden = !nodes.length;
  }

  async function load(page = null) {
    if (!dialog.open || expired) return;
    if (writing) { pendingReload = true; return; }
    if (loading) { pendingReload = true; return; }
    loading = true;
    failedPage = page;
    retry.hidden = true;
    status.textContent = 'Loading notifications…';
    controls();
    const controller = new AbortController();
    stateController = controller;
    const timeout = setTimeout(() => controller.abort('timeout'), 15000);
    try {
      const data = await request(page || stateUrl, { signal: controller.signal });
      if (!Array.isArray(data.activities) || !Array.isArray(data.conversations)) throw new Error('Invalid response.');
      if (!dialog.open || controller.signal.aborted) return;
      if (!page) rows.clear();
      for (const activity of data.activities) {
        const id = Number(activity.id);
        if (Number.isSafeInteger(id) && id > 0 && typeof activity.title === 'string' && typeof activity.body === 'string') rows.set(id, activity);
      }
      nextPage = localUrl(data.next_page_url);
      setCount(data.unread_count);
      renderActivities();
      renderConversations(data.conversations);
      if (!page) history.scrollTop = 0;
      status.textContent = '';
      failedPage = null;
    } catch (error) {
      if (error.sessionChanged) sessionChanged();
      else if (dialog.open && controller.signal.reason !== 'closed' && controller.signal.reason !== 'writing') {
        status.textContent = 'Notifications could not load. Please try again.';
        retry.textContent = 'Try again';
        retry.hidden = false;
      }
    } finally {
      clearTimeout(timeout);
      if (stateController === controller) stateController = null;
      loading = false;
      controls();
      if (pendingReload && dialog.open && !writing && !expired) { pendingReload = false; load(); }
    }
  }

  async function markRead(id = null) {
    if (writing || loading || expired || !csrf) return;
    const activity = id === null ? null : rows.get(id);
    const target = id === null ? readAllUrl : localUrl(activity?.read_url);
    if (!target) return;
    writing = true;
    retry.hidden = true;
    status.textContent = '';
    controls();
    try {
      const data = await request(target, { method: 'POST', signal: AbortSignal.timeout(15000) });
      if (expired) return;
      for (const row of rows.values()) {
        if (id === null || Number(row.id) === id) row.read_at ||= data.read_at || new Date().toISOString();
      }
      setCount(data.unread_count);
      renderActivities();
      status.textContent = id === null ? 'All notifications marked as read.' : 'Notification marked as read.';
    } catch (error) {
      if (error.sessionChanged) sessionChanged();
      else status.textContent = 'Could not mark notifications as read. Please try again.';
    } finally {
      writing = false;
      controls();
      if (pendingReload && dialog.open && !expired) { pendingReload = false; load(); }
    }
  }

  function closeAndWait(other) {
    if (!other?.open) return Promise.resolve();
    return new Promise(resolve => {
      other.addEventListener('close', resolve, { once: true });
      other.close();
    });
  }

  openers.forEach(opener => opener.addEventListener('click', async event => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    if (dialog.open || opening) return;
    opening = true;
    try {
      await closeAndWait(document.querySelector('[data-community-dialog]'));
      returnFocus = opener;
      document.body.classList.remove('menu-open');
      document.querySelector('[data-menu]')?.setAttribute('aria-expanded', 'false');
      previousOverflow = document.documentElement.style.overflow;
      rows.clear();
      items.replaceChildren();
      conversations.replaceChildren();
      conversationSection.hidden = true;
      empty.hidden = true;
      unreadLabel.textContent = '';
      readAll.hidden = true;
      dialog.showModal();
      openers.forEach(node => node.setAttribute('aria-expanded', 'true'));
      document.documentElement.style.overflow = 'hidden';
      load();
    } catch { location.assign(opener.href); }
    finally { opening = false; }
  }));

  dialog.querySelector('[data-notifications-close]')?.addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('close', () => {
    stateController?.abort('closed');
    pendingReload = false;
    openers.forEach(node => node.setAttribute('aria-expanded', 'false'));
    document.documentElement.style.overflow = previousOverflow;
    const target = visible(returnFocus) ? returnFocus : document.querySelector('[data-menu]');
    if (visible(target)) target.focus({ preventScroll: true });
  });
  // Let the existing chat opener run after this dialog has released its scroll lock.
  document.querySelectorAll('[data-community-open]').forEach(opener => opener.addEventListener('click', async event => {
    if (!dialog.open) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    await closeAndWait(dialog);
    opener.click();
  }, { capture: true }));
  items.addEventListener('click', event => {
    const button = event.target.closest('[data-notification-read]');
    if (button) markRead(Number(button.dataset.notificationRead));
  });
  readAll.addEventListener('click', () => markRead());
  refresh.addEventListener('click', () => load());
  more.addEventListener('click', () => { if (nextPage) load(nextPage); });
  retry.addEventListener('click', () => { if (expired) location.reload(); else load(failedPage); });
  window.addEventListener('pageshow', event => {
    if (event.persisted) {
      rows.clear();
      items.replaceChildren();
      conversations.replaceChildren();
      conversationSection.hidden = true;
      empty.hidden = true;
      if (dialog.open) load();
    }
  });
})();
