<?php

namespace App\Policies;

use App\Support\ContextoOperativo;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

class VentaDirectaPolicy
{
    public function create(Authenticatable $usuario): bool
    {
        try {
            app(ContextoOperativo::class)->staff();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}