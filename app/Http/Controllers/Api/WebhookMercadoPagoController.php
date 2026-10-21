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
 * Auditoría 21-oct-2026: si MP_WEBHOOK_SECRET está configurada, la firma es
 * obligatoria (sin firma, inválida o con ts de más de 5 minutos → 401). Si no
 * está configurada, se conserva el comportamiento anterior: se acepta y el pago
 * se verifica en la API de Mercado Pago, con un aviso en el log. En ambos casos
 * se descartan avisos repetidos por data.id.
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

        if (! $hayClave) {
            // Sin MP_WEBHOOK_SECRET no se puede verificar la firma: se procesa
            // como antes, consultando el pago en la API de Mercado Pago (que es
            // la fuente de verdad). Nunca se rechaza, para no romper cobros.
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
}
