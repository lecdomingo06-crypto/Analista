(() => {
    const library = document.querySelector('[data-video-library]');
    if (library) {
        const items = library.querySelector('[data-video-items]');
        const controls = library.querySelector('[data-video-views]');
        const buttons = [...library.querySelectorAll('[data-video-view]')];
        const setView = view => {
            if (!['list', 'grid'].includes(view)) return;
            items.dataset.layout = view;
            buttons.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.videoView === view)));
        };
        try { setView(localStorage.getItem('analista-video-view') || 'grid'); } catch { setView('grid'); }
        controls.hidden = false;
        buttons.forEach(button => button.addEventListener('click', () => {
            setView(button.dataset.videoView);
            try { localStorage.setItem('analista-video-view', button.dataset.videoView); } catch {}
        }));
    }
    document.querySelectorAll('[data-video-player]').forEach(player => {
        const button = player.querySelector('[data-video-play]');
        if (!button) return;
        button.hidden = false;
        button.addEventListener('click', () => {
            const url = new URL(player.dataset.videoEmbed);
            if (url.origin !== 'https://www.youtube-nocookie.com' || !/^\/embed\/[A-Za-z0-9_-]{11}$/.test(url.pathname)) return;
            url.searchParams.set('autoplay', '1');
            const frame = document.createElement('iframe');
            frame.src = url.href;
            frame.title = player.dataset.videoTitle;
            frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
            frame.allowFullscreen = true;
            frame.referrerPolicy = 'strict-origin-when-cross-origin';
            player.replaceChildren(frame);
            frame.focus();
        }, { once: true });
    });
})();
