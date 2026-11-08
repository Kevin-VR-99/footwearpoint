<?php

namespace App\Services\Vale;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Pedido;
use App\Models\Vale;
use App\Models\ValeMovimiento;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Support\Centavos;
use Illuminate\Support\Facades\DB;

/**
 * TG-356 — Uso de vales con tipo. Todas las vías (API, app, panel, punto de
 * venta) pasan por AplicarValeAction, que delega aquí, así que ninguna puede
 * saltarse estas reglas.
 *
 * - Solo en un pedido de la distribuidora emisora (el scope de tenant ya lo
 *   limita) y del mismo dueño; nunca en Venta local.
 * - Devolución de producto y sobrante: el vale se usa COMPLETO en un solo
 *   pedido. Si el vale es mayor que el saldo del pedido, el original queda
 *   agotado y la diferencia se emite en un vale NUEVO de tipo sobrante, con
 *   90 días desde su generación (decisión del usuario 07/11 17:03).
 * - Anticipo: solo cubre anticipo pendiente (de cualquier producto del pedido).
 *   Si el vale es mayor, la diferencia va a un vale NUEVO de sobrante, que
 *   puede pagar anticipo o saldo del pedido (decisión 07/11 18:23). Desde la app el sobrante queda
 *   con el usuario como autor y sin staff (CHECK chk_vale_autor).
 * - Bono: sus reglas están pendientes; no se aplica.
 */
class AplicarValeTipificadoAction
{
    public function __construct(private CrearValeTipificado $crear, private RegistrarPagoPedidoAction $pagos) {}

    public function ejecutar(Vale $vale, array $datos): Vale
    {
        if (! empty($datos['venta_directa_id'])) {
            throw new OperacionInvalidaException('Este vale solo se usa en un pedido; no aplica en Venta local.', 422);
        }
        $pedidoId = (int) ($datos['pedido_id'] ?? 0);
        if ($pedidoId <= 0) {
            throw new OperacionInvalidaException('Indica el pedido donde se usará el vale.', 422);
        }
        if ($vale->tipo === Vale::TIPO_BONO) {
            throw new OperacionInvalidaException('Las reglas del vale por bono aún no están definidas; no se puede usar todavía.', 422);
        }

        return DB::transaction(function () use ($vale, $pedidoId, $datos) {
            $this->crear->bloquearNumeracion((int) $vale->distribuidora_id);
            $vale = Vale::query()->lockForUpdate()->findOrFail($vale->id);
            $this->validarVale($vale);

            $pedido = Pedido::query()->lockForUpdate()->find($pedidoId);
            if (! $pedido) {
                throw new OperacionInvalidaException('El pedido no existe en esta distribuidora.', 422);
            }
            $this->validarPedido($vale, $pedido);

            $resumen = $this->pagos->resumen($pedido);
            $valeC = Centavos::desdeDecimal($vale->getRawOriginal('saldo_actual'));
            $staffId = $this->crear->staffActual();
            $saldoPedido = Centavos::desdeDecimal(number_format($resumen['saldo'], 2, '.', ''));

            // Finalidad: la del propio vale. Un sobrante es de tipo propio y paga
            // anticipo o saldo del pedido aunque venga de un vale de anticipo
            // (decisión del usuario 07/11 18:23); el origen queda en vale_origen_id.
            $finalidad = (string) $vale->tipo;
            if ($finalidad === Vale::TIPO_BONO) {
                throw new OperacionInvalidaException('Las reglas del vale por bono aún no están definidas; no se puede usar todavía.', 422);
            }

            if ($finalidad === Vale::TIPO_ANTICIPO) {
                $cubrible = min(Centavos::desdeDecimal(number_format($resumen['anticipo_pendiente'], 2, '.', '')), $saldoPedido);
                if ($cubrible <= 0) {
                    throw new OperacionInvalidaException('Este pedido no tiene anticipo pendiente; el vale de anticipo solo cubre anticipos.', 422);
                }
            } else {
                $cubrible = $saldoPedido;
                if ($cubrible <= 0) {
                    throw new OperacionInvalidaException('Este pedido ya está pagado.', 422);
                }
            }

            // Uso completo: se aplica lo que el pedido admite y la diferencia va a un vale NUEVO.
            $aplicado = min($valeC, $cubrible);
            $sobrante = $valeC - $aplicado;
            $this->exigirMontoCompleto($datos, [$valeC, $aplicado, min($valeC, $saldoPedido)]);

            $etiqueta = mb_strtolower(Vale::etiquetaTipo($vale->tipo));
            $this->consumir($vale, $aplicado, $pedido->id, $staffId,
                'Aplicación completa de '.$etiqueta.($sobrante > 0 ? '; sobrante $'.Centavos::aDecimal($sobrante).' en vale nuevo' : ''));

            if ($sobrante > 0) {
                $this->crear->crear(Vale::TIPO_SOBRANTE, [
                    'distribuidora_id' => (int) $vale->distribuidora_id,
                    'cliente_directo_id' => $vale->cliente_directo_id,
                    'revendedor_distribuidora_id' => $vale->revendedor_distribuidora_id,
                ], $sobrante, $staffId, 'Sobrante del vale '.$vale->folio.' usado en el pedido '.$pedido->folio,
                    valeOrigenId: $vale->id, pedidoMovimientoId: $pedido->id);
            }

            return $vale->fresh(['clienteDirecto', 'revendedorAfiliacion.revendedor', 'movimientos']);
        });
    }

