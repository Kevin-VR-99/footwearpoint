{{-- Foto de perfil redonda; sin foto muestra las iniciales con la paleta fp. --}}
@props([
    'url' => null,
    'nombre' => null,
    'tamano' => 'h-9 w-9 text-xs',
])

@php
    $iniciales = collect(preg_split('/\s+/u', trim((string) $nombre)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn ($parte) => mb_strtoupper(mb_substr($parte, 0, 1)))
        ->implode('') ?: '?';
@endphp

<span {{ $attributes->class(['fp-avatar inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-fp-primary/10 font-semibold text-fp-primary ring-2 ring-white', $tamano]) }}>
    @if (filled($url))
        <img src="{{ $url }}" alt="Foto de {{ $nombre }}" class="h-full w-full object-cover" loading="lazy" decoding="async">
    @else
        <span aria-hidden="true">{{ $iniciales }}</span>
        <span class="sr-only">{{ $nombre }}, sin foto</span>
    @endif
</span>
