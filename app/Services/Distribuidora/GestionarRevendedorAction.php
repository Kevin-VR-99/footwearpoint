<?php

namespace App\Services\Distribuidora;

use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use Illuminate\Support\Facades\DB;

class GestionarRevendedorAction
{
    /**
     * DECISIÓN PROVISIONAL MÍA (no viene escrita así en ningún documento):
     * cada vez que se afilia a alguien, se crea un registro NUEVO en la
     * tabla global "revendedores", sin buscar si ya existe una persona con
     * ese nombre/teléfono en otra distribuidora — porque no hay ninguna
     * columna con restricción única que permita esa búsqueda de forma
     * confiable. Confirma con el equipo si prefieren otra solución.
     */
    public function afiliar(array $datos): RevendedorDistribuidora
    {
        return DB::transaction(function () use ($datos) {
            $revendedor = Revendedor::create([
                'nombre'   => $datos['nombre'],
                'telefono' => $datos['telefono'] ?? null,
                'email'    => $datos['email'] ?? null,
                'estado'   => 'activo',
            ]);

            // distribuidora_id se completa solo, vía BelongsToTenant
            // (creating() en el trait, ver Fase 0).
            $afiliacion = RevendedorDistribuidora::create([
                'revendedor_id'   => $revendedor->id,
                'codigo_interno'  => $datos['codigo_interno'] ?? null,
                'notas'           => $datos['notas'] ?? null,
                'estado'          => 'activo',
                'fecha_alta'      => now()->toDateString(),
            ]);

            return $afiliacion->fresh('revendedor');
        });
    }

    public function actualizar(RevendedorDistribuidora $afiliacion, array $datos): RevendedorDistribuidora
    {
        return DB::transaction(function () use ($afiliacion, $datos) {
            $datosContacto = array_intersect_key($datos, array_flip(['nombre', 'telefono', 'email']));
            $datosAfiliacion = array_intersect_key($datos, array_flip(['codigo_interno', 'notas', 'estado']));

            if ($datosContacto !== []) {
                $afiliacion->revendedor->fill($datosContacto)->save();
            }

            if ($datosAfiliacion !== []) {
                $afiliacion->fill($datosAfiliacion)->save();
            }

            return $afiliacion->fresh('revendedor');
        });
    }
}
