<<<<<<< HEAD
@props(['estado'])

{{--
    Insignia de estado COMPARTIDA por todos los paquetes (seccion 1.4:
    "nadie vuelve a escribir su propia insignia de estado").

    Un solo significado por color, segun la seccion 4 del archivo de
    correcciones de mockups:
      gris  = borrador / descartado
      azul  = toda la familia "en proceso"
      verde = resuelto positivamente (entregado)
      rojo  = requiere atencion
      ambar = en transito

    Contraste: se usan pares 100/800 de Tailwind, muy por encima del
    minimo de 4.5:1. Las tres insignias que median 4.09, 4.43 y 4.06:1
    en los mockups quedan corregidas de raiz.

    Uso:
      <x-ui.insignia-estado :estado="$pedido->estado" />
      <x-ui.insignia-estado :estado="$ciclo->estado" />
--}}

@php
    $mapa = [
        // --- Estados de pedido (pedidos.estado) ---
        'borrador' => ['gris', 'Borrador'],
        'descartado' => ['gris', 'Descartado'],

        'colocado' => ['azul', 'Colocado'],
        'en_revision' => ['azul', 'En revision'],
        'confirmado' => ['azul', 'Confirmado'],
        'parcialmente_disponible' => ['azul', 'Parcialmente disponible'],
        'incluido_en_ciclo' => ['azul', 'Incluido en ciclo'],
        'solicitado_fabrica' => ['azul', 'Solicitado a fabrica'],
        'recibido_distribuidora' => ['azul', 'Recibido'],
        'listo_entrega' => ['azul', 'Listo para entrega'],

        'entregado' => ['verde', 'Entregado'],

        'rechazado' => ['rojo', 'Rechazado'],
        'no_surtido' => ['rojo', 'No surtido'],
        'vencido_recoleccion' => ['rojo', 'Vencido sin recoger'],

        // --- Estados de ciclo de compra (ciclos_compra.estado) ---
        // El documento de correcciones solo mapea estados de pedido;
        // estos se asignaron por analogia con la misma logica de familia.
        'abierto' => ['azul', 'Abierto'],
        'cerrado' => ['gris', 'Cerrado'],
        'solicitado' => ['azul', 'Solicitado'],
        'recibido' => ['azul', 'Recibido'],
        'finalizado' => ['verde', 'Finalizado'],

        // --- Compartido por pedidos y ciclos ---
        'en_transito' => ['ambar', 'En transito'],

        // --- Estados de venta directa (ventas_directas.estado) ---
        'completada' => ['verde', 'Completada'],
        'anulada' => ['rojo', 'Anulada'],
    ];

    $colores = [
        'gris'  => 'bg-slate-100 text-slate-800 ring-slate-200',
        'azul'  => 'bg-blue-100 text-blue-800 ring-blue-200',
        'verde' => 'bg-green-100 text-green-800 ring-green-200',
        'ambar' => 'bg-amber-100 text-amber-800 ring-amber-200',
        'rojo'  => 'bg-red-100 text-red-800 ring-red-200',
    ];

    // Un estado desconocido se pinta en gris y muestra su valor crudo,
    // para que se note en pantalla en vez de fallar en silencio.
    [$familia, $texto] = $mapa[$estado] ?? ['gris', $estado];
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset '
        . ($colores[$familia] ?? $colores['gris']),
=======
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
>>>>>>> b8f09480e0a80b7bb2d6e7e2142b3a416760bde5
]) }}>
    {{ $texto }}
</span>