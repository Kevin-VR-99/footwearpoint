{{-- TG-234: buscador reutilizable con lupa, borde marcado, sombra y anillo fp-primary al enfocar. --}}
@props([
    'placeholder' => 'Buscar…',
    'etiqueta' => null,
    'contenedor' => '',
])
<label {{ $attributes->only('class')->class(['fp-buscador', $contenedor]) }} data-buscador>
    <span class="sr-only">{{ $etiqueta ?? $placeholder }}</span>
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
        aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" />
    </svg>
    <input type="search" autocomplete="off" placeholder="{{ $placeholder }}"
        {{ $attributes->except(['class'])->merge(['aria-label' => $etiqueta ?? $placeholder]) }}
        class="fp-buscador-input">
</label>