    private function validarVale(Vale $vale): void
    {
        if ($vale->estado !== 'activo' || Centavos::desdeDecimal($vale->getRawOriginal('saldo_actual')) <= 0) {
            throw new OperacionInvalidaException('El vale '.$vale->folio.' ya se usó o no está activo.', 422);
        }
        if ($vale->fecha_vencimiento !== null && $vale->fecha_vencimiento->isPast()) {
            throw new OperacionInvalidaException('El vale '.$vale->folio.' está vencido.', 422);
        }
    }

    private function validarPedido(Vale $vale, Pedido $pedido): void
    {
        $mismo = ($vale->cliente_directo_id && (int) $vale->cliente_directo_id === (int) $pedido->cliente_directo_id)
            || ($vale->revendedor_distribuidora_id && (int) $vale->revendedor_distribuidora_id === (int) $pedido->revendedor_distribuidora_id);
        if (! $mismo) {
            throw new OperacionInvalidaException('El vale no pertenece al mismo propietario que el pedido.', 422);
        }
        if ($pedido->estado === 'borrador') {
            throw new OperacionInvalidaException('Envía el pedido antes de aplicarle un vale.', 422);
        }
        if (in_array($pedido->estado, RegistrarPagoPedidoAction::ESTADOS_CERRADOS, true)) {
            throw new OperacionInvalidaException('Este pedido ya no admite pagos (estado: '.$pedido->estado.').', 422);
        }
    }

    /** El vale se usa completo: si se pide un monto, debe ser el saldo del vale o lo que se aplicará. */
    private function exigirMontoCompleto(array $datos, array $aceptados): void
    {
        if (! isset($datos['monto']) || $datos['monto'] === null || $datos['monto'] === '') {
            return;
        }
        $texto = is_string($datos['monto']) ? trim($datos['monto']) : number_format((float) $datos['monto'], 2, '.', '');
        try {
            $pedido = Centavos::desdeDecimal($texto);
        } catch (\InvalidArgumentException) {
            throw new OperacionInvalidaException('El monto no es válido.', 422);
        }
        if (! in_array($pedido, $aceptados, true)) {
            throw new OperacionInvalidaException('Este vale se usa completo; no admite un monto parcial.', 422);
        }
    }

    private function consumir(Vale $vale, int $centavos, int $pedidoId, ?int $staffId, string $nota): void
    {
        $anterior = $vale->getRawOriginal('saldo_actual');
        $vale->saldo_actual = '0.00';
        $vale->estado = 'agotado';
        $vale->save();

        ValeMovimiento::create([
            'distribuidora_id' => $vale->distribuidora_id,
            'vale_id' => $vale->id,
            'tipo' => 'aplicacion',
            'monto' => Centavos::aDecimal($centavos),
            'saldo_anterior' => $anterior,
            'saldo_posterior' => '0.00',
            'pedido_id' => $pedidoId,
            'venta_directa_id' => null,
            'registrado_por_staff_id' => $staffId,
            'observaciones' => mb_substr($nota, 0, 300),
        ]);
    }
}
