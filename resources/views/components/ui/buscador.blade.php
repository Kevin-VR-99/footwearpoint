{{--
    TG-234: buscador reutilizable con lupa, borde marcado, sombra y anillo
    fp-primary al enfocar.

    A19 — "compacto" lo deja angosto en reposo y lo expande al enfocarlo,
    sin mover los botones de al lado (la envoltura conserva su ancho y el
    que crece es el input, encima). Se queda abierto mientras haya algo
    escrito. En telefono no aplica: ahi va al ancho completo.

    Es opcional y no automatico: hay 26 buscadores en 18 pantallas y no
    todos viven en una barra de listado. Se enciende donde toca.

        <x-ui.buscador compacto wire:model.live="buscar" placeholder="Buscar cliente" />
--}}
@props([
    'placeholder' => 'Buscar…',
    'etiqueta' => null,
    'contenedor' => '',
    'compacto' => false,
])
@if ($compacto)
    {{-- El hueco conserva su tamaño en la barra: por eso los botones de al
         lado no se mueven cuando el buscador se abre encima. --}}
    <span class="fp-buscador-hueco {{ $attributes->get('class') }}" data-buscador-compacto>
@endif
{{-- Solo la clase: los demas atributos (wire:model, aria-*) son del
     <input>, y duplicarlos en la etiqueta hace que Livewire se enganche
     dos veces al mismo campo. --}}
<label {{ $compacto ? $attributes->only('class')->class('fp-buscador') : $attributes->only('class')->class(['fp-buscador', $contenedor]) }} data-buscador>
    <span class="sr-only">{{ $etiqueta ?? $placeholder }}</span>
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
        aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" />
    </svg>
    <input type="search" autocomplete="off" placeholder="{{ $placeholder }}"
        {{ $attributes->except(['class'])->merge(['aria-label' => $etiqueta ?? $placeholder]) }}
        class="fp-buscador-input">
</label>
@if ($compacto)
    </span>
@endif
