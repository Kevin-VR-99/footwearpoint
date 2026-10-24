<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Seguridad oct-2026 — "la sesión del panel es persistente".
 *
 * El panel de distribuidora sigue con sesión persistente (decisión de
 * producto: cookie remember + SESSION_LIFETIME larga). El admin general, que
 * administra todo el SaaS, ya no: entra sin cookie remember y su cookie de
 * sesión es de navegador (se borra al cerrarlo). SESSION_LIFETIME no cambia.
 *
 * SESSION_ADMIN_GENERAL_PERSISTENTE=true regresa al comportamiento anterior.
 */
class SesionDelPanel
{
    public static function esPersistente(Usuario $usuario): bool
    {
        if ((bool) config('session.admin_general_persistente', false)) {
            return true;
        }

        return ! self::esAdminGeneral($usuario);
    }

    /** Igual que Tenant::esAdminGeneral(), pero para un usuario dado (sin depender del team fijado). */
    public static function esAdminGeneral(Usuario $usuario): bool
    {
        $tablaRoles = config('permission.table_names.roles', 'roles');
        $tablaPivote = config('permission.table_names.model_has_roles', 'model_has_roles');

        return DB::table($tablaPivote)
            ->join($tablaRoles, $tablaRoles.'.id', '=', $tablaPivote.'.role_id')
            ->where($tablaPivote.'.model_id', $usuario->id)
            ->where($tablaPivote.'.model_type', $usuario->getMorphClass())
            ->where($tablaRoles.'.name', 'admin_general')
            ->exists();
    }
}
