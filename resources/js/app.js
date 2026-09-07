import 'bootstrap/js/dist/offcanvas';
import 'bootstrap/js/dist/dropdown';

for (const button of document.querySelectorAll('[data-password-toggle]')) {
    button.hidden = false;
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(show));
        button.setAttribute('aria-label', show ? 'Nascondi password' : 'Mostra password');
        button.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
}
