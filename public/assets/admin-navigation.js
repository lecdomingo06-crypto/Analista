(() => {
    const menus = [...document.querySelectorAll('[data-admin-navigation]')];
    if (!menus.length) return;
    const compactNavigation = window.matchMedia('(max-width: 1600px)');

    const applyLayout = () => {
        menus.forEach(menu => { menu.open = !compactNavigation.matches; });
    };

    menus.forEach(menu => {
        menu.addEventListener('keydown', event => {
            if (event.key !== 'Escape' || !menu.open) return;
            event.preventDefault();
            menu.open = false;
            menu.querySelector('summary')?.focus();
        });
        menu.addEventListener('focusout', event => {
            if (compactNavigation.matches && event.relatedTarget && !menu.contains(event.relatedTarget)) menu.open = false;
        });
    });

    document.addEventListener('click', event => {
        menus.forEach(menu => {
            if (compactNavigation.matches && !menu.contains(event.target)) menu.open = false;
        });
    });

    compactNavigation.addEventListener('change', applyLayout);
    applyLayout();
})();
