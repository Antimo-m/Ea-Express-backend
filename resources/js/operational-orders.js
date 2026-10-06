export function restoreOrderPreviews(root = document, openSlugs = []) {
  for (const row of root.querySelectorAll('[data-operational-order]')) {
    const expanded = openSlugs.includes(row.dataset.operationalOrder);
    row.querySelector('[data-order-preview-toggle]').setAttribute('aria-expanded', String(expanded));
    row.querySelector('.operational-preview').hidden = !expanded;
  }
}

document.addEventListener('click', event => {
  const button = event.target.closest('[data-order-preview-toggle]');
  if (!button) return;
  const preview = document.getElementById(button.getAttribute('aria-controls'));
  const expanded = button.getAttribute('aria-expanded') !== 'true';
  button.setAttribute('aria-expanded', String(expanded));
  preview.hidden = !expanded;
});
