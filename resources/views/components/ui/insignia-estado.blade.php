@props([
    'estado' => null,
    'texto' => null,
    'variante' => null,
    'etiqueta' => null,
])

@php
    // TG-281: el mapa vive en App\Support\Estados para que los avisos que
    // le llegan al personal usen los mismos nombres que se leen aqui. Antes
    // decian "listo_entrega", con guion bajo.
    $mapaEstado = \App\Support\Estados::MAPA;

    if ($estado !== null && isset($mapaEstado[$estado])) {
        [$varianteResolvida, $textoResuelto] = $mapaEstado[$estado];
    } else {
        $varianteResolvida = $variante ?? 'neutral';
        $textoResuelto = $texto ?? $etiqueta ?? ($estado ? str_replace('_', ' ', $estado) : '—');
    }

    $clases = match ($varianteResolvida) {
        'info' => 'bg-fp-badge-info-bg text-fp-badge-info-fg',
        'success' => 'bg-fp-badge-success-bg text-fp-badge-success-fg',
        'warning' => 'bg-fp-badge-warning-bg text-fp-badge-warning-fg',
        'danger' => 'bg-fp-badge-danger-bg text-fp-badge-danger-fg',
        default => 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {$clases}"]) }}>
    {{ $textoResuelto }}
</span>