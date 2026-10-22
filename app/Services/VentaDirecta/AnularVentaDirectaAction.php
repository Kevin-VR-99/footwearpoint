<?php

namespace App\Services\VentaDirecta;

use App\Exceptions\OperacionInvalidaException;
use App\Models\MovimientoStock;
use App\Models\Pago;
use App\Models\SolicitudCambio;
use App\Models\StockLocal;
use App\Models\Vale;
use App\Models\ValeMovimiento;
use App\Models\VentaDirecta;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Auth\PermisoModuloService;
use App\Services\Reembolso\ReembolsoMercadoPagoService;
use App\Support\ContextoOperativo;
use App\Support\FolioPago;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancelar (anular) una venta de mostrador.
 *
 * En una sola transacción:
 *   - regresa cada pieza a su existencia (movimiento 'devolucion' ligado a la línea);
 *   - por cada cobro registra la devolución del dinero con el MISMO método
 *     (pago 'reembolso_venta', salida). El cobro original no se toca: el
 *     historial queda completo y el corte de caja resta la salida;
 *   - si se pagó con vale, el saldo regresa al vale;
 *   - la venta queda 'anulada' con quién, cuándo y por qué, y en la bitácora.
 *
 * Mercado Pago: si un cobro de la venta se hizo con Mercado Pago (y se
 * conoce el id del pago en Mercado Pago), el reembolso se pide solo a su API
 * desde la cola (ReembolsoMercadoPagoService), con su estado en la pantalla
 * y reintento si falla. Sin id del pago, queda como reembolso manual.
 */
class AnularVentaDirectaAction
{
    public function __construct(
        private ContextoOperativo $contexto,
        private RegistrarAuditoriaAction $auditoria,
        private PermisoModuloService $permisos,
        private ReembolsoMercadoPagoService $reembolsosMp,
    ) {
    }

    public function ejecutar(int $ventaId, string $motivo): VentaDirecta
    {
        $this->permisos->exigir(Auth::user(), PermisoModuloService::VENTAS_CANCELAR);

        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5 || mb_strlen($motivo) > 300) {
            throw ValidationException::withMessages([
                'motivo' => ['Escribe el motivo de la cancelación (de 5 a 300 caracteres).'],
            ]);
        }

        $staffId = (int) $this->contexto->staff()->id;
        $distribuidoraId = $this->contexto->distribuidoraId();

