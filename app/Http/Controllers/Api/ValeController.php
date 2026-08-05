<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vale\StoreValeRequest;
use App\Http\Resources\ValeResource;
use App\Models\Vale;
use App\Services\Vale\EmitirValeAction;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ValeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $query = Vale::query()->with(['clienteDirecto', 'revendedorAfiliacion.revendedor']);

        if ($request->filled('propietario_tipo') && $request->filled('propietario_id')) {
            if ($request->propietario_tipo === 'cliente_directo') {
                $query->where('cliente_directo_id', $request->propietario_id);
            } elseif ($request->propietario_tipo === 'revendedor') {
                $query->where('revendedor_distribuidora_id', $request->propietario_id);
            }
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $vales = $query->orderByDesc('id')->get();

        return response()->json([
            'data' => ValeResource::collection($vales),
        ]);
    }

    public function store(StoreValeRequest $request, EmitirValeAction $accion): JsonResponse
    {
        $vale = $accion->ejecutar($request->validated());

        return response()->json([
            'data'    => new ValeResource($vale),
            'message' => 'Vale emitido correctamente.',
        ], 201);
    }
}