import './bootstrap';

// TG-234: animación de entrada al hacer scroll ([data-revelar]). Respeta prefers-reduced-motion vía CSS.
function revelarAlHacerScroll() {
    const elementos = document.querySelectorAll('[data-revelar]:not(.revelado)');
    if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        elementos.forEach((el) => el.classList.add('revelado'));
        return;
    }
    const observador = new IntersectionObserver((entradas) => {
        entradas.forEach((e) => {
            if (e.isIntersecting) {
                e.target.classList.add('revelado');
                observador.unobserve(e.target);
            }
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    elementos.forEach((el) => observador.observe(el));
}
document.addEventListener('DOMContentLoaded', revelarAlHacerScroll);
document.addEventListener('livewire:navigated', revelarAlHacerScroll);
