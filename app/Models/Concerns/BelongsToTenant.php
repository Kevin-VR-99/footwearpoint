<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\Tenant;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        // Filtra automáticamente cualquier consulta por la distribuidora
        // del usuario autenticado.
        static::addGlobalScope(new TenantScope());

        // Si se crea un registro nuevo y no se indicó distribuidora_id a
        // mano, se completa solo con la del usuario autenticado.
        static::creating(function ($model) {
            if (empty($model->distribuidora_id) && Tenant::id() !== null) {
                $model->distribuidora_id = Tenant::id();
            }
        });
    }
}