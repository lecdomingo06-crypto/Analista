(() => {
  const region = document.querySelector('[data-toast-region]');
  if (!region) return;
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
  const initialized = new WeakSet();

  function initializeToast(toast) {
    if (initialized.has(toast)) return;
    const close = toast.querySelector('[data-toast-close]');
    if (!close) return;
    initialized.add(toast);
    close.hidden = false;
    const timed = ['success', 'info'].includes(toast.dataset.toastType);
    const message = toast.querySelector('.toast-message')?.textContent || '';
    let remaining = Math.min(15000, Math.max(7000, message.length * 55));
    let startedAt = 0;
    let timer;
    let leaving = false;

    const pause = () => {
      if (!timer) return;
      clearTimeout(timer);
      timer = undefined;
      remaining = Math.max(0, remaining - (performance.now() - startedAt));
    };
    const dismiss = () => {
      if (leaving) return;
      leaving = true;
      pause();
      if (toast.contains(document.activeElement)) {
        const nextClose = Array.from(region.querySelectorAll('[data-toast-close]'))
          .find(button => !toast.contains(button) && !button.closest('.is-leaving'));
        if (nextClose) nextClose.focus({ preventScroll: true });
        else {
          const main = document.getElementById('main');
          if (main) {
            const hadTabindex = main.hasAttribute('tabindex');
            if (!hadTabindex) {
              main.setAttribute('tabindex', '-1');
              main.addEventListener('blur', () => main.removeAttribute('tabindex'), { once: true });
            }
            main.focus({ preventScroll: true });
          }
        }
      }
      toast.classList.add('is-leaving');
      document.removeEventListener('visibilitychange', onVisibilityChange);
      window.removeEventListener('pagehide', pause);
      window.removeEventListener('pageshow', resume);
      if (reducedMotion.matches) toast.remove();
      else setTimeout(() => toast.remove(), 220);
    };
    const resume = () => {
      if (!timed || leaving || timer || document.hidden || toast.matches(':hover') || toast.contains(document.activeElement)) return;
      startedAt = performance.now();
      timer = setTimeout(dismiss, remaining);
    };

    close.addEventListener('click', dismiss);
    toast.addEventListener('pointerenter', pause);
    toast.addEventListener('pointerleave', resume);
    toast.addEventListener('focusin', pause);
    toast.addEventListener('focusout', () => requestAnimationFrame(resume));
    const onVisibilityChange = () => document.hidden ? pause() : resume();
    document.addEventListener('visibilitychange', onVisibilityChange);
    // A restored page may retain its DOM after its old timers were suspended.
    window.addEventListener('pagehide', pause);
    window.addEventListener('pageshow', resume);
    resume();
  }

  region.querySelectorAll('[data-toast]').forEach(initializeToast);
  new MutationObserver(records => {
    for (const record of records) {
      for (const node of record.addedNodes) {
        if (node instanceof HTMLElement && node.matches('[data-toast]')) initializeToast(node);
      }
    }
  }).observe(region, { childList: true });
})();
