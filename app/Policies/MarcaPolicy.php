<?php

namespace App\Policies;

use App\Models\Marca;
use App\Models\Usuario;
use App\Policies\Concerns\ChecksTenantOwnership;

class MarcaPolicy
{
    use ChecksTenantOwnership;

    public function viewAny(Usuario $usuario): bool
    {
        // Cualquier persona con sesión iniciada dentro de una distribuidora
        // puede listar sus propias marcas (el Global Scope ya filtra cuáles).
        return true;
    }

    public function view(Usuario $usuario, Marca $marca): bool
    {
        return $this->perteneceATenant($marca);
    }

    public function create(Usuario $usuario): bool
    {
        return true;
    }

    public function update(Usuario $usuario, Marca $marca): bool
    {
        return $this->perteneceATenant($marca);
    }

    public function delete(Usuario $usuario, Marca $marca): bool
    {
        return $this->perteneceATenant($marca);
    }
}