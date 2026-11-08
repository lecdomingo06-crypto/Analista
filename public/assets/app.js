(() => {
  // Browser history may restore HTML rendered before the user signed in or out.
  const renderedUserId = document.body.dataset.authUserId || '';
  const sessionStatusUrl = document.body.dataset.sessionStatusUrl;
  let checkingSession = false;
  const refreshSessionNavigation = async () => {
    if (!sessionStatusUrl || checkingSession) return;
    checkingSession = true;
    try {
      const response = await fetch(sessionStatusUrl, {
        headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store',
        signal: AbortSignal.timeout(8000),
      });
      if (response.redirected || response.status === 401) { window.location.reload(); return; }
      if (!response.ok) return;
      const current = await response.json();
      const currentUserId = current.authenticated ? String(current.user_id) : '';
      if (currentUserId !== renderedUserId) window.location.reload();
    } catch { /* Keep the current page and unsent input while the connection is unavailable. */ }
    finally { checkingSession = false; }
  };
  window.addEventListener('pageshow', event => { if (event.persisted) refreshSessionNavigation(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshSessionNavigation(); });
  const menu = document.querySelector('[data-menu]');
  const closeMenu = () => { document.body.classList.remove('menu-open'); menu?.setAttribute('aria-expanded','false'); };
  menu?.addEventListener('click', () => { const open = document.body.classList.toggle('menu-open'); menu.setAttribute('aria-expanded', String(open)); });
  document.querySelector('[data-overlay]')?.addEventListener('click', closeMenu);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { const search = document.querySelector('[data-global-search]'); if (search) { e.preventDefault(); search.focus(); } } });
  const themeChoices = document.querySelectorAll('button[data-theme-preference]');
  const systemTheme = matchMedia('(prefers-color-scheme: dark)');
  const reducedThemeMotion = matchMedia('(prefers-reduced-motion: reduce)');
  const themeRoot = document.documentElement;
  const validThemes = new Set(['system', 'light', 'dark']);
  let themePreference = 'light';
  let themeTransition = null;
  let themeRevision = 0;
  const applyTheme = (preference, animate = false) => {
    const revision = ++themeRevision;
    themePreference = validThemes.has(preference) ? preference : 'light';
    const nextTheme = themePreference === 'system'
      ? (systemTheme.matches ? 'dark' : 'light') : themePreference;
    themeRoot.dataset.themeMode = themePreference;
    themeChoices.forEach(button => {
      button.setAttribute('aria-pressed', String(button.dataset.themePreference === themePreference));
    });

    // Finish rapid changes immediately; skipped callbacks must not restore an old choice.
    const interrupted = themeTransition !== null;
    themeTransition?.skipTransition();
    themeTransition = null;
    themeRoot.removeAttribute('data-theme-transition');
    const updateTheme = () => {
      if (revision === themeRevision) themeRoot.dataset.theme = nextTheme;
    };
    if (!animate || interrupted || reducedThemeMotion.matches || document.hidden
      || themeRoot.dataset.theme === nextTheme || typeof document.startViewTransition !== 'function') {
      updateTheme();
      return;
    }

    // One root snapshot fade avoids repainting color transitions on every card and star.
    themeRoot.setAttribute('data-theme-transition', '');
    try {
      const transition = document.startViewTransition(updateTheme);
      themeTransition = transition;
      const finishTransition = () => {
        if (themeTransition !== transition) return;
        themeTransition = null;
        themeRoot.removeAttribute('data-theme-transition');
      };
      transition.ready.catch(() => {}); // Skipping an in-progress transition rejects ready.
      transition.updateCallbackDone.catch(updateTheme);
      transition.finished.then(finishTransition, finishTransition);
    } catch {
      themeTransition = null;
      themeRoot.removeAttribute('data-theme-transition');
      updateTheme();
    }
  };
  try { themePreference = localStorage.getItem('analista-theme') || 'light'; } catch {}
  applyTheme(themePreference);
  themeChoices.forEach(button => button.addEventListener('click', () => {
    const preference = button.dataset.themePreference;
    applyTheme(preference, true);
    try { localStorage.setItem('analista-theme', themePreference); } catch {}
  }));
  // Root snapshots retarget pointer clicks; keep only the theme picker usable mid-fade.
  document.addEventListener('click', event => {
    if (!themeTransition || event.defaultPrevented || !event.isTrusted || event.button !== 0 || event.detail < 1
      || event.target !== themeRoot) return;
    if ([...document.querySelectorAll('[aria-modal="true"], dialog[open]')].some(dialog => dialog.getClientRects().length)) return;
    const choice = [...themeChoices].find(button => {
      const bounds = button.getBoundingClientRect();
      return !button.disabled && !button.closest('[inert], [hidden]') && bounds.width > 0 && bounds.height > 0
        && getComputedStyle(button).visibility === 'visible'
        && event.clientX >= bounds.left && event.clientX <= bounds.right
        && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
    });
    if (!choice) return;
    event.preventDefault();
    choice.focus({ preventScroll: true });
    choice.click();
  });
  systemTheme.addEventListener('change', () => {
    if (themePreference === 'system') applyTheme('system', true);
  });
  window.addEventListener('storage', event => {
    if (event.key === 'analista-theme' || event.key === null) applyTheme(event.newValue, true);
  });
  reducedThemeMotion.addEventListener('change', () => {
    if (reducedThemeMotion.matches) applyTheme(themePreference);
  });
  window.addEventListener('pagehide', () => {
    if (themeTransition) applyTheme(themePreference);
  });
  document.querySelectorAll('[data-file-input]').forEach(input => input.addEventListener('change', () => { const label = document.querySelector(`[data-file-label="${input.id}"]`); if (label && input.files[0]) label.textContent = input.files[0].name; }));
  document.querySelectorAll('[data-project-select]').forEach(select => select.addEventListener('change', () => { document.querySelectorAll('[data-project-versions]').forEach(group => { const selected = group.dataset.projectVersions === select.value; group.hidden = !selected; group.querySelectorAll('select').forEach(input => { input.disabled = !selected; }); }); }));
  document.querySelectorAll('[data-choose-project]').forEach(form => form.addEventListener('submit', e => { e.preventDefault(); const destination = form.querySelector('select')?.value; if (destination) window.location.assign(destination + '#request-review'); }));
  const projectErrors = document.querySelector('[data-project-errors]');
  if (projectErrors && !projectErrors.hidden) {
    requestAnimationFrame(() => {
      projectErrors.focus({ preventScroll: true });
      projectErrors.scrollIntoView({ block: 'start', behavior: 'instant' });
    });
  }
  document.querySelectorAll('.verification-form').forEach(form => {
    const updateEvidenceFields = () => {
      const kind = form.querySelector('[data-evidence-kind]:checked')?.value;
      form.querySelectorAll('[data-evidence-fields]').forEach(group => {
        const selected = group.dataset.evidenceFields.split(' ').includes(kind);
        group.hidden = !selected;
        group.querySelectorAll('input, textarea, select').forEach(input => {
          input.disabled = !selected;
          if (input.hasAttribute('data-evidence-required')) input.required = selected;
        });
      });
    };
    form.querySelectorAll('[data-evidence-kind]').forEach(input => input.addEventListener('change', updateEvidenceFields));
    updateEvidenceFields();
  });
})();