        return DB::transaction(function () use ($ventaId, $motivo, $staffId, $distribuidoraId) {
            // Con el scope del tenant: una venta de otra distribuidora no existe.
            $venta = VentaDirecta::query()->whereKey($ventaId)->lockForUpdate()->first();

            if ($venta === null) {
                throw new OperacionInvalidaException('La venta no existe.', 404);
            }

            if ($venta->estado !== 'completada') {
                throw new OperacionInvalidaException('La venta '.$venta->folio.' ya estaba cancelada.', 409);
            }

            $venta->load('detalle');

            $conCambio = SolicitudCambio::query()
                ->whereIn('venta_detalle_id', $venta->detalle->pluck('id'))
                ->where('estado', '!=', 'rechazada')
                ->exists();

            if ($conCambio) {
                throw new OperacionInvalidaException(
                    'La venta '.$venta->folio.' tiene una solicitud de cambio en curso. Resuélvela antes de cancelar.',
                    409
                );
            }

            $nota = mb_substr('Cancelación de venta '.$venta->folio.': '.$motivo, 0, 300);

            // 1) Las piezas regresan a la existencia.
            $piezas = [];
            foreach ($venta->detalle as $linea) {
                $stock = StockLocal::query()->whereKey($linea->stock_local_id)->lockForUpdate()->first();

                if ($stock === null) {
                    throw new OperacionInvalidaException('No se encontró la existencia de '.$linea->producto_nombre.'.', 409);
                }

                $anterior = (int) $stock->cantidad_disponible;
                $posterior = $anterior + (int) $linea->cantidad;

                $stock->timestamps = false;
                $stock->cantidad_disponible = $posterior;
                $stock->updated_at = now();
                $stock->save();

                $movimiento = new MovimientoStock();
                $movimiento->timestamps = false;
                $movimiento->forceFill([
                    'distribuidora_id'        => $distribuidoraId,
                    'stock_local_id'          => $stock->id,
                    'tipo'                    => 'devolucion',
                    'cantidad'                => (int) $linea->cantidad,
                    'existencia_anterior'     => $anterior,
                    'existencia_posterior'    => $posterior,
                    'venta_detalle_id'        => $linea->id,
                    'registrado_por_staff_id' => $staffId,
                    'motivo'                  => $nota,
                    'created_at'              => now(),
                ])->save();

                $piezas[] = ['variante_id' => (int) $linea->variante_id, 'cantidad' => (int) $linea->cantidad];
            }

            // 2) El dinero se devuelve con el mismo método con que se cobró.
            $reembolsos = [];
            $cobros = Pago::query()
                ->where('venta_directa_id', $venta->id)
                ->where('tipo', 'venta_directa')
                ->where('direccion', 'entrada')
                ->where('estado', 'aplicado')
                ->get();

            foreach ($cobros as $cobro) {
                $reembolso = new Pago();
                $reembolso->timestamps = false;
                $reembolso->forceFill([
                    'distribuidora_id'        => $distribuidoraId,
                    'pedido_id'               => null,
                    'venta_directa_id'        => $venta->id,
                    'folio'                   => FolioPago::siguiente($distribuidoraId),
                    'tipo'                    => 'reembolso_venta',
                    'direccion'               => 'salida',
                    'metodo'                  => $cobro->metodo,
                    'monto'                   => (float) $cobro->monto,
                    'fecha_pago'              => now(),
                    'referencia'              => match (true) {
                        ReembolsoMercadoPagoService::esReembolsable($cobro) => 'Reembolso automático en Mercado Pago de '.$cobro->folio,
                        $cobro->metodo === 'mercado_pago'                    => 'Reembolso manual en Mercado Pago de '.$cobro->folio,
                        default                                              => 'Devolución de '.$cobro->folio,
                    },
                    'estado'                  => 'aplicado',
                    'registrado_por_staff_id' => $staffId,
                    'created_at'              => now(),
                ])->save();

                $reembolsos[] = ['folio' => $reembolso->folio, 'metodo' => $reembolso->metodo, 'monto' => (float) $reembolso->monto];

                // Cobrado con Mercado Pago: se pide la devolución a su API (en la cola).
                if (ReembolsoMercadoPagoService::esReembolsable($cobro)) {
                    $disponible = $this->reembolsosMp->disponible($cobro);
                    if ($disponible > 0) {
                        $this->reembolsosMp->solicitar($cobro, $disponible, $nota, $staffId, (int) $reembolso->id);
                    }
                }
            }

            // 3) Lo pagado con vale regresa al vale.
            $vales = [];
            $aplicaciones = ValeMovimiento::query()
                ->where('venta_directa_id', $venta->id)
                ->where('tipo', 'aplicacion')
                ->get();

            foreach ($aplicaciones as $aplicacion) {
                $vale = Vale::query()->whereKey($aplicacion->vale_id)->lockForUpdate()->first();
                if ($vale === null) {
                    continue;
                }

                $saldoAnterior = (float) $vale->saldo_actual;
                $saldoPosterior = round($saldoAnterior + (float) $aplicacion->monto, 2);

                $vale->saldo_actual = $saldoPosterior;
                if ($vale->estado === 'agotado') {
                    $vale->estado = $vale->fecha_vencimiento !== null && $vale->fecha_vencimiento->isPast() ? 'vencido' : 'activo';
                }
                $vale->save();

                ValeMovimiento::create([
                    'distribuidora_id'        => $distribuidoraId,
                    'vale_id'                 => $vale->id,
                    'tipo'                    => 'ajuste_positivo',
                    'monto'                   => (float) $aplicacion->monto,
                    'saldo_anterior'          => $saldoAnterior,
                    'saldo_posterior'         => $saldoPosterior,
                    'pedido_id'               => null,
                    'venta_directa_id'        => $venta->id,
                    'registrado_por_staff_id' => $staffId,
                    'observaciones'           => mb_substr($nota, 0, 300),
                ]);

                $vales[] = ['folio' => $vale->folio, 'monto' => (float) $aplicacion->monto];
            }

            // 4) La venta queda cancelada.
            $venta->forceFill([
                'estado'               => 'anulada',
                'anulada_at'           => now(),
                'anulada_por_staff_id' => $staffId,
                'motivo_anulacion'     => $motivo,
            ])->save();

            $this->auditoria->ejecutar('venta_mostrador.anulada', 'venta_directa', (int) $venta->id,
                ['estado' => 'completada', 'total' => (float) $venta->total],
                [
                    'estado'     => 'anulada',
                    'folio'      => $venta->folio,
                    'motivo'     => $motivo,
                    'piezas'     => $piezas,
                    'reembolsos' => $reembolsos,
                    'vales'      => $vales,
                ],
            );

            return $venta->fresh(['detalle', 'pagos']);
        });
    }
}
