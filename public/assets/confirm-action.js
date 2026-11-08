(() => {
  const dialog = document.querySelector('[data-confirm-dialog]');
  const heading = dialog?.querySelector('[data-confirm-title]');
  const message = dialog?.querySelector('[data-confirm-message]');
  const subject = dialog?.querySelector('[data-confirm-subject]');
  const accept = dialog?.querySelector('[data-confirm-accept]');
  const cancel = dialog?.querySelector('[data-confirm-cancel]');
  const approved = new WeakMap();
  let submitted = new WeakSet();
  let pending = null;
  let backdropPointer = false;

  function confirmationFor(form, submitter) {
    const source = submitter?.hasAttribute('data-confirm') ? submitter : form;
    let details;
    if (form.hasAttribute('data-confirm-report')) {
      const status = form.querySelector('select[name="status"]')?.value;
      const action = form.querySelector('select[name="action"]')?.value;
      if (status !== 'resolved' || !['hide_content', 'suspend_account'].includes(action)) return null;
      details = action === 'hide_content'
        ? { message: 'This will hide the reported content and save this report decision.', label: 'Hide content' }
        : { message: 'This will suspend the responsible account and save this report decision.', label: 'Suspend account' };
    } else {
      if (!source.hasAttribute('data-confirm')) return null;
      details = { message: source.dataset.confirm || 'Are you sure you want to continue with this action?', label: 'Confirm action' };
    }
    return {
      title: source.dataset.confirmTitle || form.dataset.confirmTitle || 'Are you sure?',
      message: details.message,
      label: source.dataset.confirmLabel || form.dataset.confirmLabel || details.label,
      subject: source.dataset.confirmSubject || form.dataset.confirmSubject || '',
    };
  }

  function isSubmitButton(button, form) {
    return button?.isConnected && button.form === form && !button.matches(':disabled')
      && ((button.tagName === 'BUTTON' && button.type === 'submit')
        || (button.tagName === 'INPUT' && ['submit', 'image'].includes(button.type)));
  }

  // Remember both the values and the original controls. Confirmation never applies
  // to a different destination, replacement form, changed decision, or changed file.
  function snapshotFor(form, submitter) {
    const controls = [...form.elements];
    const files = controls.flatMap(control => control.files ? [...control.files] : []);
    const state = JSON.stringify({
      // Attribute reads also work on reports whose select is named "action".
      action: form.getAttribute('action'), method: form.getAttribute('method'), target: form.getAttribute('target'),
      enctype: form.getAttribute('enctype'), noValidate: form.hasAttribute('novalidate'), base: document.baseURI,
      confirmation: confirmationFor(form, submitter),
      controls: controls.map(control => [
        control.tagName, control.type, control.name, control.value,
        control.checked, control.matches(':disabled'), control.required, control.readOnly,
        control.min, control.max, control.step, control.pattern, control.minLength, control.maxLength,
        control.formAction, control.formMethod, control.formEnctype, control.formTarget, control.formNoValidate,
        control.options ? [...control.options].map(option => [option.value, option.selected, option.disabled]) : null,
      ]),
    });
    return { controls, files, state };
  }

  function isCurrent(request) {
    if (!request.form.isConnected || (request.submitter && !isSubmitButton(request.submitter, request.form))) return false;
    const current = snapshotFor(request.form, request.submitter);
    return current.state === request.snapshot.state
      && current.controls.length === request.snapshot.controls.length
      && current.controls.every((control, index) => control === request.snapshot.controls[index])
      && current.files.length === request.snapshot.files.length
      && current.files.every((file, index) => file === request.snapshot.files[index]);
  }

  function dismiss() {
    const request = pending;
    pending = null;
    backdropPointer = false;
    if (dialog?.open) dialog.close();
    if (request?.opener?.isConnected && !request.opener.matches(':disabled')) {
      request.opener.focus({ preventScroll: true });
    }
    return request;
  }

  function resume(request) {
    if (!request || submitted.has(request.form) || !isCurrent(request)) return;
    approved.set(request.form, request);
    try {
      // Native submission rechecks validity and retains the exact button's name,
      // value, form overrides, CSRF token, and Laravel method field.
      if (request.submitter) request.form.requestSubmit(request.submitter);
      else request.form.requestSubmit();
    } finally {
      approved.delete(request.form);
    }
  }

  function fallbackText(details) {
    return [details.title, details.subject, details.message].filter(Boolean).join('\n\n');
  }

  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
    const submitter = event.submitter || null;
    const permission = approved.get(form);
    if (permission) {
      approved.delete(form);
      if (permission.submitter !== submitter || !isCurrent(permission)) event.preventDefault();
      else queueMicrotask(() => { if (!event.defaultPrevented) submitted.add(form); });
      return;
    }
    const details = confirmationFor(form, submitter);
    if (!details) return;
    if (pending || submitted.has(form)) {
      event.preventDefault();
      return;
    }

    // Browsers without requestSubmit can retain the original native submission
    // after a synchronous fallback, including its validation and submitter.
    if (typeof form.requestSubmit !== 'function') {
      if (!window.confirm(fallbackText(details))) event.preventDefault();
      else queueMicrotask(() => { if (!event.defaultPrevented) submitted.add(form); });
      return;
    }
    event.preventDefault();
    const request = {
      form, submitter, snapshot: snapshotFor(form, submitter),
      opener: submitter || (document.activeElement instanceof HTMLElement ? document.activeElement : null),
    };
    if (!dialog || !heading || !message || !subject || !accept || !cancel || typeof dialog.showModal !== 'function') {
      if (window.confirm(fallbackText(details))) resume(request);
      return;
    }

    heading.textContent = details.title;
    message.textContent = details.message;
    subject.textContent = details.subject;
    subject.hidden = !details.subject;
    accept.textContent = details.label;
    pending = request;
    try {
      dialog.showModal();
      cancel.focus({ preventScroll: true });
    } catch {
      dismiss();
      if (window.confirm(fallbackText(details))) resume(request);
    }
  });

  accept?.addEventListener('click', () => resume(dismiss()));
  cancel?.addEventListener('click', dismiss);
  dialog?.addEventListener('cancel', event => {
    event.preventDefault();
    dismiss();
  });
  dialog?.addEventListener('keydown', event => {
    if (!dialog.open || event.key !== 'Tab') return;
    const buttons = [cancel, accept].filter(button => button && !button.disabled);
    if (!buttons.length) return;
    const index = buttons.indexOf(document.activeElement);
    const next = index < 0 ? (event.shiftKey ? buttons.length - 1 : 0)
      : (index + (event.shiftKey ? -1 : 1) + buttons.length) % buttons.length;
    event.preventDefault();
    buttons[next].focus({ preventScroll: true });
  });
  dialog?.addEventListener('close', () => { if (!dialog.open) dismiss(); });
  const outsideDialog = event => {
    const bounds = dialog.getBoundingClientRect();
    return event.clientX < bounds.left || event.clientX > bounds.right
      || event.clientY < bounds.top || event.clientY > bounds.bottom;
  };
  dialog?.addEventListener('pointerdown', event => { backdropPointer = event.target === dialog && outsideDialog(event); });
  dialog?.addEventListener('click', event => {
    if (backdropPointer && event.target === dialog && outsideDialog(event)) dismiss();
    backdropPointer = false;
  });
  for (const type of ['input', 'change']) {
    document.addEventListener(type, event => { if (pending && event.target.form === pending.form) dismiss(); });
  }
  window.addEventListener('pagehide', dismiss);
  window.addEventListener('pageshow', () => { submitted = new WeakSet(); });
})();
