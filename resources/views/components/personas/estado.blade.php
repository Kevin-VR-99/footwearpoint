{{-- Insignia de estado de una persona con la paleta fp. --}}
@props(['estado'])
@php
    $color = match ($estado) {
        'activo' => 'bg-fp-badge-success-bg text-fp-badge-success-fg',
        'suspendido' => 'bg-fp-badge-warning-bg text-fp-badge-warning-fg',
        default => 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg',
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center whitespace-nowrap px-2.5 py-1 rounded-full text-xs font-medium', $color]) }}>{{ ucfirst((string) $estado) }}</span>
