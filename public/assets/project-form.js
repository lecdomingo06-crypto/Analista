(() => {
  const form = document.querySelector('[data-project-form]');
  if (!form || !window.fetch || !window.FormData) return;

  const summary = document.querySelector('[data-project-errors]');
  const status = form.querySelector('[data-project-submit-status]');
  const buttons = Array.from(form.querySelectorAll('button[type="submit"]'));
  const reviewLimit = form.querySelector('[data-review-limit]');
  const syncReviewMode = () => {
    if (reviewLimit) reviewLimit.hidden = form.elements.namedItem('review_mode')?.value !== 'volunteer';
  };
  form.querySelectorAll('[name="review_mode"]').forEach(control => control.addEventListener('change', syncReviewMode));
  syncReviewMode();
  let submitting = false;

  const fieldControls = name => {
    const field = form.elements.namedItem(name);
    return field instanceof HTMLElement ? [field] : Array.from(field || []).filter(control => control instanceof HTMLElement);
  };

  const removeErrorReference = (control, errorId) => {
    control.removeAttribute('aria-invalid');
    const describedBy = (control.getAttribute('aria-describedby') || '').split(/\s+/)
      .filter(id => id && id !== errorId);
    if (describedBy.length) control.setAttribute('aria-describedby', describedBy.join(' '));
    else control.removeAttribute('aria-describedby');
  };

  const clearErrors = () => {
    summary?.replaceChildren();
    if (summary) summary.hidden = true;
    form.querySelectorAll('[data-project-field-error]').forEach(error => {
      const controls = fieldControls(error.dataset.projectFieldError);
      controls.forEach(control => removeErrorReference(control, error.id));
      const group = controls[0]?.closest('fieldset');
      if (group) removeErrorReference(group, error.id);
      error.remove();
    });
  };

  const showErrors = (errors, message) => {
    if (!summary) return;
    const heading = document.createElement('strong');
    heading.textContent = form.dataset.projectMode === 'edit'
      ? 'Your changes were not saved. Please fix these fields:'
      : 'Your project has not been created yet. Please fix these fields:';
    const list = document.createElement('ul');
    let count = 0;
    for (const [field, values] of Object.entries(errors || {})) {
      const messages = Array.isArray(values) ? values : [values];
      const controls = fieldControls(field);
      if (field === 'reviewer_limit' && reviewLimit) reviewLimit.hidden = false;
      const error = document.createElement('p');
      error.className = 'hint project-field-error';
      error.id = `project-error-${field.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
      error.dataset.projectFieldError = field;
      const safeMessages = messages.filter(value => typeof value === 'string');
      for (const text of safeMessages) {
        const item = document.createElement('li');
        item.textContent = text;
        list.append(item);
        count += 1;
      }
      if (controls.length && safeMessages.length) {
        controls.forEach(input => {
          input.setAttribute('aria-invalid', 'true');
          const describedBy = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
          input.setAttribute('aria-describedby', [...new Set([...describedBy, error.id])].join(' '));
        });
        error.textContent = safeMessages.join(' ');
        controls[0].closest('.field')?.append(error);
      }
    }
    if (!count) {
      heading.textContent = message || 'Your submission could not be confirmed.';
    }
    const help = document.createElement('p');
    help.className = 'small';
    help.textContent = form.dataset.projectMode === 'edit'
      ? (count ? 'Your entered details are still here. Correct the errors and save again.' : 'Your entered details remain on this page.')
      : (count ? 'Your entered details and selected PDF are still here. Correct the highlighted fields and submit again.' : 'Your entered details and selected PDF remain on this page.');
    summary.replaceChildren(heading, list, help);
    summary.hidden = false;
    summary.focus({ preventScroll: true });
    summary.scrollIntoView({ block: 'start', behavior: 'instant' });
  };

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitting) return;
    const data = new FormData(form);
    if (event.submitter?.name) data.append(event.submitter.name, event.submitter.value);
    submitting = true;
    clearErrors();
    form.setAttribute('aria-busy', 'true');
    const previousDisabled = buttons.map(button => button.disabled);
    buttons.forEach(button => { button.disabled = true; });
    if (status) status.textContent = 'Submitting your project…';
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 90000);
    let navigating = false;
    try {
      const response = await fetch(form.action, {
        method: 'POST', body: data, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller.signal,
      });
      const payload = response.headers.get('content-type')?.includes('application/json')
        ? await response.json() : null;
      if (response.ok && typeof payload?.redirect === 'string') {
        const destination = new URL(payload.redirect, window.location.href);
        if (destination.origin !== window.location.origin) throw new Error('Unexpected redirect.');
        navigating = true;
        if (status) status.textContent = 'Project saved. Opening your project…';
        window.location.assign(destination.href);
      } else if (response.status === 422) {
        showErrors(payload?.errors, payload?.message);
      } else if (response.status === 419 || response.status === 401) {
        showErrors({}, 'Your session has expired. Copy your entered details, sign in again, then reload this form and select the PDF again.');
      } else if (response.status === 413) {
        showErrors({ document: ['The PDF is too large. Choose a PDF smaller than 20 MB.'] });
      } else if (response.status === 403) {
        showErrors({}, payload?.message || 'Your account cannot submit this project.');
      } else {
        showErrors({}, 'We could not confirm the submission. Check My projects before submitting again.');
      }
    } catch {
      showErrors({}, 'We could not confirm the submission. Check your connection and My projects before submitting again.');
    } finally {
      clearTimeout(timeout);
      if (!navigating) {
        submitting = false;
        form.removeAttribute('aria-busy');
        buttons.forEach((button, index) => { button.disabled = previousDisabled[index]; });
        if (status) status.textContent = '';
      }
    }
  });
})();
