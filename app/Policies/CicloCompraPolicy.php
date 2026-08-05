<?php

namespace App\Policies;

use App\Support\ContextoOperativo;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

class CicloCompraPolicy
{
    public function view(Authenticatable $usuario): bool
    {
        return $this->esStaffActivo();
    }

    public function update(Authenticatable $usuario): bool
    {
        return $this->esStaffActivo();
    }

    private function esStaffActivo(): bool
    {
        try {
            app(ContextoOperativo::class)->staff();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}