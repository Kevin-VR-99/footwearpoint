<?php

namespace App\Http\Controllers\Api\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogo\GuardarCampanaRequest;
use App\Http\Resources\Catalogo\CampanaResource;
use App\Models\Campana;
use App\Services\Catalogo\GestionarCampanaAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CampanaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CampanaResource::collection(Campana::all());
    }

    public function show(Campana $campana): CampanaResource
    {
        return new CampanaResource($campana);
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
}
