/**
 * TG-401 (A38) — El mapa de Google solo entra cuando lo permiten.
 *
 * El «listo cuando» de la tarjeta es que NADA de terceros se cargue antes de
 * aceptar, y el mapa es el único tercero que hay en todo el proyecto. Por eso
 * el iframe no viene en el HTML: viene un hueco con su dirección guardada en
 * un data-, y este archivo lo cambia por el iframe cuando hay permiso.
 *
 * Hacerlo así tiene una consecuencia que vale la pena entender: sin
 * JavaScript el mapa nunca aparece. Es a propósito. Es mejor quedarse sin
 * mapa que conectar a alguien con Google sin haberle preguntado, y la
 * dirección escrita y el botón «Cómo llegar» siguen ahí para quien lo
 * necesite.
 */
export default function iniciarMapaConPermiso() {
    pintarLosQuePuedan();

    document.addEventListener('fp:consentimiento', pintarLosQuePuedan);

    // La tienda es Livewire: al filtrar redibuja la pantalla y el hueco
    // vuelve a aparecer sin iframe. Hay que volver a pintarlo.
    document.addEventListener('livewire:update', pintarLosQuePuedan);
}

function pintarLosQuePuedan() {
    const huecos = document.querySelectorAll('[data-mapa-con-permiso]');

    if (!huecos.length) {
        return;
    }

    const hayPermiso = window.fpConsentimiento?.acepta('terceros') === true;

    huecos.forEach((hueco) => {
        if (hayPermiso) {
            pintar(hueco);

            return;
        }

        // Sin permiso todavía: el botón de aquí pide el permiso y de paso
        // resuelve la barra de abajo, para no preguntar dos veces lo mismo.
        const boton = hueco.querySelector('[data-permitir-mapa]');

        if (boton && !boton.dataset.enganchado) {
            boton.dataset.enganchado = '1';
            boton.addEventListener('click', () => window.fpConsentimiento?.permitir('terceros'));
        }
    });
}

function pintar(hueco) {
    if (hueco.dataset.pintado) {
        return;
    }

    hueco.dataset.pintado = '1';

    const mapa = document.createElement('iframe');
    mapa.src = hueco.dataset.src;
    mapa.title = hueco.dataset.titulo;
    mapa.className = 'h-72 w-full';
    mapa.loading = 'lazy';
    mapa.referrerPolicy = 'no-referrer-when-downgrade';

    hueco.replaceChildren(mapa);
    hueco.classList.remove('flex', 'flex-col', 'items-center', 'justify-center', 'gap-3', 'px-6', 'text-center', 'bg-fp-page');
}
