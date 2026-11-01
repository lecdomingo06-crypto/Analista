(() => {
  const input = document.querySelector('#profile_photo');
  const preview = document.querySelector('[data-profile-photo-preview]');
  const feedback = document.querySelector('#profile-photo-feedback');
  let previewUrl;

  const showPhoto = url => {
    preview.replaceChildren();
    if (!url) { preview.textContent = preview.dataset.initials; return; }
    const image = document.createElement('img');
    image.src = url;
    image.alt = '';
    preview.append(image);
  };
  const clearPreviewUrl = () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = undefined;
  };

  if (input && preview) {
    input.addEventListener('change', () => {
      clearPreviewUrl();
      const file = input.files[0];
      if (!file) {
        showPhoto(preview.dataset.originalPhoto);
        feedback.textContent = '';
        return;
      }
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
        input.value = '';
        showPhoto(preview.dataset.originalPhoto);
        feedback.textContent = 'Choose a JPG, PNG or WebP image up to 2 MB.';
        return;
      }
      previewUrl = URL.createObjectURL(file);
      showPhoto(previewUrl);
      feedback.textContent = 'New picture selected. Save your profile to apply it.';
    });
    window.addEventListener('pagehide', event => { if (!event.persisted) clearPreviewUrl(); });
  }

  document.querySelectorAll('[data-password-toggle]').forEach(button => {
    const password = document.getElementById(button.dataset.passwordToggle);
    if (!password) return;
    button.hidden = false;
    const showLabel = button.getAttribute('aria-label');
    button.addEventListener('click', () => {
      const visible = password.type === 'password';
      password.type = visible ? 'text' : 'password';
      button.textContent = visible ? 'Hide' : 'Show';
      button.setAttribute('aria-pressed', String(visible));
      button.setAttribute('aria-label', visible ? showLabel.replace('Show', 'Hide') : showLabel);
    });
  });
})();
