@props([
    'variante' => 'primario',
    'href' => null,
    'tamano' => 'md',
])

@php
    $variante = in_array($variante, ['primario', 'secundario', 'contorno', 'peligro', 'oscuro', 'fantasma'], true) ? $variante : 'primario';
    $clases = [
        'fp-btn',
        'fp-btn-sm' => $tamano === 'sm',
        'fp-btn-'.$variante,
    ];
@endphp

{{-- TG-179: botón estándar del panel. Variantes: primario, secundario, contorno, peligro, oscuro, fantasma (clases .fp-btn en app.css). --}}
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($clases) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($clases) }}>{{ $slot }}</button>
@endif
