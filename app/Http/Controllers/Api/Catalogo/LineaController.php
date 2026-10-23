<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\GuardarLineaRequest;
use App\Http\Resources\Catalogo\LineaResource;
use App\Models\Linea;
use App\Services\Catalogo\GestionarLineaAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LineaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return LineaResource::collection(
            Linea::with(['campanas', 'marcas'])->latest()->get()
        );
    }

    public function show(Linea $linea): LineaResource
    {
        return new LineaResource($linea->load(['campanas', 'marcas']));
    }

    public function store(GuardarLineaRequest $request, GestionarLineaAction $accion): LineaResource
    {
        $linea = $accion->crear(
            $request->safe()->except(['marca_ids', 'logotipo', 'quitar_logotipo']),
            $request->input('marca_ids', []),
            $request->file('logotipo')
        );

        return new LineaResource($linea);
    }

    public function update(GuardarLineaRequest $request, Linea $linea, GestionarLineaAction $accion): LineaResource
    {
        $marcaIds = $request->has('marca_ids') ? $request->input('marca_ids', []) : null;

        $linea = $accion->actualizar(
            $linea,
            $request->safe()->except(['marca_ids', 'logotipo', 'quitar_logotipo']),
            $marcaIds,
            $request->file('logotipo'),
            $request->boolean('quitar_logotipo')
        );

        return new LineaResource($linea);
    }

    /** TG-247: si tiene datos que dependen de él responde 409 con el motivo. */
    public function destroy(Linea $linea, GestionarLineaAction $accion): JsonResponse
    {
        $accion->eliminar($linea);

        return response()->json(['data' => null, 'message' => 'Línea eliminada.']);
    }
}
