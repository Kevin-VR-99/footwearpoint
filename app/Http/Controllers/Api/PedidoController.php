<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PedidoResource;
use App\Models\Pedido;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\Pedido\StorePedidoRequest;
use App\Services\Pedido\CrearPedidoBorradorAction;
use App\Http\Requests\Pedido\AgregarLineaPedidoRequest;
use App\Services\Pedido\AgregarLineaPedidoAction;
use App\Services\Pedido\EnviarPedidoAction;
use App\Services\Pedido\QuitarLineaPedidoAction;

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

    public function store(StorePedidoRequest $request, CrearPedidoBorradorAction $accion): JsonResponse
    {
        $pedido = $accion->ejecutar($request->validated());

        return response()->json([
            'data'    => new PedidoResource($pedido),
            'message' => 'Pedido borrador creado correctamente.',
        ], 201);
    }

    public function agregarLinea(
        int $id,
        AgregarLineaPedidoRequest $request,
        AgregarLineaPedidoAction $accion
    ): JsonResponse {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $pedido = Pedido::query()->findOrFail($id);
        $pedido = $accion->ejecutar($pedido, $request->validated());

        return response()->json([
            'data'    => new PedidoResource($pedido),
            'message' => 'Línea agregada al pedido.',
        ], 201);
    }

    public function enviar(int $id, EnviarPedidoAction $accion): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $pedido = Pedido::query()->findOrFail($id);
        $pedido = $accion->ejecutar($pedido);

        return response()->json([
            'data'    => new PedidoResource($pedido),
            'message' => 'Pedido enviado correctamente.',
        ]);
    }

    public function quitarLinea(int $pedidoId, int $lineaId, QuitarLineaPedidoAction $accion): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $pedido = Pedido::query()->findOrFail($pedidoId);
        $pedido = $accion->ejecutar($pedido, $lineaId);

        return response()->json([
            'data'    => new PedidoResource($pedido),
            'message' => 'Línea eliminada del pedido.',
        ]);
    }
}
