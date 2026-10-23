{{--
    Abrir la app (si está instalada) o descargarla. El botón principal es un
    intent de Android: abre la app en la pantalla indicada y, si no está
    instalada, Chrome manda a la descarga. En iPhone o computadora el intent no
    hace nada, por eso el enlace de descarga queda siempre visible.
--}}
@props(['destino' => 'perfil'])

<div {{ $attributes->class('mt-6 flex flex-col items-stretch gap-2 sm:flex-row sm:justify-center') }} data-app-movil>
    <a href="{{ \App\Support\AppMovil::urlAbrir($destino) }}" class="fp-btn fp-btn-primario justify-center" data-abrir-app>
        Abrir la app
    </a>
    <a href="{{ \App\Support\AppMovil::urlDescarga() }}" class="fp-btn fp-btn-secundario justify-center" data-descargar-app rel="noopener">
        Descargar la app
    </a>
</div>
