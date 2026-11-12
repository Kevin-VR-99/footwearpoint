@props([
    'linea' => null,
    'nombre' => null,
    'url' => null,
    'tamano' => 'md',
])

@php
    // Se puede pasar la línea entera o sus dos datos sueltos: en las tablas
    // a veces solo se tiene el nombre de una relación ya cargada.
    $nombre = $nombre ?? $linea?->nombre;
    $url = $url ?? $linea?->logotipo_url;

    $caja = match ($tamano) {
        'sm' => 'h-7 w-7 text-[11px]',
        'lg' => 'h-12 w-12 text-base',
        default => 'h-9 w-9 text-sm',
    };

    // Una sola letra, no dos: "Impuls" y "Cklass" son una palabra, y con dos
    // iniciales quedaría "IM", que no se lee como una marca.
    $inicial = mb_strtoupper(mb_substr(trim((string) $nombre), 0, 1)) ?: '?';
@endphp

{{--
    TG-384 A24 — El logo de la línea, o su inicial.

    Redondeado de caja y no círculo, y object-contain y no cover: esto es un
    logotipo, no la cara de alguien. Es la misma lección de A16 — recortar un
    logo al centro se come justo el nombre de la marca, que suele ir a lo
    ancho. x-ui.avatar sigue siendo el de personas, redondo y recortado,
    porque ahí sí conviene.

        <x-ui.avatar-linea :linea="$campana->linea" />
        <x-ui.avatar-linea nombre="Impuls" tamano="sm" />
--}}

<span {{ $attributes->class(['inline-flex shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-fp-primary/10 font-semibold text-fp-primary', $caja]) }}>
    @if (filled($url))
        <img src="{{ $url }}" alt="Logotipo de {{ $nombre }}" class="h-full w-full bg-white object-contain"
            loading="lazy" decoding="async">
    @else
        {{-- La inicial es decoración: el nombre de la línea va al lado en
             todas las pantallas, así que repetirlo sería ruido en el lector
             de pantalla. --}}
        <span aria-hidden="true">{{ $inicial }}</span>
    @endif
</span>
