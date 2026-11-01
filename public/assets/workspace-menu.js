(() => {
    const menus = [...document.querySelectorAll('[data-workspace-menu]')];
    if (!menus.length) return;
    const wideLayout = window.matchMedia('(min-width: 1601px)');

    const hasRoomBesideContent = menu => {
        const main = menu.closest('main');
        if (!main) return false;
        const contentRight = main.getBoundingClientRect().right - parseFloat(getComputedStyle(main).paddingRight);
        const menuLeft = document.documentElement.clientWidth - 16 - 240;
        return menuLeft >= contentRight + 16;
    };

    const applyLayout = () => {
        menus.forEach(menu => { menu.open = wideLayout.matches && hasRoomBesideContent(menu); });
    };

    menus.forEach(menu => {
        menu.addEventListener('keydown', event => {
            if (event.key !== 'Escape' || !menu.open) return;
            event.preventDefault();
            menu.open = false;
            menu.querySelector('summary')?.focus();
        });
        menu.addEventListener('focusout', event => {
            if (event.relatedTarget && !menu.contains(event.relatedTarget)
                && (!wideLayout.matches || !hasRoomBesideContent(menu))) menu.open = false;
        });
    });

    document.addEventListener('click', event => {
        menus.forEach(menu => {
            if (!menu.contains(event.target) && (!wideLayout.matches || !hasRoomBesideContent(menu))) menu.open = false;
        });
    });

    window.addEventListener('resize', applyLayout);
    applyLayout();
})();
