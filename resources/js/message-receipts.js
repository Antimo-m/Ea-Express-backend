export function observeMessageReceipts(container, messages, acknowledge, ownSender, onError = () => {}) {
  let stopped = false, timer;
  const visible = new Set(), pending = new Set(), confirmed = new Set();
  const incoming = messages.filter(message => message.sender !== ownSender && !message.read_at);
  const send = async (ids, state) => {
    ids = ids.filter(id => !pending.has(`${state}:${id}`) && !confirmed.has(`${state}:${id}`));
    if (!ids.length || stopped) return;
    ids.forEach(id => pending.add(`${state}:${id}`));
    try {
      await acknowledge(ids, state);
      ids.forEach(id => confirmed.add(`${state}:${id}`));
    } catch (error) { if (!stopped) onError(error); }
    finally { ids.forEach(id => pending.delete(`${state}:${id}`)); }
  };
  const read = () => { if (!document.hidden) send([...visible], 'read'); };
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      const id = Number(entry.target.dataset.messageId);
      if (entry.isIntersecting && entry.intersectionRect.height >= Math.min(80, entry.boundingClientRect.height)) visible.add(id);
      else visible.delete(id);
    });
    clearTimeout(timer); timer = setTimeout(read, 250);
  }, { threshold: [0, 0.25, 0.5, 0.75, 1] });
  incoming.forEach(message => {
    const element = container.querySelector(`[data-message-id="${message.id}"]`);
    if (element) observer.observe(element);
  });
  send(incoming.filter(message => !message.delivered_at).map(message => message.id), 'delivered');
  document.addEventListener('visibilitychange', read);
  return () => { stopped = true; clearTimeout(timer); observer.disconnect(); document.removeEventListener('visibilitychange', read); };
}
