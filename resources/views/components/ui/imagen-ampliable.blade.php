{{-- Miniatura que, con foto, abre un modal pequeño (≈360 px) con la imagen del calzado.
     Se cierra con clic afuera, Esc o el botón. Sin foto, el marcador solo tiembla un poco al pasar el cursor o al hacer clic.
     tamano: chica (listas, 40 px), media (galería, 80 px), portada (foto grande de la ficha). --}}
@props([
    'url' => null,
    'alt' => 'Producto',
    'tamano' => 'chica',
])

@php
    $caja = match ($tamano) {
        'media' => 'h-20 w-20',
        'portada' => 'aspect-square w-full',
        default => 'h-10 w-10',
    };
    $portada = $tamano === 'portada';
    $ajuste = $portada ? 'object-cover' : 'object-contain';
    $pixeles = match ($tamano) {
        'media' => 80,
        'portada' => 800,
        default => 40,
    };
@endphp

@if (filled($url))
    <span x-data="{ abierto: false }" @class(['block w-full' => $portada, 'inline-flex' => ! $portada]) @keydown.escape.window="abierto = false">
        <button type="button" @click="abierto = true" data-ver-imagen-stock
            class="group {{ $caja }} {{ $portada ? 'flex w-full' : 'inline-flex' }} shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white transition duration-200 hover:-translate-y-0.5 hover:border-fp-primary/50 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40 motion-reduce:transform-none"
            aria-label="Ver la foto de {{ $alt }}" aria-haspopup="dialog">
            <img src="{{ $url }}" alt="{{ $alt }}" class="h-full w-full {{ $ajuste }} transition duration-300 group-hover:scale-110 motion-reduce:transform-none" loading="lazy" width="{{ $pixeles }}" height="{{ $pixeles }}">
        </button>

        <template x-teleport="body">
            <div x-show="abierto" x-cloak
                x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                class="fp-modal-fondo fixed inset-0 z-50 flex items-center justify-center p-4"
                @click.self="abierto = false" role="dialog" aria-modal="true" aria-label="Foto de {{ $alt }}" data-modal-imagen-stock>
                <div x-show="abierto"
                    x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-90 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                    class="relative w-full max-w-[360px] overflow-hidden rounded-2xl bg-white p-3 shadow-2xl shadow-fp-sidebar/40 ring-1 ring-fp-primary/20 motion-reduce:transition-none">
                    <button type="button" @click="abierto = false" x-effect="abierto && $nextTick(() => $el.focus())"
                        class="absolute right-2 top-2 z-10 flex h-8 w-8 items-center justify-center rounded-full bg-white/90 text-fp-sidebar shadow ring-1 ring-slate-200 transition hover:rotate-90 hover:bg-fp-danger hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary motion-reduce:transform-none"
                        aria-label="Cerrar foto">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                    <img src="{{ $url }}" alt="{{ $alt }}" class="aspect-square w-full rounded-xl bg-fp-page object-contain" loading="lazy">
                    <p class="mt-2 truncate px-1 text-center text-sm font-medium text-fp-sidebar">{{ $alt }}</p>
                </div>
            </div>
        </template>
    </span>
@else
    <span x-data="{ temblando: false }"
        @click="temblando = false; $nextTick(() => temblando = true)" @animationend="temblando = false"
        :class="temblando && 'fp-temblor-activo'" data-sin-imagen-stock role="img" aria-label="Sin foto"
        {{ $attributes->class('fp-temblor '.$caja.' '.($portada ? 'flex w-full' : 'inline-flex').' shrink-0 cursor-default items-center justify-center rounded-lg border border-dashed border-slate-300 bg-fp-page text-slate-400') }}>
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.6-4.6a2 2 0 012.8 0L16 16m-2-2l1.6-1.6a2 2 0 012.8 0L20 14M14 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
    </span>
@endif
