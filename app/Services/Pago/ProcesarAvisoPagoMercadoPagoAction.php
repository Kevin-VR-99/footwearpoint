<?php

namespace App\Services\Pago;

use App\Models\Pago;
use App\Services\MercadoPago\ClienteMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\MercadoPago\TokenMercadoPagoPlataforma;
use App\Services\Suscripcion\ProcesarAvisoSuscripcionMercadoPagoAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TG-228 (G9) — Procesa un aviso de Mercado Pago de tipo "payment".
 *
 * Del aviso solo se usan el id del pago y la cuenta del vendedor (user_id),
 * y solo para saber DÓNDE preguntar. Lo que decide es lo que contesta
 * GET /v1/payments/{id} con el token de la distribuidora, y el que aplica es
 * AplicarPagoMercadoPagoAction, el mismo de la página de regreso y de
 * "verificar" (idempotente: si el pago ya se aplicó no pasa nada).
 *
 * Resultado:
 *   - definitivo = true: ya no hay nada más que hacer con este aviso
 *     (aplicado, ya aplicado, rechazado, no es nuestro, no cuadra…).
 *   - definitivo = false: el pago sigue en proceso; el siguiente aviso del
 *     mismo pago lo vuelve a intentar.
 *
 * Lanza MercadoPagoException cuando la falla es temporal (Mercado Pago no
 * responde, el token venció): quien llama responde 500 para que Mercado Pago
 * reintente.
 *
 * Pensado para G10/G11: un pago que no es de un pedido (suscripción) o una
 * referencia que no es FWP-{distribuidora}-{pago} no truenan; se marcan como
 * "sin manejador".
 *
 * TG-230 (G11): si el aviso es de la cuenta de FootwearPoint (la mensualidad
 * de una distribuidora), lo procesa ProcesarAvisoSuscripcionMercadoPagoAction.
 */
class ProcesarAvisoPagoMercadoPagoAction
{
    public const APLICADO = 'aplicado';

    public const YA_APLICADO = 'ya_aplicado';

    public const EN_PROCESO = 'en_proceso';

    public const RECHAZADO = 'rechazado';

    public const NO_APLICA = 'no_aplica';

    /** Fallas de Mercado Pago que vale la pena reintentar más tarde. */
    public const FALLAS_TEMPORALES = [
        MercadoPagoException::SIN_RESPUESTA,
        MercadoPagoException::CUENTA_DESCONECTADA,
        MercadoPagoException::NO_SE_PUDO_COBRAR,
    ];

    private const EN_PROCESO_MP = ['pending', 'in_process', 'authorized', 'in_mediation'];

    private const RECHAZADO_MP = ['rejected', 'cancelled'];

