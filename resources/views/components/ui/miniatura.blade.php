{{-- Foto chica del calzado junto al SKU o al nombre. Si no hay foto, el hueco se queda para que la fila no brinque. --}}
@props([
    'url' => null,
    'alt' => 'Producto',
])

<span {{ $attributes->class('inline-flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white') }}>
    @if (filled($url))
        <img src="{{ $url }}" alt="{{ $alt }}" class="h-full w-full object-contain" loading="lazy" width="40" height="40">
    @else
        <span class="sr-only">Sin foto</span>
    @endif
</span>
