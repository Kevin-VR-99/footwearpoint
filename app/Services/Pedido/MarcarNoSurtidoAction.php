<?php

namespace App\Services\Pedido;

use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Notificacion\NotificarNoSurtidoAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TG-241 (A12 / E8-05) — La fábrica confirmó un producto y al final no lo surtió.
 *
 * Pasa de verdad: la fábrica dice que sí, el cliente ya pagó su anticipo, y a
 * la hora de la entrega el producto no llegó. Hasta ahora el sistema no tenía
 * cómo registrarlo, y el pedido se quedaba esperando algo que nunca iba a
 * venir.
 *
 * Se marca RENGLÓN POR RENGLÓN, no el pedido completo: casi siempre falta una
 * talla o un color, no todo el pedido. El pedido entero solo pasa a
 * "no surtido" cuando ya no queda nada que entregar; si algo sí llegó, el
 * pedido sigue su curso con eso.
 *
 * Marcarlo no decide nada todavía: deja el renglón esperando que la persona
 * elija entre que se lo cambien por otro producto o que le devuelvan su
 * anticipo (ver ResolverNoSurtidoAction).
 */
class MarcarNoSurtidoAction
{
    /**
     * Antes de que la fábrica confirme no tiene sentido: todavía no hay nada
     * que surtir. Y en un pedido ya cerrado tampoco.
     */
    public const ESTADOS_QUE_LO_PERMITEN = [
        'confirmado',
        'parcialmente_disponible',
        'incluido_en_ciclo',
        'solicitado_fabrica',
        'en_transito',
        'recibido_distribuidora',
        'listo_entrega',
    ];

    public function __construct(
        private RegistrarAuditoriaAction $auditoria,
        private NotificarNoSurtidoAction $notificar,
    ) {
    }

    public function ejecutar(PedidoDetalle $detalle, string $motivo): Pedido
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo' => ['Escribe por qué no se surtió: el cliente va a preguntar.'],
            ]);
        }

        $pedido = $detalle->pedido;

        if (! in_array($pedido->estado, self::ESTADOS_QUE_LO_PERMITEN, true)) {
            throw ValidationException::withMessages([
                'pedido' => ['Este pedido no está en un momento donde se pueda marcar algo como no surtido (estado: '.$pedido->estado.').'],
            ]);
        }

        if ($detalle->estado_surtido === 'no_surtido') {
            throw ValidationException::withMessages([
                'detalle' => ['Ese renglón ya estaba marcado como no surtido.'],
            ]);
        }

        $estadoAnterior = $pedido->estado;

        DB::transaction(function () use ($detalle, $motivo, $pedido) {
            $detalle->update([
                'estado_surtido'        => 'no_surtido',
                'motivo_no_surtido'     => $motivo,
                'resolucion_no_surtido' => 'pendiente',
            ]);

            // Si ya no queda nada que entregar, el pedido completo se cierra.
            if ($this->nadaQueEntregar($pedido)) {
                $pedido->update(['estado' => 'no_surtido']);
            }
        });

        $pedido->refresh();

        $this->auditoria->ejecutar(
            accion: 'pedido.no_surtido',
            entidadTipo: 'pedido_detalle',
            entidadId: $detalle->id,
            datosPrevios: ['estado_pedido' => $estadoAnterior],
            datosNuevos: [
                'pedido_id'     => $pedido->id,
                'producto'      => $detalle->producto_nombre,
                'motivo'        => $motivo,
                'estado_pedido' => $pedido->estado,
            ],
        );

        $this->notificar->ejecutar($detalle->fresh(), $pedido);

        return $pedido;
    }

    /** True si todos los renglones del pedido quedaron sin surtir. */
    private function nadaQueEntregar(Pedido $pedido): bool
    {
        return ! $pedido->detalle()
            ->where('estado_surtido', '!=', 'no_surtido')
            ->exists();
    }
}
