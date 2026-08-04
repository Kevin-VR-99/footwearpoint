<?php

namespace App\Http\Controllers\Api\Distribuidora;

use App\Http\Controllers\Controller;
use App\Http\Requests\Distribuidora\ActualizarPerfilDistribuidoraRequest;
use App\Http\Resources\Distribuidora\PerfilResource;
use App\Models\Distribuidora;
use App\Services\Distribuidora\ActualizarPerfilDistribuidoraAction;
use App\Support\Tenant;

class PerfilController extends Controller
{
    public function show(): PerfilResource
    {
        return new PerfilResource($this->distribuidoraActual());
    }

    public function update(
        ActualizarPerfilDistribuidoraRequest $request,
        ActualizarPerfilDistribuidoraAction $accion
    ): PerfilResource {
        $distribuidora = $accion->ejecutar(
            $this->distribuidoraActual(),
            $request->safe()->except('logotipo'),
            $request->file('logotipo')
        );

        return new PerfilResource($distribuidora);
    }

    /**
     * Distribuidora NO usa BelongsToTenant (es la raíz del tenant, no tiene
     * columna distribuidora_id) — por eso aquí sí hay que resolver el id a
     * mano con Tenant::id(), confirmado contra tu archivo Tenant.php real.
     * El abort_if es una capa extra: si Tenant::id() regresara null aquí (no
     * debería, ya lo bloquea el middleware role:admin_distribuidora),
     * preferimos un 403 limpio a dejar pasar algo ambiguo.
     */
    private function distribuidoraActual(): Distribuidora
    {
        $distribuidoraId = Tenant::id();

        abort_if($distribuidoraId === null, 403, 'No se pudo determinar la distribuidora del usuario autenticado.');

        return Distribuidora::findOrFail($distribuidoraId);
    }
}