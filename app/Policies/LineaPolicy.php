<?php

namespace App\Policies;

use App\Models\Linea;
use App\Models\Usuario;
use App\Policies\Concerns\ChecksTenantOwnership;

class LineaPolicy
{
    use ChecksTenantOwnership;

    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Linea $linea): bool
    {
        return $this->perteneceATenant($linea);
    }

    public function create(Usuario $usuario): bool
    {
        return true;
    }

    public function update(Usuario $usuario, Linea $linea): bool
    {
        return $this->perteneceATenant($linea);
    }

    public function delete(Usuario $usuario, Linea $linea): bool
    {
        return $this->perteneceATenant($linea);
    }
}