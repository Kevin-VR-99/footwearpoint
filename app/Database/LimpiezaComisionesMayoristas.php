<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Borrado de comisiones de clientes mayoristas.
 *
 * La auditoría solo toca estas acciones, las que escribía ComisionesService:
 *
 *   DELETE FROM auditorias
 *   WHERE accion IN (
 *       'comision.default_actualizado',
 *       'comision.porcentaje_actualizado',
 *       'comision.meta_actualizada'
 *   );
 */
class LimpiezaComisionesMayoristas
{
    /** @var list<string> */
    public const ACCIONES_AUDITORIA = [
        'comision.default_actualizado',
        'comision.porcentaje_actualizado',
        'comision.meta_actualizada',
    ];

    public static function borrarAuditoria(): int
    {
        if (! Schema::hasTable('auditorias')) {
            return 0;
        }

        return DB::table('auditorias')->whereIn('accion', self::ACCIONES_AUDITORIA)->delete();
    }

    public static function quitarPermisoFino(): void
    {
        if (! Schema::hasColumn('distribuidora_staff', 'permisos_finos')) {
            return;
        }

        DB::table('distribuidora_staff')
            ->whereNotNull('permisos_finos')
            ->orderBy('id')
            ->each(function (object $fila): void {
                $finos = json_decode((string) $fila->permisos_finos, true);

                if (! is_array($finos) || ! array_key_exists('comisiones.ver', $finos)) {
                    return;
                }

                unset($finos['comisiones.ver']);

                DB::table('distribuidora_staff')->where('id', $fila->id)->update([
                    'permisos_finos' => $finos === [] ? null : json_encode($finos),
                ]);
            });
    }

    public static function quitarPermisoSpatie(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')->where('name', 'comisiones.ver')->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        if (Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        }

        if (Schema::hasTable('model_has_permissions')) {
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        }

        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
}
