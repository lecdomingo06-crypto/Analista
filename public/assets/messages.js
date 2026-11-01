(() => {
  const room = document.querySelector('[data-message-room]');
  if (!room) return;
  const thread = room.querySelector('[data-message-thread]');
  const form = room.querySelector('[data-message-form]');
  const input = form?.querySelector('[data-message-input]');
  const filesInput = form?.querySelector('[data-message-files]');
  const filesPreview = form?.querySelector('[data-message-attachments-preview]');
  const button = form?.querySelector('button[type="submit"]');
  const status = room.querySelector('[data-chat-status]');
  const list = room.querySelector('.chat-list');
  let lastId = Number(room.dataset.lastId || 0);
  let polling = false;
  let inboxPolling = false;
  let sending = false;
  let stopped = false;
  let peerAvailable = true;
  let selectedFiles = [];
  let fileSequence = 0;
  let composerError = '';
  const maxFiles = 5;
  const maxFileSize = 10 * 1024 * 1024;
  const maxTotalSize = 20 * 1024 * 1024;
  const allowedExtensions = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip']);
  const showStatus = (text, isError = false) => {
    if (!status) return;
    status.textContent = text;
    status.classList.toggle('is-error', isError);
  };
  const fileSize = bytes => Number(bytes) >= 1048576 ? `${(Number(bytes) / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.ceil(Number(bytes) / 1024))} KB`;
  const localUrl = value => {
    if (typeof value !== 'string' || !value) return null;
    try {
      const url = new URL(value, window.location.href);
      return ['http:', 'https:'].includes(url.protocol) && url.origin === window.location.origin ? url.href : null;
    } catch { return null; }
  };
  const attachmentIcon = name => {
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.classList.add('icon');
    for (const [key, value] of Object.entries({ width: '20', height: '20', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.5', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' })) icon.setAttribute(key, value);
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', name === 'download' ? 'M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4' : 'M14 2H4v20h16V8zM14 2v6h6M8 12h8M8 16h5');
    icon.append(path);
    return icon;
  };
  const updateComposer = () => {
    if (!form) return;
    const unavailable = sending || stopped || !peerAvailable;
    button.disabled = unavailable || (!input.value.trim() && selectedFiles.length === 0);
    input.readOnly = unavailable;
    if (filesInput) filesInput.disabled = unavailable;
    form.classList.toggle('is-sending', sending);
    form.setAttribute('aria-busy', sending ? 'true' : 'false');
    filesPreview?.querySelectorAll('button').forEach(control => { control.disabled = unavailable; });
  };
  const clearComposerError = () => {
    composerError = '';
    filesInput?.removeAttribute('aria-invalid');
    showStatus(peerAvailable ? '' : 'This account is currently unavailable. Your unsent message and files are kept here.');
  };
  const syncFilesInput = () => {
    if (!filesInput) return;
    try {
      const transfer = new DataTransfer();
      selectedFiles.forEach(entry => transfer.items.add(entry.file));
      filesInput.files = transfer.files;
    } catch {
      // Sending uses the retained File objects if a browser cannot assign FileList.
      filesInput.value = '';
    }
  };
  const renderSelectedFiles = () => {
    if (!filesPreview) return;
    filesPreview.replaceChildren();
    filesPreview.hidden = selectedFiles.length === 0;
    if (!selectedFiles.length) { updateComposer(); return; }
    const heading = document.createElement('div');
    heading.className = 'chat-attachment-preview-heading';
    const count = document.createElement('span');
    count.textContent = `${selectedFiles.length} ${selectedFiles.length === 1 ? 'file' : 'files'} · ${fileSize(selectedFiles.reduce((total, entry) => total + entry.file.size, 0))}`;
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'chat-attachment-clear';
    clear.dataset.clearAttachments = '';
    clear.textContent = 'Remove all';
    clear.addEventListener('click', () => { if (!sending && peerAvailable) clearSelectedFiles(); });
    heading.append(count, clear);
    const rows = document.createElement('div');
    rows.className = 'chat-attachment-selected-list';
    selectedFiles.forEach(entry => {
      const row = document.createElement('div');
      row.className = 'chat-selected-file';
      row.dataset.messageSelectedFile = String(entry.id);
      if (entry.previewUrl) {
        const photo = document.createElement('img');
        photo.src = entry.previewUrl;
        photo.alt = '';
        photo.width = 40;
        photo.height = 40;
        photo.addEventListener('error', () => { photo.replaceWith(attachmentIcon('file')); });
        row.append(photo);
      } else row.append(attachmentIcon('file'));
      const copy = document.createElement('span');
      copy.className = 'chat-attachment-copy';
      const name = document.createElement('strong');
      name.textContent = entry.file.name;
      const size = document.createElement('span');
      size.textContent = fileSize(entry.file.size);
      copy.append(name, size);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'chat-attachment-remove';
      remove.dataset.removeAttachment = String(entry.id);
      remove.setAttribute('aria-label', `Remove ${entry.file.name}`);
      remove.textContent = '×';
      remove.addEventListener('click', () => {
        if (sending || !peerAvailable) return;
        const index = selectedFiles.findIndex(item => item.id === entry.id);
        if (entry.previewUrl) URL.revokeObjectURL(entry.previewUrl);
        selectedFiles = selectedFiles.filter(item => item.id !== entry.id);
        syncFilesInput();
        renderSelectedFiles();
        clearComposerError();
        const next = selectedFiles[Math.min(index, selectedFiles.length - 1)];
        if (next) filesPreview.querySelector(`[data-remove-attachment="${next.id}"]`)?.focus();
        else filesInput?.focus();
      });
      row.append(copy, remove);
      rows.append(row);
    });
    filesPreview.append(heading, rows);
    updateComposer();
  };
  const clearSelectedFiles = () => {
    selectedFiles.forEach(entry => { if (entry.previewUrl) URL.revokeObjectURL(entry.previewUrl); });
    selectedFiles = [];
    syncFilesInput();
    renderSelectedFiles();
    clearComposerError();
  };
  filesInput?.addEventListener('change', () => {
    const additions = Array.from(filesInput.files || []).filter(file => !selectedFiles.some(entry => entry.file.name === file.name && entry.file.size === file.size && entry.file.lastModified === file.lastModified));
    const candidates = [...selectedFiles.map(entry => entry.file), ...additions];
    let error = candidates.length > maxFiles ? 'You can attach up to 5 files to one message.' : '';
    for (const file of additions) {
      if (!allowedExtensions.has(file.name.split('.').pop().toLowerCase())) error ||= `${file.name}: choose a photo, PDF, Office document, text, CSV, or ZIP file.`;
      if (file.size === 0) error ||= `${file.name} is empty. Choose a file with content.`;
      if (file.size > maxFileSize) error ||= `${file.name} is larger than 10 MB.`;
    }
    if (candidates.reduce((total, file) => total + file.size, 0) > maxTotalSize) error ||= 'The attachments exceed 20 MB in total. Choose fewer or smaller files.';
    if (error) {
      syncFilesInput();
      composerError = error;
      filesInput.setAttribute('aria-invalid', 'true');
      showStatus(error, true);
      return;
    }
    selectedFiles.push(...additions.map(file => ({ id: ++fileSequence, file, previewUrl: /^image\/(jpeg|png|gif|webp)$/.test(file.type) ? URL.createObjectURL(file) : null })));
    syncFilesInput();
    renderSelectedFiles();
    clearComposerError();
  });
  if (filesInput) form.classList.add('has-attachment-picker');
  const setUnread = count => document.querySelectorAll('[data-message-unread]').forEach(badge => {
    badge.textContent = String(count);
    badge.hidden = Number(count) === 0;
  });
  const nearBottom = () => !thread || thread.scrollHeight - thread.scrollTop - thread.clientHeight < 100;
  const scrollToLatest = () => { if (thread) thread.scrollTop = thread.scrollHeight; };
  const restoreMessageInitials = photo => {
    const avatar = photo.closest('.chat-message-avatar');
    if (avatar && thread?.contains(avatar)) avatar.textContent = avatar.dataset.avatarInitials || '?';
  };
  thread?.addEventListener('error', event => {
    if (!(event.target instanceof HTMLImageElement)) return;
    if (event.target.closest('.chat-message-avatar')) restoreMessageInitials(event.target);
    else {
      const attachment = event.target.closest('.chat-attachment-image');
      if (attachment) attachment.replaceChildren(document.createTextNode('Open image'));
    }
  }, true);
  thread?.querySelectorAll('.chat-message-avatar img').forEach(photo => {
    if (photo.complete && photo.naturalWidth === 0) restoreMessageInitials(photo);
  });
  const messageAvatarNode = data => {
    const avatar = document.createElement('span');
    avatar.className = 'avatar chat-message-avatar';
    avatar.setAttribute('role', 'img');
    avatar.setAttribute('aria-label', data.sender_name || 'Account unavailable');
    avatar.dataset.avatarInitials = data.sender_initials || '?';
    avatar.textContent = avatar.dataset.avatarInitials;
    if (data.sender_profile_photo_url) {
      const photo = document.createElement('img');
      photo.alt = '';
      photo.width = 32;
      photo.height = 32;
      photo.loading = 'lazy';
      photo.decoding = 'async';
      photo.src = data.sender_profile_photo_url;
      avatar.replaceChildren(photo);
    }
    return avatar;
  };
  const updateAvatar = (avatar, chat) => {
    if (!chat.profile_photo_url) {
      avatar.textContent = chat.initials || '?';
      return;
    }
    const current = avatar.querySelector('img');
    if (current?.getAttribute('src') === chat.profile_photo_url) return;
    const photo = document.createElement('img');
    photo.src = chat.profile_photo_url;
    photo.alt = '';
    photo.width = 48;
    photo.height = 48;
    photo.loading = 'lazy';
    photo.decoding = 'async';
    avatar.replaceChildren(photo);
  };
  const reviewCardNode = card => {
    const box = document.createElement('div');
    box.className = `chat-review-card${card.available ? '' : ' is-unavailable'}`;
    box.dataset.reviewCard = '';
    const label = document.createElement('p');
    label.className = 'chat-review-label';
    label.textContent = card.label;
    box.append(label);
    if (card.available) {
      const title = document.createElement('h3');
      title.textContent = card.project_title;
      box.append(title);
    }
    const description = document.createElement('p');
    description.className = 'chat-review-description';
    description.textContent = card.description;
    box.append(description);
    if (!card.available) return box;
    const details = document.createElement('dl');
    details.className = 'chat-review-details';
    const values = [['Evaluator', card.evaluator_name], ['PDF version', String(card.document_version)], ['Status', card.status]];
    if (card.deadline) values.push(['Agreed completion', card.deadline]);
    values.forEach(([name, value]) => {
      const row = document.createElement('div');
      const term = document.createElement('dt');
      term.textContent = name;
      const definition = document.createElement('dd');
      definition.textContent = value;
      row.append(term, definition);
      details.append(row);
    });
    const actions = document.createElement('div');
    actions.className = 'chat-review-actions';
    const links = [[card.review_url, card.event === 'requested' ? 'View request' : 'View review', '↗']];
    if (card.rating_url) links.push([card.rating_url, 'Rate evaluator', '☆']);
    if (card.project_url) links.push([card.project_url, 'View project', '↗']);
    links.forEach(([url, text, mark]) => {
      if (!url) return;
      const target = new URL(url, window.location.href);
      if (target.origin !== window.location.origin) return;
      const link = document.createElement('a');
      link.href = target.href;
      link.append(document.createTextNode(`${text} `));
      const arrow = document.createElement('span');
      arrow.setAttribute('aria-hidden', 'true');
      arrow.textContent = mark;
      link.append(arrow);
      actions.append(link);
    });
    box.append(details, actions);
    return box;
  };
  const attachmentNode = data => {
    const downloadUrl = localUrl(data.download_url);
    const imageUrl = data.is_image === true ? localUrl(data.url) : null;
    if (!downloadUrl) return null;
    const box = document.createElement('div');
    box.className = `chat-attachment${imageUrl ? ' is-image' : ''}`;
    const name = String(data.name || 'Attachment');
    const size = fileSize(data.size || 0);
    if (imageUrl) {
      const open = document.createElement('a');
      open.className = 'chat-attachment-image';
      open.href = imageUrl;
      open.target = '_blank';
      open.rel = 'noopener';
      open.setAttribute('aria-label', `Open image: ${name}`);
      const photo = document.createElement('img');
      photo.src = imageUrl;
      photo.alt = name;
      photo.loading = 'lazy';
      photo.decoding = 'async';
      photo.width = 280;
      photo.height = 180;
      open.append(photo);
      box.append(open);
    }
    const link = document.createElement('a');
    link.className = 'chat-attachment-download';
    link.href = downloadUrl;
    link.download = '';
    link.setAttribute('aria-label', `Download ${name} (${size})`);
    const copy = document.createElement('span');
    copy.className = 'chat-attachment-copy';
    const title = document.createElement('strong');
    title.textContent = name;
    const label = document.createElement('span');
    label.textContent = size;
    copy.append(title, label);
    link.append(attachmentIcon('file'), copy, attachmentIcon('download'));
    box.append(link);
    return box;
  };
  const messageNode = data => {
    const article = document.createElement('article');
    article.className = `chat-message${data.is_own ? ' is-own' : ''}`;
    article.dataset.messageId = String(data.id);
    const content = document.createElement('div');
    content.className = 'chat-message-content';
    if (data.review_card) content.append(reviewCardNode(data.review_card));
    else if (data.body) {
      const bubble = document.createElement('p');
      bubble.className = 'chat-bubble';
      bubble.textContent = data.body;
      content.append(bubble);
    }
    if (!data.review_card && Array.isArray(data.attachments) && data.attachments.length) {
      const attachments = document.createElement('div');
      attachments.className = 'chat-attachments';
      attachments.setAttribute('aria-label', 'Message attachments');
      data.attachments.forEach(item => { const node = attachmentNode(item); if (node) attachments.append(node); });
      if (attachments.childElementCount) content.append(attachments);
    }
    const meta = document.createElement('div');
    meta.className = 'chat-message-meta';
    const time = document.createElement('time');
    time.dateTime = data.created_at;
    time.textContent = data.label;
    meta.append(time);
    if (data.is_own) {
      const receipt = document.createElement('span');
      receipt.dataset.messageReceipt = '';
      receipt.textContent = 'Sent';
      meta.append(receipt);
    }
    content.append(meta);
    article.append(messageAvatarNode(data), content);
    return article;
  };
  const insertMessage = data => {
    if (!thread || thread.querySelector(`[data-message-id="${Number(data.id)}"]`)) return;
    const next = Array.from(thread.querySelectorAll('[data-message-id]')).find(row => Number(row.dataset.messageId) > Number(data.id));
    thread.insertBefore(messageNode(data), next || null);
  };
  const resizeInput = () => {
    if (!input) return;
    input.style.height = 'auto';
    const contentHeight = input.scrollHeight + input.offsetHeight - input.clientHeight;
    input.style.height = `${Math.min(contentHeight, 160)}px`;
    input.style.overflowY = contentHeight > 160 ? 'auto' : 'hidden';
  };
  const checkResponse = async response => {
    if (response.status === 401 || response.status === 419 || response.redirected) {
      stopped = true;
      throw new Error('Your session has expired. Your unsent message and files are still here. Refresh this page to sign in again; choose your files again after refreshing.');
    }
    const data = await response.json();
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || (response.status === 403 ? 'This conversation is currently unavailable for messaging.' : 'Could not connect. Please try again.'));
    return data;
  };
  const refreshMessages = async () => {
    if (!room.dataset.updatesUrl || stopped || polling || document.hidden) return;
    polling = true;
    try {
      const url = new URL(room.dataset.updatesUrl, window.location.href);
      url.searchParams.set('after', String(lastId));
      const data = await checkResponse(await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' }));
      const keepPosition = !nearBottom();
      data.messages.forEach(insertMessage);
      lastId = Math.max(lastId, Number(data.latest_id || 0));
      thread?.querySelectorAll('.is-own[data-message-id]').forEach(row => {
        if (Number(row.dataset.messageId) <= Number(data.peer_read_through_id || 0)) row.querySelector('[data-message-receipt]').textContent = 'Seen';
      });
      setUnread(data.unread_count);
      if (!keepPosition) scrollToLatest();
      peerAvailable = data.can_message !== false;
      updateComposer();
      if (!sending && !composerError) showStatus(peerAvailable ? '' : 'This account is currently unavailable. Your unsent message and files are kept here.');
    } catch (error) {
      if (!sending && !composerError) showStatus(stopped ? error.message : 'Reconnecting… Your unsent message and files are kept here.');
      updateComposer();
    } finally { polling = false; }
  };
  const refreshInbox = async () => {
    if (!room.dataset.inboxUpdatesUrl || stopped || inboxPolling || document.hidden) return;
    inboxPolling = true;
    try {
      const data = await checkResponse(await fetch(room.dataset.inboxUpdatesUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' }));
      setUnread(data.unread_count);
      // Older chat-list pages keep their server pagination and rows.
      if (new URL(window.location.href).searchParams.has('chats_page')) return;
      const currentId = room.dataset.conversationId;
      const visibleIds = new Set(data.conversations.map(chat => String(chat.id)));
      list.querySelectorAll('[data-chat-id]').forEach(row => { if (!visibleIds.has(row.dataset.chatId)) row.remove(); });
      data.conversations.forEach(chat => {
        let row = list.querySelector(`[data-chat-id="${Number(chat.id)}"]`);
        if (!row) {
          row = document.createElement('a');
          row.className = 'chat-person';
          row.dataset.chatId = String(chat.id);
          const avatar = document.createElement('span'); avatar.className = 'avatar';
          const copy = document.createElement('span'); copy.className = 'chat-person-copy';
          const name = document.createElement('strong');
          const preview = document.createElement('span'); preview.dataset.chatPreview = '';
          copy.append(name, preview);
          const meta = document.createElement('span'); meta.className = 'chat-person-meta';
          row.append(avatar, copy, meta);
        }
        row.href = chat.url;
        row.classList.toggle('active', String(chat.id) === currentId);
        if (String(chat.id) === currentId) row.setAttribute('aria-current', 'page'); else row.removeAttribute('aria-current');
        row.classList.toggle('has-unread', Number(chat.unread_count) > 0);
        updateAvatar(row.querySelector('.avatar'), chat);
        row.querySelector('strong').textContent = chat.name;
        row.querySelector('[data-chat-preview]').textContent = `${chat.is_own ? 'You: ' : ''}${chat.preview}`;
        const meta = row.querySelector('.chat-person-meta');
        meta.replaceChildren();
        const time = document.createElement('time'); time.dateTime = chat.last_message_at || ''; time.textContent = chat.label || ''; meta.append(time);
        if (Number(chat.unread_count) > 0) {
          const unread = document.createElement('span'); unread.className = 'chat-unread'; unread.textContent = String(chat.unread_count); unread.setAttribute('aria-label', `${chat.unread_count} unread messages`); meta.append(unread);
        }
        list.append(row);
      });
      if (data.conversations.length) list.querySelector('.chat-list-empty')?.remove();
    } catch (error) { if (stopped) showStatus(error.message); }
    finally { inboxPolling = false; }
  };
  form?.addEventListener('submit', async event => {
    event.preventDefault();
    if (sending || stopped || !peerAvailable || (!input.value.trim() && !selectedFiles.length)) return;
    sending = true;
    clearComposerError();
    updateComposer();
    showStatus(selectedFiles.length ? 'Sending attachments…' : 'Sending…');
    try {
      const payload = new FormData(form);
      payload.delete('attachments[]');
      selectedFiles.forEach(entry => payload.append('attachments[]', entry.file, entry.file.name));
      const data = await checkResponse(await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body: payload }));
      if (data.redirect) { window.location.assign(data.redirect); return; }
      if (!data.message) throw new Error('The message could not be confirmed. Refresh the conversation before trying again.');
      insertMessage(data.message);
      input.value = '';
      clearSelectedFiles();
      resizeInput();
      scrollToLatest();
      showStatus('');
      refreshMessages();
      refreshInbox();
    } catch (error) { composerError = error.message; showStatus(error.message, true); }
    finally { sending = false; updateComposer(); input.focus(); }
  });
  input?.addEventListener('input', () => { resizeInput(); updateComposer(); if (composerError) clearComposerError(); });
  input?.addEventListener('keydown', event => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); if (input.value.trim() || selectedFiles.length) form.requestSubmit(); }
  });
  scrollToLatest();
  resizeInput();
  updateComposer();
  const messagesTimer = window.setInterval(refreshMessages, 5000);
  const inboxTimer = window.setInterval(refreshInbox, 10000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { refreshMessages(); refreshInbox(); } });
  window.addEventListener('pagehide', event => {
    if (event.persisted) return;
    window.clearInterval(messagesTimer);
    window.clearInterval(inboxTimer);
    selectedFiles.forEach(entry => { if (entry.previewUrl) URL.revokeObjectURL(entry.previewUrl); });
  });
})();
