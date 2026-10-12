<?php

namespace App\Services\Cambio;

use App\Models\ConfiguracionDistribuidora;
use App\Models\PedidoDetalle;
use App\Models\SolicitudCambio;
use App\Models\VentaDirectaDetalle;
use Illuminate\Support\Carbon;

/**
 * TG-254 (R12, E12-01) — Hasta cuándo se puede pedir el cambio de una línea.
 *
 * Reglas (decididas por el PO):
 *   - El plazo es configurable por distribuidora (dias_solicitud_cambio) y es
 *     el mismo para clientes directos y clientes mayoristas.
 *   - Se cuentan días naturales desde la entrega del pedido (pedidos.fecha_entrega)
 *     o desde la venta de mostrador (fecha_venta), y el último día cuenta
 *     completo (hasta las 23:59:59, hora de la app).
 *   - Una solicitud cubre la línea completa; solo una abierta por línea.
 */
class PlazoCambio
{
    public const DIAS_POR_DEFECTO = 12;

    public const DIAS_GESTION_POR_DEFECTO = 20;

    /** Estados en los que la solicitud ya terminó y no bloquea otra. */
    public const ESTADOS_TERMINADOS = ['rechazada', 'cerrada'];

    private ?ConfiguracionDistribuidora $config = null;

    private bool $configLeida = false;

    public function diasSolicitud(): int
    {
        return max(1, (int) ($this->configuracion()?->dias_solicitud_cambio ?? self::DIAS_POR_DEFECTO));
    }

    public function diasGestion(): int
    {
        return max(1, (int) ($this->configuracion()?->dias_gestion_devolucion ?? self::DIAS_GESTION_POR_DEFECTO));
    }

    public function fechaLimite(Carbon $desde): Carbon
    {
        return $desde->copy()->addDays($this->diasSolicitud())->endOfDay();
    }

    /** Fecha de entrega (pedido) o de venta (mostrador); null si todavía no hay. */
    public function fechaOrigen(PedidoDetalle|VentaDirectaDetalle $linea): ?Carbon
    {
        if ($linea instanceof PedidoDetalle) {
            $pedido = $linea->pedido;

            return $pedido && $pedido->estado === 'entregado' ? $pedido->fecha_entrega : null;
        }

        $venta = $linea->ventaDirecta;

        return $venta && $venta->estado === 'completada' ? $venta->fecha_venta : null;
    }

    /** La solicitud abierta de esa línea, si la hay. */
    public function solicitudAbierta(PedidoDetalle|VentaDirectaDetalle $linea): ?SolicitudCambio
    {
        $columna = $linea instanceof PedidoDetalle ? 'pedido_detalle_id' : 'venta_detalle_id';

        return SolicitudCambio::query()
            ->where($columna, $linea->id)
            ->whereNotIn('estado', self::ESTADOS_TERMINADOS)
            ->latest('id')
            ->first();
    }

    /**
     * Lo que la app y el panel necesitan para mostrar el botón.
     *
     * @return array{puede_solicitar: bool, fecha_limite: ?string, solicitud: ?array{id: int, estado: string}}
     */
    public function estado(PedidoDetalle|VentaDirectaDetalle $linea, ?Carbon $ahora = null): array
    {
        $ahora ??= now();
        $origen = $this->fechaOrigen($linea);
        $limite = $origen ? $this->fechaLimite($origen) : null;
        // Sin entrega no puede haber solicitud: se evita la consulta (las
        // listas de pedidos traen muchas líneas sin entregar).
        $abierta = $origen ? $this->solicitudAbierta($linea) : null;

        return [
            'puede_solicitar' => $limite !== null && $ahora->lte($limite) && $abierta === null,
            'fecha_limite' => $limite?->toIso8601String(),
            'solicitud' => $abierta ? ['id' => (int) $abierta->id, 'estado' => (string) $abierta->estado] : null,
        ];
    }

    private function configuracion(): ?ConfiguracionDistribuidora
    {
        if (! $this->configLeida) {
            $this->config = ConfiguracionDistribuidora::query()->first();
            $this->configLeida = true;
        }

        return $this->config;
    }
}
