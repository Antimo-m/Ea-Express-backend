function applyTheme(theme) {
  document.documentElement.dataset.eaTheme = theme;
  document.querySelectorAll('[data-theme-toggle]').forEach(button => {
    button.setAttribute('aria-label', theme === 'dark' ? 'Attiva tema chiaro' : 'Attiva tema scuro');
    button.setAttribute('aria-pressed', String(theme === 'light'));
  });
  try { localStorage.setItem('ea:theme', theme); } catch { /* Preferenza valida per questa pagina. */ }
}
try { applyTheme(localStorage.getItem('ea:theme') || 'dark'); } catch { applyTheme('dark'); }
document.addEventListener('click', event => {
  if (event.target.closest('[data-theme-toggle]')) applyTheme(document.documentElement.dataset.eaTheme === 'light' ? 'dark' : 'light');
});
