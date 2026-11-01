(() => {
  const deck = document.querySelector('[data-evaluator-deck]');
  if (!deck) return;
  const cards = [...deck.querySelectorAll('[data-evaluator-card]')];
  if (cards.length < 2) return;
  const announcement = document.querySelector('[data-evaluator-announcement]');
  const help = document.querySelector('[data-evaluator-help]');
  let active = 0;

  const sizeDeck = () => {
    const height = Math.max(...cards.map(card => card.offsetHeight));
    deck.style.setProperty('--evaluator-deck-height', `${height + 80}px`);
  };
  const showCard = (index, announce = false) => {
    active = index;
    cards.forEach((card, position) => {
      const offset = (position - active + cards.length) % cards.length;
      const center = offset === 0;
      card.classList.toggle('is-center', center);
      card.classList.toggle('is-left', offset === 1);
      card.classList.toggle('is-right', offset === 2);
      card.tabIndex = center ? -1 : 0;
      if (center) {
        card.removeAttribute('role');
        card.removeAttribute('aria-label');
        card.removeAttribute('aria-describedby');
      } else {
        card.setAttribute('role', 'button');
        card.setAttribute('aria-label', `Show ${card.dataset.evaluatorName}'s evaluator card`);
      }
      card.querySelectorAll('a').forEach(link => {
        if (center) { link.removeAttribute('tabindex'); link.removeAttribute('aria-hidden'); }
        else { link.tabIndex = -1; link.setAttribute('aria-hidden', 'true'); }
      });
    });
    if (announce && announcement) announcement.textContent = `Showing ${cards[active].dataset.evaluatorName}, evaluator ${active + 1} of ${cards.length}.`;
  };

  cards.forEach((card, index) => {
    const activate = event => {
      if (index === active) return;
      event.preventDefault();
      showCard(index, true);
      card.focus({ preventScroll: true });
    };
    card.addEventListener('click', activate);
    card.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') activate(event);
    });
  });
  showCard(0);
  deck.dataset.deckReady = 'true';
  if (help) help.hidden = false;
  sizeDeck();
  if (typeof ResizeObserver !== 'undefined') {
    const observer = new ResizeObserver(sizeDeck);
    cards.forEach(card => observer.observe(card));
  }
  window.addEventListener('resize', sizeDeck, { passive: true });
  document.fonts?.ready.then(sizeDeck);
})();
