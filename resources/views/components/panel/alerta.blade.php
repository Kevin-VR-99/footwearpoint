@props([
    'tipo' => 'info',
])

@php
    /*
     * A18 — El mismo vocabulario de estado que x-panel.insignia:
     * neutral | info | exito | aviso | peligro.
     *
     * Esta alerta ya hablaba espanol, pero decia "error" donde la insignia
     * decia "danger". Se queda "peligro" como nombre bueno y se siguen
     * aceptando "error" y los ingleses, que estan en 49 llamadas.
     */
    $tipo = ['error' => 'peligro', 'danger' => 'peligro', 'success' => 'exito', 'warning' => 'aviso'][$tipo] ?? $tipo;

    $clases = match ($tipo) {
        'exito' => 'border-fp-badge-success-fg/20 bg-fp-badge-success-bg text-fp-badge-success-fg',
        'peligro' => 'border-fp-badge-danger-fg/20 bg-fp-badge-danger-bg text-fp-badge-danger-fg',
        'aviso' => 'border-fp-badge-warning-fg/20 bg-fp-badge-warning-bg text-fp-badge-warning-fg',
        default => 'border-fp-badge-info-fg/20 bg-fp-badge-info-bg text-fp-badge-info-fg',
    };
@endphp

{{-- TG-179: aviso con los colores de insignia de la casa. --}}
<div role="{{ $tipo === 'peligro' ? 'alert' : 'status' }}" {{ $attributes->class(['fp-emerger rounded-xl border px-4 py-3 text-sm', $clases]) }}>
    {{ $slot }}
</div>
