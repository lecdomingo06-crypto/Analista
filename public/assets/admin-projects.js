(() => {
  const forms = [...document.querySelectorAll('[data-project-moderation]')];
  const region = document.querySelector('[data-toast-region]');
  const template = document.querySelector('[data-validation-toast-template]');
  if (!forms.length || !region || !template) return;

  function showError(field, message) {
    const existing = region.querySelector('.toast-error:not(.is-leaving)');
    if (existing) {
      existing.querySelector('.toast-message').textContent = message;
    } else {
      const toast = template.content.firstElementChild.cloneNode(true);
      toast.dataset.projectValidation = '';
      toast.querySelector('.toast-message').textContent = message;
      region.append(toast);
    }
    field.setAttribute('aria-invalid', 'true');
    field.focus();
  }

  for (const form of forms) {
    const note = form.querySelector('[name="reason"]');
    if (!note) continue;
    form.addEventListener('invalid', event => {
      event.preventDefault();
      const field = event.target;
      showError(field, field.validity.valueMissing ? note.dataset.requiredMessage : field.validationMessage);
    }, true);
    form.addEventListener('submit', event => {
      if (event.submitter?.value === 'approve') return;
      if (!note.value.trim()) {
        event.preventDefault();
        showError(note, note.dataset.requiredMessage);
      }
    });
    note.addEventListener('input', () => note.removeAttribute('aria-invalid'));
  }

  const invalidNote = forms.map(form => form.querySelector('[name="reason"][aria-invalid="true"]')).find(Boolean);
  if (invalidNote) requestAnimationFrame(() => invalidNote.focus());
})();
