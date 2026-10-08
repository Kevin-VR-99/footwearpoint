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
 *   - 401 si trae firma y no coincide con MP_WEBHOOK_SECRET;
 *   - 500 si algo falló de forma temporal (Mercado Pago reintenta);
 *   - 200 en todo lo demás, incluso si el aviso no era nuestro, para que
 *     Mercado Pago no lo repita.
 * Sin firma (formato IPN) se procesa igual: el pago siempre se consulta en
 * la API de Mercado Pago antes de aplicarlo.
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

        if ($revision === FirmaWebhookMercadoPago::INVALIDA) {
            Log::warning('Mercado Pago: aviso con firma inválida; se rechaza.', ['data_id' => $dataId]);

            return response()->json(['message' => 'La firma del aviso no es válida.'], 401);
        }

        if ($revision === FirmaWebhookMercadoPago::SIN_CLAVE) {
            Log::warning('Mercado Pago: el aviso trae firma, pero falta MP_WEBHOOK_SECRET; se procesa consultando la API.');
        }

        if ($recibir->ejecutar($query, $cuerpo, is_string($requestId) ? $requestId : null) === RecibirAvisoMercadoPagoAction::REINTENTAR) {
            return response()->json(['message' => 'No se pudo procesar el aviso; inténtalo más tarde.'], 500);
        }

        return response()->json(['ok' => true]);
    }
}
