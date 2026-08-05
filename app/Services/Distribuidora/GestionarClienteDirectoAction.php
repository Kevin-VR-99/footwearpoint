<?php

namespace App\Services\Distribuidora;

use App\Models\ClienteDirecto;

class GestionarClienteDirectoAction
{
    public function crear(array $datos): ClienteDirecto
    {
        // distribuidora_id se completa solo, vía BelongsToTenant (Fase 0).
        return ClienteDirecto::create([
            'nombre'             => $datos['nombre'],
            'telefono'           => $datos['telefono'] ?? null,
            'email'              => $datos['email'] ?? null,
            'direccion_contacto' => $datos['direccion_contacto'] ?? null,
            'notas'              => $datos['notas'] ?? null,
            'estado'             => 'activo',
        ]);
    }

    public function actualizar(ClienteDirecto $cliente, array $datos): ClienteDirecto
    {
        $cliente->fill($datos);
        $cliente->save();

        return $cliente->fresh();
    }
}
