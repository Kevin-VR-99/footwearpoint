<?php

namespace App\Services\Pago;

use App\Models\WebhookMercadoPago;
use App\Services\MercadoPago\MercadoPagoException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TG-228 (G9) — Recibe un aviso de Mercado Pago: lo guarda en
 * webhooks_mercado_pago (sin repetir) y, si es de un pago, lo procesa en ese
 * momento (Railway no tiene worker de colas; consultar un pago tarda menos
 * de un segundo y Mercado Pago espera hasta 22).
 *
 * Acepta los dos formatos:
 *   - Webhook: ?data.id=123&type=payment y un cuerpo con type, data.id y
 *     user_id (la cuenta del vendedor).
 *   - IPN (el viejo): ?topic=payment&id=123, sin user_id.
 * Los dos quedan como tipo "payment" con el id del pago, así que el mismo
 * pago avisado por los dos caminos cae en la misma fila.
 *
 * procesado_at solo se llena cuando el resultado es definitivo. Si el pago
 * sigue en proceso o algo falló de forma temporal, queda nulo y el siguiente
 * aviso (o el reintento de Mercado Pago) lo vuelve a intentar.
 *
 * Otros tipos (merchant_order, suscripciones, mp-connect…) se guardan y se
 * marcan "sin manejador": responden bien para que Mercado Pago no reintente.
 */
class RecibirAvisoMercadoPagoAction
{
    /** Ya no hay nada que hacer (procesado, repetido o ignorado): 200. */
    public const LISTO = 'listo';

    /** Falla temporal: responder 500 para que Mercado Pago reintente. */
    public const REINTENTAR = 'reintentar';

    public function __construct(private readonly ProcesarAvisoPagoMercadoPagoAction $procesar)
    {
    }

    /**
     * @param  array  $query  Parámetros de la URL (PHP convierte "data.id" en "data_id").
     * @param  array  $cuerpo  Cuerpo JSON del aviso.
     */
    public function ejecutar(array $query, array $cuerpo, ?string $requestId): string
    {
        ['tipo' => $tipo, 'recurso_id' => $recursoId, 'cuenta' => $cuenta, 'pista' => $pista] = self::normalizar($query, $cuerpo);

        if ($tipo === null || $recursoId === null) {
            Log::info('Mercado Pago: llegó un aviso sin tipo o sin id; se ignora.');

            return self::LISTO;
        }

        $aviso = WebhookMercadoPago::query()->createOrFirst(
            ['tipo' => $tipo, 'recurso_id' => $recursoId],
            ['payload' => ['query' => $query, 'cuerpo' => $cuerpo, 'request_id' => $requestId]],
        );

        // El mismo aviso otra vez (Mercado Pago repite): ya quedó.
        if ($aviso->procesado_at !== null) {
            return self::LISTO;
        }

        if ($tipo !== 'payment') {
            $aviso->update(['procesado_at' => now(), 'error' => 'Sin manejador para este tipo de aviso.']);

            return self::LISTO;
        }

        try {
            $resultado = $this->procesar->ejecutar($recursoId, $cuenta, $pista);
        } catch (MercadoPagoException $e) {
            $aviso->update(['error' => $e->getMessage()]);
            Log::warning('Mercado Pago: no se pudo procesar el aviso; Mercado Pago lo reintentará.', [
                'aviso_id' => $aviso->id,
                'mensaje'  => $e->getMessage(),
            ]);

            return self::REINTENTAR;
        } catch (Throwable $e) {
            report($e);
            $aviso->update(['error' => 'Error interno al procesar el aviso.']);

            return self::REINTENTAR;
        }

        $aviso->update([
            'distribuidora_id' => $resultado['distribuidora_id'],
            'procesado_at'     => $resultado['definitivo'] ? now() : null,
            'error'            => $resultado['error'],
        ]);

        Log::info('Mercado Pago: aviso procesado.', [
            'aviso_id'         => $aviso->id,
            'tipo'             => $tipo,
            'recurso_id'       => $recursoId,
            'distribuidora_id' => $resultado['distribuidora_id'],
            'pago_id'          => $resultado['pago_id'],
            'resultado'        => $resultado['resultado'],
            'definitivo'       => $resultado['definitivo'],
        ]);

        return self::LISTO;
    }

    /**
     * Tipo, id del recurso, cuenta del vendedor y la pista ?d= de la URL.
     *
     * @return array{tipo: string|null, recurso_id: string|null, cuenta: string|null, pista: int|null}
     */
    public static function normalizar(array $query, array $cuerpo): array
    {
        $texto = fn ($valor) => is_scalar($valor) && trim((string) $valor) !== '' ? trim((string) $valor) : null;

        // Webhook (type + data.id) o IPN (topic + id).
        $tipo = $texto($cuerpo['type'] ?? null) ?? $texto($query['type'] ?? null);

        if ($tipo !== null) {
            $recursoId = $texto($cuerpo['data']['id'] ?? null) ?? $texto($query['data_id'] ?? null);
        } else {
            $tipo = $texto($cuerpo['topic'] ?? null) ?? $texto($query['topic'] ?? null);
            $recurso = $texto($query['id'] ?? null) ?? $texto($cuerpo['resource'] ?? null);
            // En IPN "resource" puede ser el id o la URL del recurso.
            $recursoId = $recurso !== null ? basename((string) parse_url($recurso, PHP_URL_PATH)) : null;
        }

        $tipo = $tipo !== null ? strtolower($tipo) : null;

        if ($tipo === null || preg_match('/^[a-z0-9_.\-]{1,60}$/', $tipo) !== 1) {
            return ['tipo' => null, 'recurso_id' => null, 'cuenta' => null, 'pista' => null];
        }

        if ($recursoId !== null && preg_match('/^[A-Za-z0-9_\-]{1,190}$/', $recursoId) !== 1) {
            $recursoId = null;
        }

        // El id de un pago siempre es numérico.
        if ($tipo === 'payment' && $recursoId !== null && preg_match('/^[0-9]{1,20}$/', $recursoId) !== 1) {
            $recursoId = null;
        }

        $cuenta = $texto($cuerpo['user_id'] ?? null);
        $cuenta = $cuenta !== null && preg_match('/^[0-9]{1,20}$/', $cuenta) === 1 ? $cuenta : null;

        $pista = $texto($query['d'] ?? null);
        $pista = $pista !== null && preg_match('/^[0-9]{1,10}$/', $pista) === 1 ? (int) $pista : null;

        return ['tipo' => $tipo, 'recurso_id' => $recursoId, 'cuenta' => $cuenta, 'pista' => $pista];
    }
}
