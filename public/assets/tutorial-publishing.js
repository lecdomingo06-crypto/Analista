(() => {
  document.querySelectorAll('[data-tutorial-publishing-form]').forEach(form => {
    const file = form.querySelector('input[name="video"]');
    const link = form.querySelector('input[name="youtube_url"]');
    const submit = form.querySelector('[data-tutorial-submit]');
    const submitText = submit?.textContent;
    window.addEventListener('pageshow', () => { if (submit) { submit.disabled = false; submit.textContent = submitText; } });
    function updateSource() {
      const source = form.querySelector('input[name="source_type"]:checked')?.value || 'youtube';
      form.querySelectorAll('[data-tutorial-source]').forEach(group => {
        group.hidden = group.dataset.tutorialSource !== source;
        group.querySelectorAll('input').forEach(input => { input.disabled = group.hidden; });
      });
      if (link) link.required = source === 'youtube';
      if (file) file.required = source === 'upload' && file.dataset.existingVideo !== 'true';
    }
    form.querySelectorAll('input[name="source_type"]').forEach(input => input.addEventListener('change', updateSource));
    updateSource();
    file?.addEventListener('change', () => {
      file.setCustomValidity(file.files[0]?.size > 30 * 1024 * 1024 ? 'Please choose a video no larger than 30 MB.' : '');
    });
    form.addEventListener('submit', () => {
      if (!form.checkValidity()) return;
      const button = form.querySelector('[data-tutorial-submit]');
      if (button) { button.disabled = true; button.textContent = file?.files.length && !file.disabled ? 'Uploading…' : 'Saving…'; }
      const status = form.querySelector('[data-tutorial-upload-status]');
      if (status) status.hidden = !(file?.files.length && !file.disabled);
    });
  });
})();
