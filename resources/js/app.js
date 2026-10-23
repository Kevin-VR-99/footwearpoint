import './bootstrap';

// Marca que el JS arrancó: solo entonces [data-revelar] empieza oculto (ver app.css).
document.documentElement.classList.add('fp-js');

// TG-234: animación de entrada al hacer scroll ([data-revelar]). Respeta prefers-reduced-motion.
function revelarAlHacerScroll() {
    const elementos = document.querySelectorAll('[data-revelar]:not(.revelado)');
    const revelarTodo = () => document.querySelectorAll('[data-revelar]:not(.revelado)').forEach((el) => el.classList.add('revelado'));
    if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        revelarTodo();
        return;
    }
    const observador = new IntersectionObserver((entradas) => {
        entradas.forEach((e) => {
            if (e.isIntersecting) {
                e.target.classList.add('revelado');
                observador.unobserve(e.target);
            }
        });
    }, { threshold: 0.01 });
    elementos.forEach((el) => observador.observe(el));
    // Respaldo: nada se queda invisible aunque el observador no dispare.
    setTimeout(revelarTodo, 1500);
}

// Capas "Cargando…": si una petición falla, se cancela o se navega con wire:navigate,
// se ocultan siempre para que nunca queden pegadas tapando la pantalla.
function ocultarCapasDeCarga() {
    document.querySelectorAll('[data-cargando]').forEach((el) => { el.style.display = 'none'; });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', revelarAlHacerScroll);
} else {
    revelarAlHacerScroll();
}
document.addEventListener('livewire:navigated', () => { ocultarCapasDeCarga(); revelarAlHacerScroll(); });
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ respond, fail }) => {
        fail(({ status, preventDefault }) => {
            setTimeout(ocultarCapasDeCarga, 0);
            // Sesion persistente: con el token CSRF vencido (419) Livewire
            // pregunta "This page has expired" y parece que se cerro la
            // sesion. Se recarga sola: la cookie "remember" mantiene al
            // usuario dentro y la pagina trae un token nuevo.
            if (status === 419) {
                preventDefault();
                window.location.reload();
            }
        });
        respond(() => setTimeout(() => {
            // Tras responder, si no queda ninguna petición en curso, ninguna capa debe seguir visible.
            if (!document.querySelector('[wire\\:loading][data-loading], [data-loading]')) ocultarCapasDeCarga();
        }, 50));
    });
});
window.addEventListener('pageshow', ocultarCapasDeCarga);
