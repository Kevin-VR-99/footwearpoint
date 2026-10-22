<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Distribuidora\BajaAccesoAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Baja y reactivación de accesos (solo admin de la distribuidora). */
class AccesoController extends Controller
{
    public function baja(Request $request, string $tipo, int $id, BajaAccesoAction $accion): JsonResponse
    {
        $request->validate(['motivo' => ['nullable', 'string', 'max:300']]);
        $registro = $accion->darDeBaja($tipo, $id, $request->input('motivo'));

        return response()->json(['data' => ['id' => $registro->id, 'estado' => $registro->estado], 'message' => 'Acceso dado de baja.']);
    }

    public function reactivar(string $tipo, int $id, BajaAccesoAction $accion): JsonResponse
    {
        $registro = $accion->reactivar($tipo, $id);

        return response()->json(['data' => ['id' => $registro->id, 'estado' => $registro->estado], 'message' => 'Acceso reactivado.']);
    }
}
