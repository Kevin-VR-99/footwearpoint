<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\GuardarCampanaRequest;
use App\Http\Resources\Catalogo\CampanaResource;
use App\Models\Campana;
use App\Services\Catalogo\GestionarCampanaAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CampanaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CampanaResource::collection(
            Campana::with('linea')->latest()->get()
        );
    }

    public function show(Campana $campana): CampanaResource
    {
        return new CampanaResource($campana->load('linea'));
    }

    public function store(GuardarCampanaRequest $request, GestionarCampanaAction $accion): CampanaResource
    {
        $campana = $accion->crear($request->validated());

        return new CampanaResource($campana);
    }

    public function update(GuardarCampanaRequest $request, Campana $campana, GestionarCampanaAction $accion): CampanaResource
    {
        $campana = $accion->actualizar($campana, $request->validated());

        return new CampanaResource($campana);
    }

    /** TG-247: si tiene datos que dependen de él responde 409 con el motivo. */
    public function destroy(Campana $campana, GestionarCampanaAction $accion): JsonResponse
    {
        $accion->eliminar($campana);

        return response()->json(['data' => null, 'message' => 'Temporada eliminada.']);
    }
}
