<?php

namespace App\Http\Controllers\Api\Distribuidora;

use App\Http\Controllers\Controller;
use App\Http\Requests\Distribuidora\ActualizarConfiguracionDistribuidoraRequest;
use App\Http\Resources\Distribuidora\ConfiguracionDistribuidoraResource;
use App\Models\ConfiguracionDistribuidora;
use App\Services\Distribuidora\ActualizarConfiguracionDistribuidoraAction;
use App\Support\Tenant;

class ConfiguracionDistribuidoraController extends Controller
{
    public function show(): ConfiguracionDistribuidoraResource
    {
        $this->verificarTenant();

        // ConfiguracionDistribuidora SÍ usa BelongsToTenant, así que el
        // Global Scope ya filtra esto solo por la distribuidora actual.
        return new ConfiguracionDistribuidoraResource(
            ConfiguracionDistribuidora::firstOrFail()
        );
    }

    public function update(
        ActualizarConfiguracionDistribuidoraRequest $request,
        ActualizarConfiguracionDistribuidoraAction $accion
    ): ConfiguracionDistribuidoraResource {
        $this->verificarTenant();

        $config = $accion->ejecutar($request->validated());

        return new ConfiguracionDistribuidoraResource($config);
    }

    /**
     * Si Tenant::id() regresa null aquí, tu TenantScope NO aplica ningún
     * filtro (así lo diseñaste: null = sin restricción, para admin_general).
     * Pero en ESTE endpoint eso sería grave: firstOrFail() devolvería la
     * primera fila de CUALQUIER distribuidora de la tabla. Por eso se
     * verifica aquí a mano, como capa extra sobre el middleware
     * role:admin_distribuidora.
     */
    private function verificarTenant(): void
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora del usuario autenticado.');
    }
}