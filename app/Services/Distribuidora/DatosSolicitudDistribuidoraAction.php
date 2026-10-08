<?php

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Support\Tenant;

/**
 * TG-195 (G4) — Los datos de una distribuidora para revisarla antes de
 * aprobarla o rechazarla (E2-01: "Puedo revisar los datos de una
 * distribuidora antes de aprobarla").
 *
 * La usan el panel "Ver datos" del admin general y
 * GET /api/admin/distribuidoras/{id}, para que los dos muestren lo mismo.
 */
class DatosSolicitudDistribuidoraAction
{
    public function ejecutar(Distribuidora $distribuidora): array
    {
        // Mismo criterio que AprobarDistribuidoraAction: el staff se consulta
        // dentro del contexto de esa distribuidora.
        $administradores = Tenant::forzar($distribuidora->id, fn () => DistribuidoraStaff::with('usuario')
            ->where('distribuidora_id', $distribuidora->id)
            ->where('tipo', 'administrador')
            ->orderBy('id')
            ->get());

        return [
            'id'                  => $distribuidora->id,
            'nombre_comercial'    => $distribuidora->nombre_comercial,
            'razon_social'        => $distribuidora->razon_social,
            'rfc'                 => $distribuidora->rfc,
            'slug'                => $distribuidora->slug,
            'subdominio'          => $distribuidora->subdominio,
            'descripcion_publica' => $distribuidora->descripcion_publica,
            'direccion_publica'   => $distribuidora->direccion_publica,
            'telefono_publico'    => $distribuidora->telefono_publico,
            'email_publico'       => $distribuidora->email_publico,
            'horario_publico'     => $distribuidora->horario_publico,
            'marketplace_visible' => (bool) $distribuidora->marketplace_visible,
            'estado'              => $distribuidora->estado,
            'fecha_solicitud'     => $distribuidora->fecha_solicitud?->toIso8601String(),
            'fecha_aprobacion'    => $distribuidora->fecha_aprobacion?->toIso8601String(),
            'motivo_rechazo'      => $distribuidora->motivo_rechazo,
            'administradores'     => $administradores->map(fn (DistribuidoraStaff $staff) => [
                'nombre' => $staff->usuario?->nombre,
                'email'  => $staff->usuario?->email,
                'estado' => $staff->estado,
            ])->values()->all(),
            // TG-197 (G16): categorías del directorio, activas e inactivas.
            'categorias_directorio' => $distribuidora->categoriasDirectorio()
                ->orderBy('nombre')
                ->get()
                ->map(fn ($categoria) => [
                    'id'     => $categoria->id,
                    'nombre' => $categoria->nombre,
                    'activa' => (bool) $categoria->activa,
                ])->values()->all(),
        ];
    }
}
