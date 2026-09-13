<?php

namespace App\Services\Auditoria;

use App\Models\Auditoria;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;

class RegistrarAuditoriaAction
{
    public function ejecutar(
        string $accion,
        string $entidadTipo,
        ?int $entidadId = null,
        ?array $datosPrevios = null,
        ?array $datosNuevos = null
    ): Auditoria {
        return Auditoria::create([
            'usuario_id'       => Auth::id(),
            'distribuidora_id' => Tenant::id(),
            'accion'           => $accion,
            'entidad_tipo'     => $entidadTipo,
            'entidad_id'       => $entidadId,
            'datos_previos'    => $datosPrevios,
            'datos_nuevos'     => $datosNuevos,
            'ip_origen'        => request()?->ip(),
        ]);
    }
}