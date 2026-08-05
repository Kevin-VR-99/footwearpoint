@props([
    'estado' => '',
    'etiqueta' => null,
])

@php
    $clave = \Illuminate\Support\Str::of((string) $estado)
        ->lower()
        ->replace(' ', '_')
        ->toString();

    /*
     | Mapeo único (doc §1.4 / correcciones §4):
     | gris = borrador/descartado | azul = en proceso
     | verde = entregado/activo   | ámbar/rojo = atención
     */
    $familia = match (true) {
        in_array($clave, [
            'borrador', 'descartado', 'archivada', 'archivado',
            'inactivo', 'agotado', 'finalizada', 'finalizado',
        ], true) => 'neutral',

        in_array($clave, [
            'entregado', 'entregada', 'completada', 'completado',
            'activo', 'activa', 'aprobada', 'aprobado', 'aplicado',
        ], true) => 'success',

        in_array($clave, [
            'cancelado', 'cancelada', 'rechazada', 'rechazado',
            'bloqueado', 'bloqueada', 'fallido', 'anulada', 'anulado',
            'suspendida', 'suspendido',
        ], true) => 'danger',

        in_array($clave, [
            'vencido', 'vencida', 'en_transito', 'pendiente',
            'requiere_atencion',
        ], true) => 'warning',

        in_array($clave, [
            'confirmado', 'confirmada', 'colocado', 'colocada',
            'en_proceso', 'enproceso', 'en_importacion', 'en_revision',
            'en_recoleccion', 'listo_recoleccion', 'solicitado',
            'abierto', 'recibido', 'autorizada', 'enviada_fabrica',
        ], true) => 'info',

        default => 'info',
    };

    $clases = match ($familia) {
        'neutral' => 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg',
        'success' => 'bg-fp-badge-success-bg text-fp-badge-success-fg',
        'warning' => 'bg-fp-badge-warning-bg text-fp-badge-warning-fg',
        'danger'  => 'bg-fp-badge-danger-bg text-fp-badge-danger-fg',
        default   => 'bg-fp-badge-info-bg text-fp-badge-info-fg',
    };

    $texto = $etiqueta ?? \Illuminate\Support\Str::of($clave)
        ->replace('_', ' ')
        ->title()
        ->toString();
@endphp

<span {{ $attributes->merge([
    'class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {$clases}",
]) }}>
    {{ $texto }}
</span>