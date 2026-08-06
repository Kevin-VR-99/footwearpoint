<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\GuardarVarianteRequest;
use App\Http\Resources\Catalogo\VarianteResource;
use App\Models\Variante;
use App\Services\Catalogo\GestionarVarianteAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VarianteController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return VarianteResource::collection(Variante::all());
    }

    public function show(Variante $variante): VarianteResource
    {
        return new VarianteResource($variante);
    }

    public function store(GuardarVarianteRequest $request, GestionarVarianteAction $accion): VarianteResource
    {
        $variante = $accion->crear($request->validated());

        return new VarianteResource($variante);
    }

    public function update(GuardarVarianteRequest $request, Variante $variante, GestionarVarianteAction $accion): VarianteResource
    {
        $variante = $accion->actualizar($variante, $request->validated());

        return new VarianteResource($variante);
    }
}
