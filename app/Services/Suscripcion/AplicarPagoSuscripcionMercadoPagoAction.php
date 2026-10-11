<?php

namespace App\Services\Suscripcion;

use App\Models\Pago;
use App\Models\Suscripcion;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Distribuidora\CupoLineasDistribuidora;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\MercadoPago\TokenMercadoPagoPlataforma;
use App\Services\Notificacion\NotificarPagoSuscripcionAction;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * TG-230 (G11) — Aplica un pago de mensualidad que Mercado Pago confirmó y
 * renueva la suscripción. Lo usan el aviso de G9, el regreso a la pestaña
 * Suscripción y "Verificar pago". Es idempotente: si el pago ya se aplicó no
 * pasa nada.
 *
 * Antes de aplicar se revisa con lo que contestó la API de Mercado Pago (con
 * el token de FootwearPoint) que sea ESTE pago: external_reference, moneda,
 * monto y que el dinero haya llegado a la cuenta de FootwearPoint
 * (collector_id obligatorio aquí).
 *
 * Al aplicar, la suscripción queda activa un mes más (desde su fecha de fin,
 * o desde hoy si ya venció) y las líneas extra se vuelven a contar con lo que
 * tiene activo hoy (CobroSuscripcion::recalcularExtrasParaRenovar, para esta
 * suscripción). Si mientras tanto la suscripción se canceló (el admin general
 * asignó otro plan), el pago se aplica pero no renueva nada y queda marcado
 * en la auditoría para revisarlo.
 */
class AplicarPagoSuscripcionMercadoPagoAction
{
    public const APLICADO = 'aplicado';

    public const YA_APLICADO = 'ya_aplicado';

    public const NO_APLICA = 'no_aplica';

    public function __construct(
        private readonly TokenMercadoPagoPlataforma $plataforma,
        private readonly RegistrarAuditoriaAction $auditoria,
        private readonly NotificarPagoSuscripcionAction $notificar,
    ) {
    }

    /**
     * Por qué el pago de Mercado Pago NO corresponde a este pago de
     * mensualidad, o null si sí. No revisa el status.
     *
     * @throws MercadoPagoException si no se puede saber la cuenta de FootwearPoint.
     */
    public function motivoNoCuadra(Pago $pago, array $pagoMp): ?string
    {
        $cuenta = $this->plataforma->para()['cuenta'];

        return match (true) {
            ! isset($pagoMp['id']) || ! is_scalar($pagoMp['id']) => 'sin id',
            $pago->metodo !== 'mercado_pago' || $pago->tipo !== 'suscripcion' || $pago->suscripcion_id === null => 'el pago no es de una mensualidad',
            ($pagoMp['external_reference'] ?? null) !== $pago->referenciaMercadoPago() => 'otra external_reference',
            ($pagoMp['currency_id'] ?? null) !== CrearPagoSuscripcionMercadoPagoAction::MONEDA => 'otra moneda',
            abs((float) ($pagoMp['transaction_amount'] ?? 0) - (float) $pago->monto) > 0.009 => 'otro monto',
            ! isset($pagoMp['collector_id']) || (string) $pagoMp['collector_id'] !== $cuenta => 'otra cuenta',
            default => null,
        };
    }

    /**
     * @return string APLICADO, YA_APLICADO o NO_APLICA.
     *
     * @throws MercadoPagoException si no se puede saber la cuenta de FootwearPoint.
     */
    public function ejecutar(Pago $pago, array $pagoMp): string
    {
        if (($pagoMp['status'] ?? null) !== 'approved') {
            return self::NO_APLICA;
        }

        $problema = $this->motivoNoCuadra($pago, $pagoMp);

        if ($problema !== null) {
            report(new RuntimeException(
                "Pago de Mercado Pago aprobado que no cuadra con la mensualidad {$pago->id} ({$problema}); no se aplicó."
            ));

            return self::NO_APLICA;
        }

        [$estado, $aplicado, $suscripcion, $renovada] = Tenant::forzar(
            (int) $pago->distribuidora_id,
            fn () => DB::transaction(fn () => $this->aplicar($pago, $pagoMp))
        );

        if ($estado === self::APLICADO) {
            // El pago ya quedó; si el aviso falla, solo se reporta.
            try {
                $this->notificar->ejecutar($aplicado, $suscripcion, $renovada);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $estado;
    }

    /** @return array{0: string, 1: ?Pago, 2: ?Suscripcion, 3: bool} */
    private function aplicar(Pago $pago, array $pagoMp): array
    {
        $actual = Pago::query()->whereKey($pago->id)->lockForUpdate()->first();

        if ($actual === null || $actual->estado === 'aplicado') {
            return [self::YA_APLICADO, null, null, false];
        }

        if ($actual->estado === 'revertido') {
            return [self::NO_APLICA, null, null, false];
        }

        $suscripcion = Suscripcion::query()->whereKey($actual->suscripcion_id)->lockForUpdate()->firstOrFail();
        $estadoAnterior = $actual->estado;

        $actual->update([
            'estado'             => 'aplicado',
            'proveedor_pago'     => 'mercado_pago',
            'referencia_externa' => (string) $pagoMp['id'],
            'fecha_pago'         => $this->fechaAprobacion($pagoMp),
        ]);

        $antes = [
            'estado'                   => $suscripcion->estado,
            'fecha_fin'                => $suscripcion->fecha_fin?->toDateString(),
            'lineas_extra_contratadas' => (int) $suscripcion->lineas_extra_contratadas,
        ];

        $renovada = in_array($suscripcion->estado, CrearPagoSuscripcionMercadoPagoAction::ESTADOS_PAGABLES, true);

        if ($renovada) {
            ['hasta' => $hasta] = CrearPagoSuscripcionMercadoPagoAction::periodoAlPagar($suscripcion);

            $suscripcion->update([
                'estado'                   => 'activa',
                'fecha_fin'                => $hasta->toDateString(),
                'lineas_extra_contratadas' => max(0, app(CupoLineasDistribuidora::class)->activas() - (int) $suscripcion->lineas_incluidas_contratadas),
            ]);
        }

        $this->auditoria->ejecutar('pago.mercado_pago.aplicado', 'pago', $actual->id, ['estado' => $estadoAnterior], [
            'estado'            => 'aplicado',
            'suscripcion_id'    => $suscripcion->id,
            'folio'             => $actual->folio,
            'tipo'              => 'suscripcion',
            'monto'             => (float) $actual->monto,
            'pago_mercado_pago' => (string) $pagoMp['id'],
            // El dinero llegó, pero la suscripción ya no estaba para renovarse.
            'requiere_revision' => ! $renovada,
        ]);

        if ($renovada) {
            $suscripcion->refresh();

            $this->auditoria->ejecutar('suscripcion.renovada', 'suscripcion', $suscripcion->id, $antes, [
                'estado'                   => $suscripcion->estado,
                'fecha_fin'                => $suscripcion->fecha_fin?->toDateString(),
                'lineas_extra_contratadas' => (int) $suscripcion->lineas_extra_contratadas,
                'pago_id'                  => $actual->id,
            ]);
        }

        return [self::APLICADO, $actual->fresh(), $suscripcion, $renovada];
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
