<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\GuardarProductoRequest;
use App\Http\Resources\Catalogo\ProductoResource;
use App\Models\Producto;
use App\Services\Catalogo\GestionarProductoAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductoController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProductoResource::collection(Producto::all());
    }

    public function show(Producto $producto): ProductoResource
    {
        return new ProductoResource($producto);
    }

    public function store(GuardarProductoRequest $request, GestionarProductoAction $accion): ProductoResource
    {
        $producto = $accion->crear($request->validated());

        return new ProductoResource($producto);
    }

    public function update(GuardarProductoRequest $request, Producto $producto, GestionarProductoAction $accion): ProductoResource
    {
        $producto = $accion->actualizar($producto, $request->validated());

        return new ProductoResource($producto);
    }
}
