{{--
    Logo de una línea o marca junto a su nombre. Con la misma animación que
    las fotos de la tarjeta de la tienda; el logo va en object-contain para no
    recortarlo. Sin logo muestra las iniciales con la paleta fp.
--}}
@props([
    'url' => null,
    'nombre' => null,
    'tamano' => 'h-10 w-10',
])

@php
    $iniciales = collect(preg_split('/\s+/u', trim((string) $nombre)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn ($parte) => mb_strtoupper(mb_substr($parte, 0, 1)))
        ->implode('') ?: '?';
@endphp

<span {{ $attributes->class(['group fp-tarjeta-interactiva inline-flex shrink-0 items-center justify-center overflow-hidden', $tamano]) }} data-logotipo>
    @if (filled($url))
        <span class="flex h-full w-full items-center justify-center overflow-hidden">
            <img src="{{ $url }}" alt="Logo de {{ $nombre }}" loading="lazy" decoding="async" width="40" height="40"
                 class="h-full w-full object-contain p-0.5 transition duration-300 group-hover:-translate-y-0.5 group-hover:scale-110 motion-reduce:transform-none">
        </span>
    @else
        <span class="flex h-full w-full items-center justify-center bg-fp-primary/10 text-xs font-semibold text-fp-primary" data-logotipo-iniciales>
            <span aria-hidden="true">{{ $iniciales }}</span>
            <span class="sr-only">{{ $nombre }}, sin logo</span>
        </span>
    @endif
</span>
