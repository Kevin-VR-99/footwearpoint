<?php

namespace App\Http\Controllers\Api\Distribuidora;

use App\Http\Controllers\Controller;
use App\Http\Requests\Distribuidora\GuardarConfiguracionCicloRequest;
use App\Http\Resources\Distribuidora\ConfiguracionCicloResource;
use App\Models\ConfiguracionCiclo;
use App\Services\Distribuidora\ConfiguracionCicloAction;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConfiguracionCicloController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->verificarTenant();

        return ConfiguracionCicloResource::collection(
            ConfiguracionCiclo::with('diasRecepcion')->get()
        );
    }

    public function show(ConfiguracionCiclo $ciclo): ConfiguracionCicloResource
    {
        // Aquí NO hace falta verificarTenant(): el route-model-binding ya
        // aplicó el Global Scope al buscar $ciclo. Si fuera de otra
        // distribuidora, Laravel ya habría respondido 404 antes de llegar aquí.
        return new ConfiguracionCicloResource($ciclo->load('diasRecepcion'));
    }

    public function store(
        GuardarConfiguracionCicloRequest $request,
        ConfiguracionCicloAction $accion
    ): ConfiguracionCicloResource {
        $this->verificarTenant();

        $ciclo = $accion->crear($request->validated());

        return new ConfiguracionCicloResource($ciclo);
    }

    public function update(
        GuardarConfiguracionCicloRequest $request,
        ConfiguracionCiclo $ciclo,
        ConfiguracionCicloAction $accion
    ): ConfiguracionCicloResource {
        $ciclo = $accion->actualizar($ciclo, $request->validated());

        return new ConfiguracionCicloResource($ciclo);
    }

    public function destroy(ConfiguracionCiclo $ciclo): JsonResponse
    {
        $ciclo->delete();

        return response()->json([
            'data' => null,
            'message' => 'Configuración de ciclo eliminada.',
        ]);
    }

    private function verificarTenant(): void
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora del usuario autenticado.');
    }
}