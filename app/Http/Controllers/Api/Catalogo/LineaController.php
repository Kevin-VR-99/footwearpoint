<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\GuardarLineaRequest;
use App\Http\Resources\Catalogo\LineaResource;
use App\Models\Linea;
use App\Services\Catalogo\GestionarLineaAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LineaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return LineaResource::collection(
            Linea::with(['campana', 'marcas'])->latest()->get()
        );
    }

    public function show(Linea $linea): LineaResource
    {
        return new LineaResource($linea->load(['campana', 'marcas']));
    }

    public function store(GuardarLineaRequest $request, GestionarLineaAction $accion): LineaResource
    {
        $linea = $accion->crear(
            $request->safe()->except('marca_ids'),
            $request->input('marca_ids', [])
        );

        return new LineaResource($linea);
    }

    public function update(GuardarLineaRequest $request, Linea $linea, GestionarLineaAction $accion): LineaResource
    {
        $marcaIds = $request->has('marca_ids') ? $request->input('marca_ids', []) : null;

        $linea = $accion->actualizar(
            $linea,
            $request->safe()->except(['marca_ids', 'campana_id']),
            $marcaIds
        );

        return new LineaResource($linea);
    }
}