@props([
    'tipo' => 'info',
])

@php
    $clases = match ($tipo) {
        'exito' => 'border-fp-badge-success-fg/20 bg-fp-badge-success-bg text-fp-badge-success-fg',
        'error' => 'border-fp-badge-danger-fg/20 bg-fp-badge-danger-bg text-fp-badge-danger-fg',
        'aviso' => 'border-fp-badge-warning-fg/20 bg-fp-badge-warning-bg text-fp-badge-warning-fg',
        default => 'border-fp-badge-info-fg/20 bg-fp-badge-info-bg text-fp-badge-info-fg',
    };
@endphp

{{-- TG-179: aviso con los colores de insignia de la casa. --}}
<div role="{{ $tipo === 'error' ? 'alert' : 'status' }}" {{ $attributes->class(['rounded-xl border px-4 py-3 text-sm', $clases]) }}>
    {{ $slot }}
</div>
