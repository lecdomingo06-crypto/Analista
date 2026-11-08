(() => {
  const rows = [...document.querySelectorAll('[data-evaluator-topic-row]')];
  if (!rows.length) return;

  function fitTopics(row) {
    const topics = [...row.querySelectorAll('[data-evaluator-topic]')];
    const more = row.querySelector('[data-evaluator-topic-more]');
    const approval = row.querySelector('.verified');
    if (!more || !approval) return;
    const count = more.querySelector('[data-evaluator-topic-count]');
    const description = more.querySelector('[data-evaluator-topic-description]');
    const gap = parseFloat(getComputedStyle(row).columnGap) || 0;
    const available = row.getBoundingClientRect().width;
    if (!available) return;

    topics.forEach(topic => { topic.hidden = false; });
    more.hidden = true;
    const widths = topics.map(topic => topic.getBoundingClientRect().width);
    const approvalWidth = approval.getBoundingClientRect().width;
    const occupied = visible => approvalWidth + widths.slice(0, visible).reduce((sum, width) => sum + width + gap, 0);

    // Four capsules at most, including approval and the optional +N capsule.
    if (topics.length <= 3 && occupied(topics.length) <= available + 0.5) return;

    const updateMore = visible => {
      const hiddenNames = topics.slice(visible).map(topic => topic.textContent.trim());
      count.textContent = `+${hiddenNames.length}`;
      description.textContent = `${hiddenNames.length} more ${hiddenNames.length === 1 ? 'area' : 'areas'} of expertise: ${hiddenNames.join(', ')}`;
      more.title = hiddenNames.join(', ');
    };

    more.hidden = false;
    let visible = Math.min(2, topics.length - 1);
    while (visible >= 0) {
      updateMore(visible);
      if (occupied(visible) + gap + more.getBoundingClientRect().width <= available + 0.5 || visible === 0) break;
      visible--;
    }
    topics.forEach((topic, index) => { topic.hidden = index >= visible; });
  }

  let frame;
  const refresh = () => {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => rows.forEach(fitTopics));
  };
  rows.forEach(fitTopics);
  document.fonts?.ready.then(refresh);
  if (typeof ResizeObserver !== 'undefined') {
    const observer = new ResizeObserver(refresh);
    rows.forEach(row => observer.observe(row));
  } else {
    window.addEventListener('resize', refresh, { passive: true });
  }
})();
