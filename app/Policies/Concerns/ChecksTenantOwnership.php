<?php

namespace App\Policies\Concerns;

use App\Support\Tenant;

trait ChecksTenantOwnership
{
    // Cualquier Policy puede usar este método para confirmar que el
    // registro que se quiere ver/editar/borrar sí pertenece a la
    // distribuidora del usuario autenticado. Es un segundo candado además
    // del Global Scope: el Global Scope evita fugas por accidente en
    // consultas normales, esto documenta explícitamente la regla en cada
    // acción (ver, crear, editar, borrar) de cada recurso.
    protected function perteneceATenant($model): bool
    {
        return $model->distribuidora_id === Tenant::id();
    }
}