<?php

namespace App\Services\Pago;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Pago;
use App\Models\Pedido;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Notificacion\NotificarPagoMercadoPagoAction;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * TG-226 (G7) — Aplica un pago que Mercado Pago confirmó.
 *
 * Es el ÚNICO lugar que pasa un pago de Mercado Pago a 'aplicado'. Lo usa la
 * verificación al regresar de pagar (G7) y lo usará el aviso/webhook (G9),
 * así que es idempotente: si el mismo pago llega dos veces, la segunda no
 * hace nada.
 *
 * Nunca confía en lo que trae la URL de regreso: $pagoMp es la respuesta de
 * la API de Mercado Pago consultada con el token de la distribuidora, y aun
 * así se revisa que sea de este pago, por este monto, en esta moneda y para
 * la cuenta de esta distribuidora.
 *
 * Si cuando llega la confirmación el anticipo ya estaba cubierto (por ejemplo,
 * pagó en mostrador mientras tanto), el dinero ya está en la cuenta de la
 * distribuidora: se aplica igual y se marca en la auditoría para que el
 * personal lo revise. Los reembolsos quedan fuera de G7.
 *
 * Todo corre dentro de Tenant::forzar con la distribuidora del pago: en G9 no
 * hay sesión, y así se usan los scopes normales.
 */
class AplicarPagoMercadoPagoAction
{
    public const APLICADO = 'aplicado';

    public const YA_APLICADO = 'ya_aplicado';

    public const NO_APLICA = 'no_aplica';

    public function __construct(
        private readonly RegistrarPagoPedidoAction $pagos,
        private readonly RegistrarAuditoriaAction $auditoria,
        private readonly NotificarPagoMercadoPagoAction $notificar,
    ) {
    }

    /**
     * @param  array  $pagoMp  Pago tal como lo regresa GET /v1/payments.
     * @return string Uno de APLICADO, YA_APLICADO o NO_APLICA.
     */
    public function ejecutar(Pago $pago, array $pagoMp): string
    {
        return Tenant::forzar((int) $pago->distribuidora_id, fn () => $this->aplicar($pago, $pagoMp));
    }

    private function aplicar(Pago $pago, array $pagoMp): string
    {
        if (($pagoMp['status'] ?? null) !== 'approved') {
            return self::NO_APLICA;
        }

        if (! $this->esDeEstePago($pago, $pagoMp)) {
            return self::NO_APLICA;
        }

        $resultado = DB::transaction(function () use ($pago, $pagoMp) {
            $actual = Pago::query()->whereKey($pago->id)->lockForUpdate()->first();

            if ($actual === null || $actual->estado === 'aplicado') {
                return [self::YA_APLICADO, null, null];
            }

            // Un pago revertido ya no se toca (eso es de G9).
            if ($actual->estado === 'revertido') {
                return [self::NO_APLICA, null, null];
            }

            $pedido = Pedido::query()->with(['detalle', 'pagos', 'aplicacionesVale'])->findOrFail($actual->pedido_id);
            $resumen = $this->pagos->resumen($pedido);
            $monto = (float) $actual->monto;

            $estadoAnterior = $actual->estado;

            $actual->update([
                'estado'             => 'aplicado',
                'proveedor_pago'     => 'mercado_pago',
                'referencia_externa' => (string) $pagoMp['id'],
                'fecha_pago'         => $this->fechaAprobacion($pagoMp),
            ]);

            $this->auditoria->ejecutar(
                'pago.mercado_pago.aplicado',
                'pago',
                $actual->id,
                ['estado' => $estadoAnterior],
                [
                    'estado'            => 'aplicado',
                    'pedido_id'         => $pedido->id,
                    'folio'             => $actual->folio,
                    'tipo'              => $actual->tipo,
                    'monto'             => $monto,
                    'pago_mercado_pago' => (string) $pagoMp['id'],
                    // Para que el personal revise: el dinero llegó, pero ya
                    // no hacía falta todo (o nada) de lo que se pagó.
                    'excede_anticipo'   => $actual->tipo === 'anticipo' && $monto - $resumen['anticipo_pendiente'] > 0.009,
                    'excede_saldo'      => $monto - $resumen['saldo'] > 0.009,
                ],
            );

            return [self::APLICADO, $actual, $pedido];
        });

        [$estado, $aplicado, $pedido] = $resultado;

        if ($estado === self::APLICADO) {
            // El pago ya quedó; si el aviso falla, solo se reporta.
            try {
                $this->notificar->ejecutar($aplicado, $pedido);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $estado;
    }

    /**
     * ¿Este pago de Mercado Pago corresponde a este pago nuestro? Si algo no
     * cuadra no se aplica y se deja constancia en el log (sin datos
     * sensibles).
     */
    private function esDeEstePago(Pago $pago, array $pagoMp): bool
    {
        $configuracion = ConfiguracionDistribuidora::query()
            ->where('distribuidora_id', $pago->distribuidora_id)
            ->first();

        $moneda = $configuracion?->moneda ?: 'MXN';
        $cuenta = $configuracion?->mercado_pago_account_id;

        $problema = match (true) {
            ! isset($pagoMp['id']) || ! is_scalar($pagoMp['id']) => 'sin id',
            $pago->metodo !== 'mercado_pago' => 'el pago no es de Mercado Pago',
            ($pagoMp['external_reference'] ?? null) !== $pago->referenciaMercadoPago() => 'otra external_reference',
            ($pagoMp['currency_id'] ?? null) !== $moneda => 'otra moneda',
            abs((float) ($pagoMp['transaction_amount'] ?? 0) - (float) $pago->monto) > 0.009 => 'otro monto',
            // El dinero tiene que haber llegado a la cuenta de esta distribuidora.
            $cuenta !== null && isset($pagoMp['collector_id']) && (string) $pagoMp['collector_id'] !== (string) $cuenta => 'otra cuenta',
            default => null,
        };

        if ($problema !== null) {
            report(new RuntimeException(
                "Pago de Mercado Pago aprobado que no cuadra con el pago {$pago->id} ({$problema}); no se aplicó."
            ));

            return false;
        }

        return true;
    }

    private function fechaAprobacion(array $pagoMp): Carbon
    {
        try {
            if (is_string($pagoMp['date_approved'] ?? null)) {
                return Carbon::parse($pagoMp['date_approved'])->setTimezone(config('app.timezone'));
            }
        } catch (Throwable) {
            // Fecha rara: se usa la de ahora.
        }

        return now();
    }
}
