<?php

namespace App\Policies;

use App\Support\ContextoOperativo;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

class StockLocalPolicy
{
    public function viewAny(Authenticatable $usuario): bool
    {
        return $this->esStaffActivo();
    }

    public function create(Authenticatable $usuario): bool
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