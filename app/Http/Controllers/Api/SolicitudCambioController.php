<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cambio\SolicitarCambioRequest;
use App\Http\Resources\SolicitudCambioResource;
use App\Models\SolicitudCambio;
use App\Services\Cambio\SolicitarCambioAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;

/** TG-254 (R12, E12-01) — Solicitudes de cambio de producto. */
class SolicitudCambioController extends Controller
{
    public function index(): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        // Cada cliente solo ve las suyas; el personal, las de su distribuidora.
        $solicitudes = PropietarioActual::limitar(SolicitudCambio::query())
            ->with(['pedidoDetalle', 'ventaDetalle'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => SolicitudCambioResource::collection($solicitudes)]);
    }

    public function store(SolicitarCambioRequest $request, SolicitarCambioAction $accion): JsonResponse
    {
        $solicitud = $accion->ejecutar($request->validated());

        return response()->json([
            'data' => new SolicitudCambioResource($solicitud->load(['pedidoDetalle', 'ventaDetalle'])),
            'message' => 'Solicitud de cambio enviada. Si se autoriza, recibirás un vale; no hay devoluciones en efectivo.',
        ], 201);
    }
}
