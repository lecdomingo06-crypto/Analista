(() => {
  const dialogs = [...document.querySelectorAll('[data-tutorial-editor]')];
  if (!dialogs.length) return;
  const openers = new WeakMap();

  function openEditor(dialog, opener) {
    if (!(dialog instanceof HTMLDialogElement) || dialog.open) return;
    if (opener) openers.set(dialog, opener);
    dialog.showModal();
    const field = dialog.querySelector('input[name="title"]');
    field?.focus({ preventScroll: true });
  }

  document.querySelectorAll('[data-tutorial-edit]').forEach(button => {
    button.addEventListener('click', () => openEditor(document.getElementById(button.dataset.tutorialEdit), button));
  });

  for (const dialog of dialogs) {
    let backdropPointer = false;
    const outside = event => {
      const bounds = dialog.getBoundingClientRect();
      return event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom;
    };
    dialog.querySelectorAll('[data-tutorial-editor-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('pointerdown', event => { backdropPointer = event.target === dialog && outside(event); });
    dialog.addEventListener('click', event => {
      if (backdropPointer && event.target === dialog && outside(event)) dialog.close();
      backdropPointer = false;
    });
    dialog.addEventListener('close', () => {
      const opener = openers.get(dialog);
      if (opener?.isConnected) opener.focus({ preventScroll: true });
    });
    dialog.addEventListener('keydown', event => {
      if (event.key !== 'Tab') return;
      const controls = [...dialog.querySelectorAll('button, input, textarea, select, a[href], [tabindex]')]
        .filter(control => !control.matches(':disabled, [type="hidden"], [tabindex="-1"]') && control.getClientRects().length);
      const first = controls[0], last = controls.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    });
    if (dialog.hasAttribute('data-editor-reopen')) {
      const opener = [...document.querySelectorAll('[data-tutorial-edit]')].find(button => button.dataset.tutorialEdit === dialog.id);
      openEditor(dialog, opener);
    }
  }
})();