    private const DEVUELTO_MP = ['refunded', 'charged_back'];

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly TokenMercadoPagoDistribuidora $tokens,
        private readonly AplicarPagoMercadoPagoAction $aplicar,
        private readonly TokenMercadoPagoPlataforma $plataforma,
        private readonly ProcesarAvisoSuscripcionMercadoPagoAction $suscripcion,
    ) {
    }

    /**
     * @param  string  $pagoMpId  data.id del aviso (id del pago en Mercado Pago).
     * @param  string|null  $cuentaMp  user_id del aviso (cuenta del vendedor).
     * @param  int|null  $pista  ?d= que FootwearPoint pone en notification_url.
     * @return array{definitivo: bool, resultado: string, distribuidora_id: int|null, pago_id: int|null, error: string|null}
     *
     * @throws MercadoPagoException si la falla es temporal.
     */
    public function ejecutar(string $pagoMpId, ?string $cuentaMp, ?int $pista): array
    {
        // TG-230 (G11): la mensualidad se cobra con la cuenta de FootwearPoint.
        if ($cuentaMp !== null && $cuentaMp !== '' && $this->plataforma->esLaCuenta($cuentaMp)) {
            return $this->suscripcion->ejecutar($pagoMpId);
        }

        $distribuidoraId = $this->distribuidora($cuentaMp, $pista);

        if ($distribuidoraId === null) {
            return $this->sinAplicar(null, null, 'La cuenta de Mercado Pago del aviso no está vinculada a ninguna distribuidora.');
        }

        try {
            ['token' => $token] = $this->tokens->para($distribuidoraId);
        } catch (MercadoPagoException $e) {
            if (in_array($e->getMessage(), self::FALLAS_TEMPORALES, true)) {
                throw $e;
            }

            return $this->sinAplicar($distribuidoraId, null, 'La distribuidora ya no tiene Mercado Pago conectado.');
        }

        $pagoMp = $this->cliente->obtenerPagoSiExiste($token, $pagoMpId);

        if ($pagoMp === null) {
            return $this->sinAplicar($distribuidoraId, null, 'Mercado Pago no encontró el pago con la cuenta de la distribuidora.');
        }

        $referencia = is_string($pagoMp['external_reference'] ?? null) ? $pagoMp['external_reference'] : '';

        if (preg_match('/^FWP-([0-9]{1,10})-([0-9]{1,12})$/', $referencia, $partes) !== 1) {
            return $this->sinAplicar($distribuidoraId, null, 'Sin manejador: el pago no es de un pedido de FootwearPoint.');
        }

        if ((int) $partes[1] !== $distribuidoraId) {
            return $this->sinAplicar($distribuidoraId, null, 'La referencia del pago es de otra distribuidora.');
        }

        $pago = Tenant::forzar($distribuidoraId, fn () => Pago::query()
            ->whereKey((int) $partes[2])
            ->where('metodo', 'mercado_pago')
            ->first());

        if ($pago === null) {
            return $this->sinAplicar($distribuidoraId, null, 'No existe el pago de la referencia.');
        }

        if ($pago->pedido_id === null) {
            // Por ejemplo, la suscripción de G11: todavía no hay quién la aplique.
            return $this->sinAplicar($distribuidoraId, $pago->id, 'Sin manejador: el pago no es de un pedido.');
        }

        $status = is_string($pagoMp['status'] ?? null) ? $pagoMp['status'] : '';

        if ($status === 'approved') {
            return $this->aprobado($distribuidoraId, $pago, $pagoMp);
        }

        if (in_array($status, self::RECHAZADO_MP, true)) {
            return $this->resultado(true, self::RECHAZADO, $distribuidoraId, $pago->id, null);
        }

        if (in_array($status, self::DEVUELTO_MP, true)) {
            // No se revierte solo (fuera de G9): el personal lo revisa.
            Log::warning('Mercado Pago: un aviso reporta un pago devuelto; no se revierte solo.', [
                'pago_id' => $pago->id,
                'status'  => $status,
            ]);

            return $this->sinAplicar($distribuidoraId, $pago->id, "Mercado Pago reporta el pago como {$status}; revísalo a mano.");
        }

        // pending, in_process u otro estado que todavía puede cambiar.
        return $this->resultado(false, self::EN_PROCESO, $distribuidoraId, $pago->id, null);
    }

    private function aprobado(int $distribuidoraId, Pago $pago, array $pagoMp): array
    {
        $motivo = $this->aplicar->motivoNoCuadra($pago, $pagoMp);

        if ($motivo !== null) {
            Log::warning('Mercado Pago: el pago del aviso no cuadra; no se aplicó.', [
                'pago_id' => $pago->id,
                'motivo'  => $motivo,
            ]);

            return $this->sinAplicar($distribuidoraId, $pago->id, "El pago no cuadra ({$motivo}).");
        }

        $aplicado = $this->aplicar->ejecutar($pago, $pagoMp);

        if ($aplicado === AplicarPagoMercadoPagoAction::APLICADO) {
            Log::info('Mercado Pago: pago confirmado y aplicado.', [
                'pago_id' => $pago->id,
                'origen'  => 'webhook',
                'metodo'  => 'aviso',
            ]);

            return $this->resultado(true, self::APLICADO, $distribuidoraId, $pago->id, null);
        }

        if ($aplicado === AplicarPagoMercadoPagoAction::YA_APLICADO) {
            return $this->resultado(true, self::YA_APLICADO, $distribuidoraId, $pago->id, null);
        }

        return $this->sinAplicar($distribuidoraId, $pago->id, 'El pago ya no admite aplicarse.');
    }

    /**
     * La distribuidora dueña de la cuenta del aviso. Es una búsqueda entre
     * distribuidoras, así que se hace con DB::table y solo se lee el id (el
     * mismo patrón de VincularMercadoPagoService), sin withoutGlobalScopes.
     */
    private function distribuidora(?string $cuentaMp, ?int $pista): ?int
    {
        $conectadas = fn () => DB::table('configuraciones_distribuidora')->whereNotNull('mp_conectado_at');

        if ($cuentaMp !== null && $cuentaMp !== '') {
            $id = $conectadas()->where('mercado_pago_account_id', $cuentaMp)->value('distribuidora_id');

            return $id !== null ? (int) $id : null;
        }

        // El formato viejo (IPN) no trae user_id: se usa la pista de la URL,
        // que solo dice dónde preguntar (luego se revisa todo con la API).
        if ($pista !== null && $conectadas()->where('distribuidora_id', $pista)->exists()) {
            return $pista;
        }

        return null;
    }

    private function sinAplicar(?int $distribuidoraId, ?int $pagoId, string $error): array
    {
        return $this->resultado(true, self::NO_APLICA, $distribuidoraId, $pagoId, $error);
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
