<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PedidoResource;
use App\Models\Pedido;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PedidoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $query = Pedido::query()
            ->with(['clienteDirecto', 'revendedorAfiliacion.revendedor'])
            ->orderByDesc('id');

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        $pedidos = $query->limit(100)->get();

        return response()->json([
            'data' => PedidoResource::collection($pedidos),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $pedido = Pedido::query()
            ->with(['clienteDirecto', 'revendedorAfiliacion.revendedor', 'detalle'])
            ->findOrFail($id);

        return response()->json([
            'data' => new PedidoResource($pedido),
        ]);
    }
}