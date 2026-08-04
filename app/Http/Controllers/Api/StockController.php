<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\IndexStockRequest;
use App\Http\Requests\Stock\StoreAjusteStockRequest;
use App\Http\Requests\Stock\StoreEntradaStockRequest;
use App\Http\Resources\MovimientoStockResource;
use App\Http\Resources\StockLocalResource;
use App\Models\StockLocal;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StockController extends Controller
{
    public function __construct(private StockService $stock)
    {
    }

    public function index(IndexStockRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', StockLocal::class);

        $existencias = $this->stock
            ->consultar($request->filled('variante_id') ? $request->integer('variante_id') : null)
            ->paginate($request->filled('por_pagina') ? $request->integer('por_pagina') : 25);

        return StockLocalResource::collection($existencias);
    }

    public function storeEntrada(StoreEntradaStockRequest $request): JsonResponse
    {
        Gate::authorize('create', StockLocal::class);

        $movimiento = $this->stock->registrarEntrada(
            $request->integer('variante_id'),
            $request->integer('cantidad'),
            $request->input('motivo'),
        );

        return (new MovimientoStockResource($movimiento))
            ->additional(['message' => 'Entrada de stock registrada.'])
            ->response()
            ->setStatusCode(201);
    }

    public function storeAjuste(StoreAjusteStockRequest $request): JsonResponse
    {
        Gate::authorize('create', StockLocal::class);

        $movimiento = $this->stock->registrarAjuste(
            $request->integer('variante_id'),
            $request->string('tipo')->toString(),
            $request->integer('cantidad'),
            $request->string('motivo')->toString(),
        );

        return (new MovimientoStockResource($movimiento))
            ->additional(['message' => 'Ajuste de stock registrado.'])
            ->response()
            ->setStatusCode(201);
    }
}