<?php

namespace App\Services\Cambio;

use App\Exceptions\OperacionInvalidaException;
use App\Models\PedidoDetalle;
use App\Models\SolicitudCambio;
use App\Models\VentaDirectaDetalle;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * TG-254 (R12, E12-01) — Crea la solicitud de cambio de una línea de pedido
 * entregado o de una venta de mostrador, dentro del plazo.
 *
 * - El cliente (directo o mayorista) solo puede pedir cambio de lo suyo; lo
 *   ajeno responde 404, igual que pedidos y vales.
 * - El personal puede pedirlo por el cliente (y es la única forma para las
 *   ventas de mostrador, que la app no muestra).
 * - Queda en 'solicitada'. Autorizar, rechazar, fábrica y vale son de otra
 *   tarea. Nunca hay devolución en efectivo.
 */
class SolicitarCambioAction
{
    public function __construct(
        private PlazoCambio $plazo,
        private RegistrarAuditoriaAction $auditoria,
    ) {
    }

    /** @param array{pedido_detalle_id?: ?int, venta_detalle_id?: ?int, motivo: string} $datos */
    public function ejecutar(array $datos): SolicitudCambio
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $pedidoDetalleId = $datos['pedido_detalle_id'] ?? null;
        $ventaDetalleId = $datos['venta_detalle_id'] ?? null;

        if (($pedidoDetalleId && $ventaDetalleId) || (! $pedidoDetalleId && ! $ventaDetalleId)) {
            throw new OperacionInvalidaException('Indica la línea del pedido o la de la venta, solo una.', 422);
        }

        $motivo = trim((string) ($datos['motivo'] ?? ''));
        if ($motivo === '') {
            throw new OperacionInvalidaException('Escribe el motivo del cambio.', 422);
        }
        if (mb_strlen($motivo) > 300) {
            throw new OperacionInvalidaException('El motivo no puede pasar de 300 caracteres.', 422);
        }

        return DB::transaction(function () use ($pedidoDetalleId, $ventaDetalleId, $motivo) {
            $linea = $pedidoDetalleId ? $this->lineaDePedido((int) $pedidoDetalleId) : $this->lineaDeVenta((int) $ventaDetalleId);
            [$clienteId, $revendedorId] = $this->duenio($linea);

            $origen = $this->plazo->fechaOrigen($linea);
            if ($origen === null) {
                throw new OperacionInvalidaException(
                    $linea instanceof PedidoDetalle
                        ? 'Solo se puede pedir cambio de un pedido ya entregado.'
                        : 'Esa venta no admite cambios.',
                    422
                );
            }

            $limite = $this->plazo->fechaLimite($origen);
            if (now()->gt($limite)) {
                throw new OperacionInvalidaException(
                    'El plazo para pedir cambio terminó el '.$limite->format('d/m/Y').' ('.$this->plazo->diasSolicitud().' días desde la '
                        .($linea instanceof PedidoDetalle ? 'entrega' : 'compra').').',
                    422
                );
            }

            if ($this->plazo->solicitudAbierta($linea) !== null) {
                throw new OperacionInvalidaException('Ese producto ya tiene una solicitud de cambio en trámite.', 409);
            }

            $ahora = now();
            $solicitud = SolicitudCambio::create([
                'distribuidora_id' => Tenant::id(),
                'pedido_detalle_id' => $linea instanceof PedidoDetalle ? $linea->id : null,
                'venta_detalle_id' => $linea instanceof VentaDirectaDetalle ? $linea->id : null,
                'cliente_directo_id' => $clienteId,
                'revendedor_distribuidora_id' => $revendedorId,
                'fecha_entrega_original' => $origen,
                'fecha_solicitud' => $ahora,
                'dias_solicitud_aplicados' => $this->plazo->diasSolicitud(),
                'fecha_limite_solicitud' => $limite,
                'dias_gestion_fabrica_aplicados' => $this->plazo->diasGestion(),
                'fecha_limite_gestion_fabrica' => $ahora->copy()->addDays($this->plazo->diasGestion())->endOfDay(),
                'estado' => 'solicitada',
                'motivo' => $motivo,
            ]);

            $this->auditoria->ejecutar('cambio.solicitado', 'solicitud_cambio', (int) $solicitud->id, null, [
                'pedido_detalle_id' => $solicitud->pedido_detalle_id,
                'venta_detalle_id' => $solicitud->venta_detalle_id,
                'producto' => $linea->producto_nombre,
                'motivo' => $motivo,
                'fecha_limite_solicitud' => $limite->toDateString(),
            ]);

            return $solicitud->fresh();
        });
    }

    private function lineaDePedido(int $id): PedidoDetalle
    {
        $linea = PedidoDetalle::query()->with('pedido')->lockForUpdate()->find($id);

        // Lo ajeno (de otro cliente) responde igual que lo que no existe.
        $pedido = $linea?->pedido;
        if (! $pedido || ! PropietarioActual::limitar($pedido->newQuery())->whereKey($pedido->id)->exists()) {
            throw new OperacionInvalidaException('No se encontró ese producto.', 404);
        }

        return $linea;
    }

    private function lineaDeVenta(int $id): VentaDirectaDetalle
    {
        // Las ventas de mostrador no se ven en la app: solo el personal.
        if (! PropietarioActual::esDeLaCasa()) {
            throw new OperacionInvalidaException('No se encontró ese producto.', 404);
        }

        $linea = VentaDirectaDetalle::query()->with('ventaDirecta')->lockForUpdate()->find($id);
        if (! $linea || ! $linea->ventaDirecta) {
            throw new OperacionInvalidaException('No se encontró ese producto.', 404);
        }

        if (! $linea->ventaDirecta->cliente_directo_id) {
            throw new OperacionInvalidaException(
                'Esa venta se hizo sin cliente. Para pedir cambio, la venta tiene que estar a nombre de un cliente.',
                422
            );
        }

        return $linea;
    }

    /** @return array{0: ?int, 1: ?int} [cliente_directo_id, revendedor_distribuidora_id] */
    private function duenio(PedidoDetalle|VentaDirectaDetalle $linea): array
    {
        if ($linea instanceof VentaDirectaDetalle) {
            return [(int) $linea->ventaDirecta->cliente_directo_id, null];
        }

        return [
            $linea->pedido->cliente_directo_id ? (int) $linea->pedido->cliente_directo_id : null,
            $linea->pedido->revendedor_distribuidora_id ? (int) $linea->pedido->revendedor_distribuidora_id : null,
        ];
    }
}
