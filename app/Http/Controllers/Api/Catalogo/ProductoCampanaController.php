<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\GuardarProductoCampanaRequest;
use App\Http\Resources\Catalogo\ProductoCampanaResource;
use App\Models\ProductoCampana;
use App\Services\Catalogo\GestionarProductoCampanaAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductoCampanaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProductoCampanaResource::collection(ProductoCampana::all());
    }

    public function show(ProductoCampana $productoCampana): ProductoCampanaResource
    {
        return new ProductoCampanaResource($productoCampana);
    }

    public function store(
        GuardarProductoCampanaRequest $request,
        GestionarProductoCampanaAction $accion
    ): ProductoCampanaResource {
        $productoCampana = $accion->crear($request->validated());

        return new ProductoCampanaResource($productoCampana);
    }

    public function update(
        GuardarProductoCampanaRequest $request,
        ProductoCampana $productoCampana,
        GestionarProductoCampanaAction $accion
    ): ProductoCampanaResource {
        $productoCampana = $accion->actualizar($productoCampana, $request->validated());

        return new ProductoCampanaResource($productoCampana);
    }
}
