@props([
    'estado' => null,
    'texto' => null,
    'variante' => null,
    'etiqueta' => null,
])

@php
    $mapaEstado = [
        // Pedidos / genericos
        'borrador' => ['neutral', 'Borrador'],
        'descartado' => ['neutral', 'Descartado'],
        'colocado' => ['info', 'Colocado'],
        'en_revision' => ['info', 'En revisión'],
        'confirmado' => ['info', 'Confirmado'],
        'incluido_en_ciclo' => ['info', 'En ciclo'],
        'solicitado_fabrica' => ['info', 'Solicitado fábrica'],
        'en_transito' => ['info', 'En tránsito'],
        'recibido_distribuidora' => ['info', 'Recibido'],
        'parcialmente_disponible' => ['warning', 'Parc. disponible'],
        'listo_entrega' => ['warning', 'Listo entrega'],
        'vencido_recoleccion' => ['warning', 'Vencido recolección'],
        'no_surtido' => ['warning', 'No surtido'],
        'entregado' => ['success', 'Entregado'],
        'rechazado' => ['danger', 'Rechazado'],
        'cancelado' => ['danger', 'Cancelado'],
        // Campañas (B)
        'en_importacion' => ['info', 'En importación'],
        'activa' => ['success', 'Activa'],
        'cerrada' => ['neutral', 'Cerrada'],
        'pausada' => ['warning', 'Pausada'],
    ];

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