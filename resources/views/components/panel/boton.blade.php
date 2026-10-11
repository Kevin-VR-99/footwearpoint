@props([
    'variante' => 'primario',
    'href' => null,
    'tamano' => 'md',
])

@php
    $clases = [
        'inline-flex items-center justify-center gap-1.5 rounded-lg font-medium shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary/40 disabled:cursor-not-allowed disabled:opacity-60',
        'px-4 py-2 text-sm' => $tamano === 'md',
        'px-3 py-1.5 text-xs' => $tamano === 'sm',
        'bg-fp-primary text-white hover:bg-fp-primary/90' => $variante === 'primario',
        'border border-slate-200 bg-white text-fp-accent hover:bg-fp-page' => $variante === 'secundario',
        'bg-fp-danger text-white hover:bg-fp-danger/90' => $variante === 'peligro',
    ];
@endphp

{{-- TG-179: botón estándar del panel. --}}
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($clases) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($clases) }}>{{ $slot }}</button>
@endif
