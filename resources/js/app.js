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

/*
 * A19 — Buscador compacto que se expande.
 *
 * Se abre al enfocarlo y se queda abierto mientras haya texto escrito. Los
 * botones de al lado no se mueven porque el buscador crece ENCIMA de un hueco
 * que conserva su tamaño; para el acomodo de la barra nada cambia.
 *
 * Por que en JavaScript y no en CSS: se intento primero con :focus-within y
 * :has(input:not(:placeholder-shown)). La regla queda bien formada, en el
 * @media correcto, el navegador soporta los tres selectores y la envoltura si
 * coincide con :focus-within, pero el ancho no cambia ni con !important. La
 * misma regla inyectada a mano en un <style> si funciona, asi que algo del
 * apilado de capas de Tailwind la tapa; no se encontro que. El estilo en
 * linea gana sobre cualquier capa y aqui si abre.
 *
 * Los anchos NO se escriben aqui: salen de las variables que define app.css
 * (--fp-buscador-reposo y --fp-buscador-abierto), para que se afinen en un
 * solo lugar y por pantalla si hace falta.
 */
function buscadoresCompactos() {
    document.querySelectorAll('[data-buscador-compacto]').forEach((hueco) => {
        if (hueco.dataset.a19 === 'listo') return;
        hueco.dataset.a19 = 'listo';

        const buscador = hueco.querySelector('.fp-buscador');
        const campo = hueco.querySelector('input');
        if (!buscador || !campo) return;

        const ancho = (nombre) => getComputedStyle(hueco).getPropertyValue(nombre).trim();

        const pintar = () => {
            // En telefono las variables no estan puestas (el compacto es solo
            // de sm en adelante): ahi no se toca nada y queda al ancho completo.
            const abierto = ancho('--fp-buscador-abierto');
            if (!abierto) {
                buscador.style.minWidth = '';
                delete hueco.dataset.abierto;
                return;
            }

            // Con texto escrito se queda abierto aunque se salga del campo: si
            // se cerrara, se pierde de vista lo que se esta filtrando.
            const debeAbrir = document.activeElement === campo || campo.value !== '';

            buscador.style.minWidth = debeAbrir ? abierto : ancho('--fp-buscador-reposo');

            if (debeAbrir) {
                hueco.dataset.abierto = '';
            } else {
                delete hueco.dataset.abierto;
            }
        };

        campo.addEventListener('focus', pintar);
        campo.addEventListener('blur', pintar);
        campo.addEventListener('input', pintar);
        // Al cambiar de tamaño la ventana puede entrar o salir del modo compacto.
        window.addEventListener('resize', pintar);

        pintar();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', buscadoresCompactos);
} else {
    buscadoresCompactos();
}
// Livewire redibuja listados enteros: los buscadores nuevos tambien se enganchan.
document.addEventListener('livewire:navigated', buscadoresCompactos);
document.addEventListener('livewire:update', buscadoresCompactos);

/*
 * TG-282 — Registro del service worker.
 *
 * Va aquí porque este archivo ya lo cargan los cinco layouts, así que no
 * hace falta tocarlos otra vez.
 *
 * Solo en https (o en localhost, que el navegador trata igual): sin eso el
 * navegador lo rechaza y deja un error en consola que no significa nada.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch((error) => {
            // Que falle no rompe nada: la app sigue funcionando igual, solo
            // sin caché ni página de sin conexión.
            console.warn('No se pudo registrar el service worker:', error);
        });
    });
}
