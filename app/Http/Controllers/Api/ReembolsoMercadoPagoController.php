<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReembolsoMercadoPago;
use App\Services\Reembolso\ReembolsoMercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/** Estado y reintento de un reembolso automático en Mercado Pago. */
class ReembolsoMercadoPagoController extends Controller
{
    public function show(int $id): JsonResponse
    {
        // Con el scope del tenant: el de otra distribuidora es 404.
        $reembolso = ReembolsoMercadoPago::query()->findOrFail($id);

        return response()->json(['data' => $reembolso->aArreglo()]);
    }

    public function reintentar(int $id, ReembolsoMercadoPagoService $servicio): JsonResponse
    {
        $reembolso = $servicio->reintentar($id, Auth::user());

        return response()->json([
            'data'    => $reembolso->fresh()->aArreglo(),
            'message' => 'Reembolso enviado de nuevo a Mercado Pago.',
        ]);
    }
}
