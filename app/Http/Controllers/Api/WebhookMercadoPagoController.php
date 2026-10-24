<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MercadoPago\FirmaWebhookMercadoPago;
use App\Services\Pago\RecibirAvisoMercadoPagoAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * TG-228 (G9) — POST /api/webhooks/mercado-pago: avisos de Mercado Pago.
 *
 * Pública (sin sesión; las rutas /api no llevan CSRF), con límite por IP.
 * Responde:
 *   - 401 si hay MP_WEBHOOK_SECRET y la firma falta, no coincide o venció;
 *   - 500 si algo falló de forma temporal (Mercado Pago reintenta);
 *   - 200 en todo lo demás, incluso si el aviso no era nuestro, para que
 *     Mercado Pago no lo repita.
 *
 * Seguridad oct-2026 (fail-closed): la firma siempre es obligatoria cuando hay
 * MP_WEBHOOK_SECRET (sin firma, inválida o con ts de más de 5 minutos → 401).
 * Si la variable NO está configurada, fuera de local/testing el aviso se
 * rechaza con 503 (Mercado Pago lo reintenta) y se deja un error en el log.
 * En local/testing se puede seguir procesando sin firma consultando la API
 * (MP_WEBHOOK_PERMITIR_SIN_CLAVE, true por defecto) para no romper pruebas.
 * Se descartan avisos repetidos por data.id.
 */
class WebhookMercadoPagoController extends Controller
{
    public function __invoke(Request $request, FirmaWebhookMercadoPago $firma, RecibirAvisoMercadoPagoAction $recibir): JsonResponse
    {
        $query = $request->query();
        $cuerpo = $request->json()->all();
        $requestId = $request->header('x-request-id');

        // La firma usa el data.id de la URL (PHP lo deja como data_id).
        $dataId = $request->query('data_id');
        $dataId = is_scalar($dataId) ? (string) $dataId : null;

        $revision = $firma->revisar($request->header('x-signature'), $requestId, $dataId);

        $hayClave = (string) config('services.mercadopago.webhook_secret', '') !== '';

        if (! $hayClave && ! self::permiteSinClave()) {
            // Fail-closed: sin clave no hay forma de saber que el aviso viene
            // de Mercado Pago. 503 para que lo reintente cuando se configure.
            Log::error('Mercado Pago: falta MP_WEBHOOK_SECRET; el aviso se rechaza (fail-closed). Configura la variable en el servicio.', ['data_id' => $dataId, 'entorno' => app()->environment()]);

            return response()->json(['message' => 'El webhook no está configurado.'], 503);
        }

        if (! $hayClave) {
            // Solo local/testing: se procesa consultando la API de Mercado Pago.
            Log::warning('Mercado Pago: falta MP_WEBHOOK_SECRET; el aviso se procesa sin verificar la firma, consultando la API. Configura la variable.', ['data_id' => $dataId]);
        } elseif ($revision !== FirmaWebhookMercadoPago::VALIDA) {
            $motivo = match ($revision) {
                FirmaWebhookMercadoPago::VENCIDA => 'firma vencida (ts de hace más de 5 minutos)',
                FirmaWebhookMercadoPago::SIN_FIRMA => 'aviso sin firma',
                default => 'firma inválida',
            };
            Log::warning("Mercado Pago: {$motivo}; se rechaza.", ['data_id' => $dataId]);

            return response()->json(['message' => 'La firma del aviso no es válida.'], 401);
        }

        if ($recibir->ejecutar($query, $cuerpo, is_string($requestId) ? $requestId : null) === RecibirAvisoMercadoPagoAction::REINTENTAR) {
            return response()->json(['message' => 'No se pudo procesar el aviso; inténtalo más tarde.'], 500);
        }

        return response()->json(['ok' => true]);
    }

    /** Solo en local/testing se permite procesar avisos sin MP_WEBHOOK_SECRET. */
    private static function permiteSinClave(): bool
    {
        return app()->environment(['local', 'testing'])
            && (bool) config('services.mercadopago.webhook_permitir_sin_clave', true);
    }
}
