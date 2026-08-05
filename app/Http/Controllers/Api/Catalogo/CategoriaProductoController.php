<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\GuardarCategoriaProductoRequest;
use App\Http\Resources\Catalogo\CategoriaProductoResource;
use App\Models\CategoriaProducto;
use App\Services\Catalogo\GestionarCategoriaProductoAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoriaProductoController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoriaProductoResource::collection(CategoriaProducto::all());
    }

    public function show(CategoriaProducto $categoria): CategoriaProductoResource
    {
        return new CategoriaProductoResource($categoria);
    }

    public function store(
        GuardarCategoriaProductoRequest $request,
        GestionarCategoriaProductoAction $accion
    ): CategoriaProductoResource {
        $categoria = $accion->crear($request->validated());

        return new CategoriaProductoResource($categoria);
    }

    public function update(
        GuardarCategoriaProductoRequest $request,
        CategoriaProducto $categoria,
        GestionarCategoriaProductoAction $accion
    ): CategoriaProductoResource {
        $categoria = $accion->actualizar($categoria, $request->validated());

        return new CategoriaProductoResource($categoria);
    }
}
