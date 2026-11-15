{{-- TG-401 (A38) — Consentimiento de cookies y de contenido de terceros.

     SIEMPRE SE PINTA, SIEMPRE EMPIEZA ESCONDIDO. El atributo hidden sale en el
     HTML de todo el mundo y es el navegador quien lo quita si todavía no hay
     una decisión guardada. Tiene que ser así porque el service worker guarda
     una copia del inicio y se la sirve igual a todos (TG-282): si el servidor
     decidiera, la copia guardada enseñaría la decisión de otra persona.

     EFECTO BONITO DE HACERLO ASÍ: sin JavaScript no se carga ningún tercero,
     porque quien los inserta es el propio JavaScript. Queda seguro por
     defecto, no abierto por defecto.

     NO ATRAPA EL TECLADO. Es una barra, no un modal: no lleva role="dialog"
     ni encierra el foco, porque no bloquea la página. Quien llega aquí
     tabulando puede seguir de largo sin decidir nada, y mientras no decida no
     se carga nada de terceros. --}}
@php
    use App\Support\ConsentimientoCookies;
@endphp
<div id="fp-consentimiento" hidden
    data-cookie="{{ ConsentimientoCookies::COOKIE }}"
    data-version="{{ ConsentimientoCookies::VERSION }}"
    data-dias="{{ ConsentimientoCookies::DIAS }}"
    data-dominio="{{ ConsentimientoCookies::dominio() }}"
    role="region" aria-labelledby="fp-consentimiento-titulo"
    class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white shadow-[0_-6px_20px_-8px_rgb(15_23_42_/_0.25)]">
    <div class="mx-auto flex max-w-6xl flex-col gap-3 px-4 py-4 sm:px-5 md:flex-row md:items-center md:gap-6">
        <div class="min-w-0 flex-1">
            <p id="fp-consentimiento-titulo" class="text-sm font-semibold text-fp-sidebar">
                Cookies y contenido de terceros
            </p>
            {{-- Dice lo que de verdad pasa. Se revisó el proyecto entero: el
                 único tercero es el mapa, no hay analítica ni publicidad.
                 Prometer menos de lo que se hace es mentir igual. --}}
            <p class="mt-1 text-xs leading-relaxed text-slate-700">
                Este sitio usa solo <strong>cookies técnicas</strong>, las que hacen falta para que funcione: tu sesión y
                lo que elijas aquí. No hay analítica ni publicidad.
                La tienda de cada distribuidora puede mostrar un <strong>mapa de Google</strong>, que es un tercero:
                solo se carga si tú lo permites.
                <a href="{{ route('aviso-privacidad') }}" class="fp-enlace font-medium text-fp-primary">Aviso de privacidad</a>
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            {{-- "Solo las necesarias" va primero y no se ve menos importante
                 que la otra: si rechazar costara más trabajo que aceptar, la
                 elección no sería libre. --}}
            <button type="button" data-consentimiento="necesarias" class="fp-btn fp-btn-sm fp-btn-secundario">
                Solo las necesarias
            </button>
            <button type="button" data-consentimiento="terceros" class="fp-btn fp-btn-sm fp-btn-primario">
                Permitir el mapa
            </button>
        </div>
    </div>
</div>
