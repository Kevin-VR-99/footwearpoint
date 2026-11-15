/**
 * TG-401 (A38) — Consentimiento de cookies y de contenido de terceros.
 *
 * QUIÉN MANDA AQUÍ. El navegador, no el servidor. El service worker guarda
 * una copia del inicio y se la sirve igual a todo el mundo (TG-282), así que
 * el HTML que llega es el mismo para quien ya decidió y para quien no. Este
 * archivo es el que lee la elección de cada quien y la aplica.
 *
 * CÓMO LO USA QUIEN CARGA UN TERCERO (el mapa, en A39). Dos cosas, no una:
 *
 *   if (window.fpConsentimiento?.acepta('terceros')) { cargar(); }
 *   document.addEventListener('fp:consentimiento', (e) => {
 *       if (e.detail.terceros) cargar();
 *   });
 *
 * La primera es para cuando la página abre con el permiso ya dado; la segunda
 * para cuando lo dan en ese momento.
 *
 * SUPUESTO: la barra vive en el layout y el layout no se reemplaza, porque
 * las pantallas públicas no usan wire:navigate. Si algún día lo usan, esto se
 * tiene que volver a enganchar en 'livewire:navigated' o la barra quedaría
 * apuntando a un elemento que ya no está en la página. El estado inicial también se avisa por el
 * evento, pero en un microtask, para que a nadie se le escape por haberse
 * registrado un renglón más abajo.
 */

const EVENTO = 'fp:consentimiento';

/** Nada de esto vale la pena si tumba la página: las cookies a veces están bloqueadas. */
function leerCookie(nombre) {
    try {
        const parejas = document.cookie ? document.cookie.split('; ') : [];
        for (const pareja of parejas) {
            const corte = pareja.indexOf('=');
            if (corte > 0 && pareja.slice(0, corte) === nombre) {
                return decodeURIComponent(pareja.slice(corte + 1));
            }
        }
    } catch (e) {
        // Navegador con cookies bloqueadas: se trata como "no ha decidido".
    }

    return null;
}

function escribirCookie(nombre, valor, dias, dominio) {
    try {
        const trozos = [
            `${nombre}=${encodeURIComponent(valor)}`,
            'path=/',
            `max-age=${Math.round(dias * 24 * 60 * 60)}`,
            // Lax y no None: esta cookie nunca hace falta en una petición que
            // venga de otro sitio, y Lax no viaja en esas.
            'SameSite=Lax',
        ];

        if (dominio) {
            trozos.push(`domain=${dominio}`);
        }

        if (location.protocol === 'https:') {
            trozos.push('Secure');
        }

        document.cookie = trozos.join('; ');

        return true;
    } catch (e) {
        return false;
    }
}

/**
 * Lo que la persona ya decidió, o null si todavía no decide.
 *
 * Si la versión guardada no es la de ahora, vale null a propósito: significa
 * que los terceros cambiaron desde que dijo que sí, y un permiso dado para un
 * mapa no es un permiso para lo que haya entrado después.
 */
function decisionGuardada(banner) {
    const crudo = leerCookie(banner.dataset.cookie);

    if (!crudo) {
        return null;
    }

    const corte = crudo.indexOf(':');
    const version = corte > 0 ? crudo.slice(0, corte) : '';

    if (version !== `v${banner.dataset.version}`) {
        return null;
    }

    return crudo
        .slice(corte + 1)
        .split(',')
        .map((c) => c.trim())
        .filter(Boolean);
}

export default function iniciarConsentimiento() {
    const banner = document.getElementById('fp-consentimiento');

    if (!banner) {
        return;
    }

    // 'necesarias' siempre está: sin la cookie de sesión no hay sesión. Se
    // guarda igual para que la cookie diga qué se aceptó y no solo qué no.
    let aceptadas = ['necesarias'];

    function avisar() {
        document.dispatchEvent(
            new CustomEvent(EVENTO, {
                detail: { terceros: aceptadas.includes('terceros'), categorias: [...aceptadas] },
            })
        );
    }

    // El botón del pie se enseña solo si este archivo corrió: sin JavaScript
    // no abriría nada y sería un control muerto.
    const abridor = document.querySelector('[data-abrir-consentimiento]');

    // Si la barra se abrió desde el pie, al cerrarla el foco vuelve ahí. Si
    // salió sola al cargar, NO: devolverlo al pie mandaría a la persona hasta
    // el final de una página que acaba de abrir.
    let vinoDelPie = false;

    function mostrar(desdeElPie) {
        vinoDelPie = desdeElPie;
        banner.hidden = false;

        // Quien lo abrió a propósito navega con teclado o con lector: el foco
        // tiene que ir a los botones, o activaría algo que aparece lejos de
        // donde está parado y no lo encontraría.
        if (desdeElPie) {
            banner.querySelector('[data-consentimiento]')?.focus();
        }
    }

    function decidir(categoria) {
        aceptadas = categoria === 'terceros' ? ['necesarias', 'terceros'] : ['necesarias'];

        escribirCookie(
            banner.dataset.cookie,
            `v${banner.dataset.version}:${aceptadas.join(',')}`,
            Number(banner.dataset.dias) || 180,
            banner.dataset.dominio
        );

        banner.hidden = true;
        avisar();

        if (vinoDelPie) {
            abridor?.focus();
            vinoDelPie = false;
        }
    }

    banner.querySelectorAll('[data-consentimiento]').forEach((boton) => {
        boton.addEventListener('click', () => decidir(boton.dataset.consentimiento));
    });

    if (abridor) {
        abridor.hidden = false;
        abridor.addEventListener('click', () => mostrar(true));
    }

    const guardada = decisionGuardada(banner);

    if (guardada === null) {
        mostrar(false);
    } else {
        aceptadas = guardada.includes('terceros') ? ['necesarias', 'terceros'] : ['necesarias'];
    }

    window.fpConsentimiento = {
        acepta: (categoria) => aceptadas.includes(categoria),
        abrir: () => mostrar(true),
        // Para un botón que pide el permiso donde hace falta, como el
        // «Mostrar el mapa» de la tienda: ese clic es consentimiento igual
        // que el de la barra, y se guarda igual.
        permitir: (categoria) => decidir(categoria),
    };

    // En microtask: así alcanza también a quien se registró al evento más
    // abajo en el mismo paquete de JavaScript.
    queueMicrotask(avisar);
}
