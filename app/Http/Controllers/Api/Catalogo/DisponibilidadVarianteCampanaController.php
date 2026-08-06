<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\GuardarDisponibilidadVarianteCampanaRequest;
use App\Http\Resources\Catalogo\DisponibilidadVarianteCampanaResource;
use App\Models\DisponibilidadVarianteCampana;
use App\Services\Catalogo\GestionarDisponibilidadVarianteCampanaAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DisponibilidadVarianteCampanaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DisponibilidadVarianteCampanaResource::collection(DisponibilidadVarianteCampana::all());
    }

    public function show(DisponibilidadVarianteCampana $disponibilidadVarianteCampana): DisponibilidadVarianteCampanaResource
    {
        return new DisponibilidadVarianteCampanaResource($disponibilidadVarianteCampana);
    }

    public function store(
        GuardarDisponibilidadVarianteCampanaRequest $request,
        GestionarDisponibilidadVarianteCampanaAction $accion
    ): DisponibilidadVarianteCampanaResource {
        $disponibilidad = $accion->crear($request->validated());

        return new DisponibilidadVarianteCampanaResource($disponibilidad);
    }

    public function update(
        GuardarDisponibilidadVarianteCampanaRequest $request,
        DisponibilidadVarianteCampana $disponibilidadVarianteCampana,
        GestionarDisponibilidadVarianteCampanaAction $accion
    ): DisponibilidadVarianteCampanaResource {
        $disponibilidad = $accion->actualizar($disponibilidadVarianteCampana, $request->validated());

        return new DisponibilidadVarianteCampanaResource($disponibilidad);
    }
}
