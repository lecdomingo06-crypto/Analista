(() => {
    const revealHash = () => {
        let id;
        try { id = decodeURIComponent(window.location.hash.slice(1)); } catch { return; }
        if (!id) return;
        const target = document.getElementById(id);
        if (!target || !target.matches('.faq-question, .faq-group')) return;
        if (target.matches('.faq-question')) target.open = true;
        requestAnimationFrame(() => target.scrollIntoView({ block: 'start' }));
    };

    window.addEventListener('hashchange', revealHash);
    revealHash();
})();
