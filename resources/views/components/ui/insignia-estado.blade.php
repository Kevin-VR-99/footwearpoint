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

        // --- Estados de vale (vales.estado) ---
        'activo' => ['verde', 'Activo'],
        'agotado' => ['gris', 'Agotado'],
        'vencido' => ['ambar', 'Vencido'],
        'bloqueado' => ['rojo', 'Bloqueado'],
    ];

    $colores = [
        'gris'  => 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg',
        'azul'  => 'bg-fp-badge-info-bg text-fp-badge-info-fg',
        'verde' => 'bg-fp-badge-success-bg text-fp-badge-success-fg',
        'ambar' => 'bg-fp-badge-warning-bg text-fp-badge-warning-fg',
        'rojo'  => 'bg-fp-badge-danger-bg text-fp-badge-danger-fg',
    ];

    // Un estado desconocido se pinta en gris y muestra su valor crudo,
    // para que se note en pantalla en vez de fallar en silencio.
    [$familia, $texto] = $mapa[$estado] ?? ['gris', $estado];
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium '
        . ($colores[$familia] ?? $colores['gris']),
]) }}>
    {{ $texto }}
</span>