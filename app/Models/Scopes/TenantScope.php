<?php

namespace App\Models\Scopes;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $distribuidoraId = Tenant::id();

        if ($distribuidoraId !== null) {
            $builder->where($model->getTable() . '.distribuidora_id', $distribuidoraId);
        }
    }
}