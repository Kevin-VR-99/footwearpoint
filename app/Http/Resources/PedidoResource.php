<?php

namespace App\Http\Resources;

use App\Services\Pago\CrearPagoPedidoMercadoPagoAction;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $resumen = app(RegistrarPagoPedidoAction::class)->resumen($this->resource);

        return [
            'id'        => $this->id,
            'folio'     => $this->folio,
            'tipo'      => $this->tipo,
            'estado'    => $this->estado,
            'subtotal'  => (float) $this->subtotal,
            'total'     => (float) $this->total,
            'pagado'    => $resumen['pagado'],
            'pagado_con_vales' => $resumen['pagado_con_vales'],
            'saldo'     => $resumen['saldo'],
            'anticipo_requerido' => $resumen['anticipo_requerido'],
            'anticipo_pagado'    => $resumen['anticipo_pagado'],
            'anticipo_pendiente' => $resumen['anticipo_pendiente'],
            'sucursal_id' => $this->sucursal_id,
            'propietario' => [
                'tipo' => $this->tipo,
                'id'   => $this->cliente_directo_id ?? $this->revendedor_distribuidora_id,
                'nombre' => $this->clienteDirecto?->nombre
                    ?? $this->revendedorAfiliacion?->revendedor?->nombre
                    ?? null,
            ],
            'ciclo_compra_id' => $this->ciclo_compra_id,
            'fecha_colocacion' => optional($this->fecha_colocacion)->toIso8601String(),
            'observaciones'    => $this->observaciones,
            'capturado_por_staff_id' => $this->capturado_por_staff_id,
            'lineas' => $this->whenLoaded('detalle', function () {
                return $this->detalle->map(fn ($l) => [
                    'id'               => $l->id,
                    'producto_nombre'  => $l->producto_nombre,
                    'modelo'           => $l->modelo,
                    'talla'            => $l->talla,
                    'color'            => $l->color,
                    'cantidad'         => $l->cantidad,
                    'precio_unitario'  => (float) $l->precio_unitario,
                    'subtotal'         => (float) $l->subtotal,
                    'anticipo_requerido' => (float) $l->anticipo_requerido,
                    'estado_surtido'   => $l->estado_surtido,
                    'variante_id'      => $l->variante_id,
                    'producto_campana_id' => $l->producto_campana_id,
                ]);
            }),
            'pagos' => $this->whenLoaded('pagos', function () {
                return $this->pagos->map(fn ($p) => [
                    'id'         => $p->id,
                    'folio'      => $p->folio,
                    'tipo'       => $p->tipo,
                    'metodo'     => $p->metodo,
                    'monto'      => (float) $p->monto,
                    'estado'     => $p->estado,
                    'referencia' => $p->referencia,
                    // TG-226: 'mercado_pago' y el id del pago en Mercado Pago
                    // cuando ya se confirmó. Un pago 'pendiente' NO cuenta
                    // en pagado/saldo/anticipo hasta que queda 'aplicado'.
                    'proveedor_pago'     => $p->proveedor_pago,
                    'referencia_externa' => $p->referencia_externa,
                    'fecha_pago' => optional($p->fecha_pago)->toIso8601String(),
                ]);
            }),
            // TG-226: hay un pago con Mercado Pago esperando confirmación
            // (la app muestra "Verificar pago").
            'pago_mercado_pago_pendiente' => $this->whenLoaded('pagos', fn () => $this->pagos
                ->contains(fn ($p) => $p->metodo === 'mercado_pago' && $p->estado === 'pendiente')),
            // TG-227 (G8): de qué es ese pago pendiente ('anticipo' o
            // 'saldo_pedido'; null si no hay), para saber qué verificar.
            'pago_mercado_pago_pendiente_tipo' => $this->whenLoaded('pagos', fn () => $this->pagos
                ->filter(fn ($p) => $p->metodo === 'mercado_pago' && $p->estado === 'pendiente')
                ->sortByDesc('id')
                ->first()?->tipo),
            // TG-227 (G8): el dueño ya puede pagar el saldo con Mercado Pago
            // (cliente directo, anticipo cubierto, saldo > 0 y el pedido ya
            // llegó a la distribuidora). No revisa si la distribuidora tiene
            // Mercado Pago conectado: eso lo dice el error al intentarlo.
            'puede_pagar_saldo_mercado_pago' => CrearPagoPedidoMercadoPagoAction::puedePagarSaldo($this->resource, $resumen),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}