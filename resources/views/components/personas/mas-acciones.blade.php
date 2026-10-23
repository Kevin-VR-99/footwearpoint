{{-- Menú «Más acciones» de una fila: botón con icono y lista desplegable. Escape o clic afuera lo cierran. --}}
@props(['persona' => null, 'etiqueta' => 'Más acciones'])

<div class="fp-mas-acciones relative" x-data="{ abierto: false }" x-on:keydown.escape.stop="abierto = false; $refs.boton.focus()" x-on:click.outside="abierto = false" {{ $attributes }}>
    <button type="button" x-ref="boton" class="fp-accion-persona" x-on:click="abierto = ! abierto"
        aria-haspopup="menu" x-bind:aria-expanded="abierto ? 'true' : 'false'"
        aria-label="{{ $persona ? $etiqueta.' para '.$persona : $etiqueta }}" title="{{ $etiqueta }}">
        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/></svg>
        <span class="fp-mas-acciones-texto">Más</span>
    </button>
    <div class="fp-menu-acciones" role="menu" x-cloak x-show="abierto"
        x-transition:enter="fp-menu-entra" x-transition:enter-start="fp-menu-oculto" x-transition:leave="fp-menu-sale" x-transition:leave-end="fp-menu-oculto"
        x-on:click="if ($event.target.closest('[role=menuitem]')) abierto = false">
        {{ $slot }}
    </div>
</div>
