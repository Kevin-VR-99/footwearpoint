<?php

namespace App\Http\Controllers\Api\Distribuidora;

use App\Http\Controllers\Controller;
use App\Http\Requests\Distribuidora\GuardarRevendedorRequest;
use App\Http\Resources\Distribuidora\RevendedorAfiliacionResource;
use App\Models\RevendedorDistribuidora;
use App\Services\Distribuidora\GestionarRevendedorAction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RevendedorController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // El Global Scope BelongsToTenant ya filtra por tu distribuidora;
        // el with('revendedor') trae los datos globales (nombre, tel, email).
        return RevendedorAfiliacionResource::collection(
            RevendedorDistribuidora::with('revendedor')->get()
        );
    }

    public function show(RevendedorDistribuidora $revendedor): RevendedorAfiliacionResource
    {
        return new RevendedorAfiliacionResource($revendedor->load('revendedor'));
    }

    public function store(
        GuardarRevendedorRequest $request,
        GestionarRevendedorAction $accion
    ): RevendedorAfiliacionResource {
        $afiliacion = $accion->afiliar($request->validated());

        return new RevendedorAfiliacionResource($afiliacion);
    }

    /**
     * "Desafiliar" NO es un endpoint aparte: se hace con este mismo PATCH,
     * mandando "estado": "inactivo". Coincide con el patrón del resto del
     * proyecto (no hay borrados definitivos, solo cambios de estado).
     */
    public function update(
        GuardarRevendedorRequest $request,
        RevendedorDistribuidora $revendedor,
        GestionarRevendedorAction $accion
    ): RevendedorAfiliacionResource {
        $afiliacion = $accion->actualizar($revendedor, $request->validated());

        return new RevendedorAfiliacionResource($afiliacion);
    }
}
