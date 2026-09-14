<?php

namespace App\Services\Distribuidora;

use App\Models\ClienteDirecto;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($cliente, $datos) {
            $sincronizar = app(SincronizarCuentaDesdeContactoAction::class);
            $datos = $sincronizar->normalizar($datos);

            $cliente->fill($datos);
            $cliente->save();

            // TG-147: si ya tiene cuenta de la app, que vea el dato nuevo.
            $sincronizar->ejecutar($cliente->usuario_id, $datos);

            return $cliente->fresh();
        });
    }
}
