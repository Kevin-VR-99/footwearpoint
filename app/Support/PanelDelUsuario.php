<?php

namespace App\Support;

use App\Models\DistribuidoraStaff;
use App\Models\Usuario;

/**
 * TG-219 — A dónde mandar a quien ya tiene sesión y está en una pantalla
 * pública (el inicio, el marketplace, los documentos legales).
 *
 * Antes esto vivía escrito a mano dentro de layouts/public.blade.php. Ahora lo
 * usan el layout y la página de inicio, para que el botón diga y lleve a lo
 * mismo en las dos.
 *
 * Ojo con el efecto de lado, que es a propósito y se conserva igual que antes:
 * deja puesto el team de Spatie (setPermissionsTeamId) con la distribuidora de
 * la persona, o 0 si es el admin general. El resto de la pantalla cuenta con
 * eso para poder preguntar por roles.
 */
class PanelDelUsuario
{
    /**
     * @return array{url: string, texto: string}
     */
    public static function para(Usuario $usuario): array
    {
        setPermissionsTeamId(0);

        $esAdminGeneral = $usuario->hasRole('admin_general');

        if (! $esAdminGeneral) {
            // TG-224: sin scope a propósito, aquí se RESUELVE la distribuidora
            // del usuario (ver Tenant::desdeStaff).
            $staff = DistribuidoraStaff::withoutGlobalScopes()
                ->where('usuario_id', $usuario->id)
                ->where('estado', 'activo')
                ->first();

            if ($staff) {
                setPermissionsTeamId($staff->distribuidora_id);
            }
        }

        return [
            'url' => $esAdminGeneral ? route('admin.dashboard') : route('dashboard'),
            'texto' => $esAdminGeneral ? 'Ir al panel admin' : 'Ir al panel',
        ];
    }
}
