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

    /** Detalle con su línea de tiempo. Cada quien solo ve lo suyo. */
    public function show(int $id): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $solicitud = PropietarioActual::limitar(SolicitudCambio::query())
            ->with(['pedidoDetalle', 'ventaDetalle', 'historial'])
            ->findOrFail($id);

        return response()->json(['data' => new SolicitudCambioResource($solicitud)]);
    }

    /** El personal mueve la solicitud (autorizar, rechazar, fábrica, vale, cerrar). */
    public function cambiarEstado(\Illuminate\Http\Request $request, int $id, \App\Services\Cambio\CambiarEstadoSolicitudCambioAction $accion): JsonResponse
    {
        $datos = $request->validate([
            'estado'     => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(SolicitudCambio::ETIQUETAS))],
            'nota'       => ['nullable', 'string', 'max:500'],
            'monto_vale' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $solicitud = $accion->ejecutar($id, $datos['estado'], $datos['nota'] ?? null, isset($datos['monto_vale']) ? (float) $datos['monto_vale'] : null);

        return response()->json([
            'data'    => new SolicitudCambioResource($solicitud->load(['pedidoDetalle', 'ventaDetalle', 'historial'])),
            'message' => 'Solicitud actualizada: '.mb_strtolower(SolicitudCambio::etiqueta($solicitud->estado)).'.',
        ]);
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
