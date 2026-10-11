<?php

namespace App\Services\Suscripcion;

use App\Models\Pago;
use App\Services\MercadoPago\ClienteMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\MercadoPago\TokenMercadoPagoPlataforma;
use App\Services\Pago\ProcesarAvisoPagoMercadoPagoAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * TG-230 (G11) — Procesa un aviso "payment" de la cuenta de Mercado Pago de
 * FootwearPoint: el pago de una mensualidad. Lo llama
 * ProcesarAvisoPagoMercadoPagoAction cuando el user_id del aviso es la
 * cuenta de FootwearPoint, y devuelve el mismo formato de resultado.
 *
 * Del aviso solo se usa el id del pago; lo que decide es
 * GET /v1/payments/{id} con el token de FootwearPoint y
 * AplicarPagoSuscripcionMercadoPagoAction.
 */
class ProcesarAvisoSuscripcionMercadoPagoAction
{
    private const RECHAZADO_MP = ['rejected', 'cancelled'];

    private const DEVUELTO_MP = ['refunded', 'charged_back'];

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly TokenMercadoPagoPlataforma $plataforma,
        private readonly AplicarPagoSuscripcionMercadoPagoAction $aplicar,
    ) {
    }

    /**
     * @return array{definitivo: bool, resultado: string, distribuidora_id: int|null, pago_id: int|null, error: string|null}
     *
     * @throws MercadoPagoException si la falla es temporal.
     */
    public function ejecutar(string $pagoMpId): array
    {
        try {
            ['token' => $token] = $this->plataforma->para();
        } catch (MercadoPagoException $e) {
            if ($e->getMessage() === MercadoPagoException::SIN_RESPUESTA) {
                throw $e;
            }

            return $this->sinAplicar(null, null, 'El cobro de mensualidades no está configurado (MP_ACCESS_TOKEN).');
        }

        $pagoMp = $this->cliente->obtenerPagoSiExiste($token, $pagoMpId);

        if ($pagoMp === null) {
            return $this->sinAplicar(null, null, 'Mercado Pago no encontró el pago con la cuenta de FootwearPoint.');
        }

        $referencia = is_string($pagoMp['external_reference'] ?? null) ? $pagoMp['external_reference'] : '';

        if (preg_match('/^FWP-([0-9]{1,10})-([0-9]{1,12})$/', $referencia, $partes) !== 1) {
            return $this->sinAplicar(null, null, 'Sin manejador: el pago no es de una mensualidad de FootwearPoint.');
        }

        $distribuidoraId = (int) $partes[1];

        $pago = Tenant::forzar($distribuidoraId, fn () => Pago::query()
            ->whereKey((int) $partes[2])
            ->where('metodo', 'mercado_pago')
            ->where('tipo', 'suscripcion')
            ->whereNotNull('suscripcion_id')
            ->first());

        if ($pago === null) {
            return $this->sinAplicar(null, null, 'No existe el pago de mensualidad de la referencia.');
        }

        $status = is_string($pagoMp['status'] ?? null) ? $pagoMp['status'] : '';

        if ($status === 'approved') {
            return $this->aprobado($distribuidoraId, $pago, $pagoMp);
        }

        if (in_array($status, self::RECHAZADO_MP, true)) {
            return $this->resultado(true, ProcesarAvisoPagoMercadoPagoAction::RECHAZADO, $distribuidoraId, $pago->id, null);
        }

        if (in_array($status, self::DEVUELTO_MP, true)) {
            Log::warning('Mercado Pago: un aviso reporta una mensualidad devuelta; no se revierte sola.', [
                'pago_id' => $pago->id,
                'status'  => $status,
            ]);

            return $this->sinAplicar($distribuidoraId, $pago->id, "Mercado Pago reporta el pago como {$status}; revísalo a mano.");
        }

        return $this->resultado(false, ProcesarAvisoPagoMercadoPagoAction::EN_PROCESO, $distribuidoraId, $pago->id, null);
    }

    private function aprobado(int $distribuidoraId, Pago $pago, array $pagoMp): array
    {
        $motivo = $this->aplicar->motivoNoCuadra($pago, $pagoMp);

        if ($motivo !== null) {
            Log::warning('Mercado Pago: el pago de mensualidad del aviso no cuadra; no se aplicó.', [
                'pago_id' => $pago->id,
                'motivo'  => $motivo,
            ]);

            return $this->sinAplicar($distribuidoraId, $pago->id, "El pago no cuadra ({$motivo}).");
        }

        $aplicado = $this->aplicar->ejecutar($pago, $pagoMp);

        if ($aplicado === AplicarPagoSuscripcionMercadoPagoAction::APLICADO) {
            Log::info('Mercado Pago: pago de mensualidad confirmado y aplicado.', [
                'pago_id' => $pago->id,
                'origen'  => 'webhook',
                'metodo'  => 'aviso',
            ]);

            return $this->resultado(true, ProcesarAvisoPagoMercadoPagoAction::APLICADO, $distribuidoraId, $pago->id, null);
        }

        if ($aplicado === AplicarPagoSuscripcionMercadoPagoAction::YA_APLICADO) {
            return $this->resultado(true, ProcesarAvisoPagoMercadoPagoAction::YA_APLICADO, $distribuidoraId, $pago->id, null);
        }

        return $this->sinAplicar($distribuidoraId, $pago->id, 'El pago ya no admite aplicarse.');
    }

    private function sinAplicar(?int $distribuidoraId, ?int $pagoId, string $error): array
    {
        return $this->resultado(true, ProcesarAvisoPagoMercadoPagoAction::NO_APLICA, $distribuidoraId, $pagoId, $error);
    }

    private function resultado(bool $definitivo, string $resultado, ?int $distribuidoraId, ?int $pagoId, ?string $error): array
    {
        return [
            'definitivo'       => $definitivo,
            'resultado'        => $resultado,
            'distribuidora_id' => $distribuidoraId,
            'pago_id'          => $pagoId,
            'error'            => $error,
        ];
    }
}
