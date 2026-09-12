<?php

namespace App\Models\Scopes;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $distribuidoraId = Tenant::id();

        if ($distribuidoraId !== null) {
            $builder->where($model->getTable() . '.distribuidora_id', $distribuidoraId);

            return;
        }

        // A partir de aquí no hay distribuidora. Son DOS situaciones muy
        // distintas y confundirlas es una fuga de datos, porque "no filtrar"
        // no significa "no mostrar nada": significa mostrar los datos de
        // TODAS las distribuidoras.
        //
        //   1) No hay nadie con sesión iniciada — seeders, comandos de
        //      consola, endpoints públicos. Aquí no filtrar es lo correcto y
        //      lo que el sistema siempre ha hecho.
        //
        //   2) Sí hay alguien con sesión, pero no se le pudo resolver la
        //      distribuidora. Por ejemplo un revendedor suspendido, o un
        //      empleado dado de baja. A esa persona NO se le puede quitar el
        //      filtro: se le devuelve cero resultados.
        //
        // admin_general es la excepción legítima del caso 2: administra todo
        // el SaaS y no pertenece a ninguna distribuidora a propósito.
        if (Auth::user() === null) {
            return;
        }

        if (Tenant::esAdminGeneral()) {
            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
