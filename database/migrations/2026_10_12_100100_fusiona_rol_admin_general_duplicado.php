<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * TG-280 — Había dos roles admin_general: uno con team_id null (lo creaba
 * RolesPermissionsSeeder) y otro con team_id 0 (AdminGeneralSeeder). La
 * convención es el equipo 0, así que se pasan al de 0 los usuarios y
 * permisos del de null, sin duplicar filas, y se borra el de null.
 *
 * Idempotente: si no hay duplicado no hace nada. Si solo existe el de null,
 * se mueve al equipo 0 en lugar de borrarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = config('permission.table_names.roles', 'roles');
        $modelHasRoles = config('permission.table_names.model_has_roles', 'model_has_roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $team = config('permission.column_names.team_foreign_key', 'team_id');
        $rolePivot = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $permissionPivot = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        $modelKey = config('permission.column_names.model_morph_key', 'model_id');

        DB::transaction(function () use ($roles, $modelHasRoles, $roleHasPermissions, $team, $rolePivot, $permissionPivot, $modelKey) {
            $guards = DB::table($roles)->where('name', 'admin_general')->distinct()->pluck('guard_name');

            foreach ($guards as $guard) {
                $huerfanos = DB::table($roles)
                    ->where('name', 'admin_general')
                    ->where('guard_name', $guard)
                    ->whereNull($team)
                    ->orderBy('id')
                    ->pluck('id');

                if ($huerfanos->isEmpty()) {
                    continue;
                }

                $bueno = DB::table($roles)
                    ->where('name', 'admin_general')
                    ->where('guard_name', $guard)
                    ->where($team, 0)
                    ->value('id');

                if ($bueno === null) {
                    // Solo existe el de null: se mueve al equipo 0.
                    $bueno = $huerfanos->shift();
                    DB::table($roles)->where('id', $bueno)->update([$team => 0]);
                    DB::table($modelHasRoles)->where($rolePivot, $bueno)->update([$team => 0]);
                }

                foreach ($huerfanos as $viejo) {
                    foreach (DB::table($modelHasRoles)->where($rolePivot, $viejo)->get() as $fila) {
                        $ya = DB::table($modelHasRoles)
                            ->where($rolePivot, $bueno)
                            ->where('model_type', $fila->model_type)
                            ->where($modelKey, $fila->{$modelKey})
                            ->where($team, 0)
                            ->exists();

                        if (! $ya) {
                            DB::table($modelHasRoles)->insert([
                                $rolePivot => $bueno,
                                'model_type' => $fila->model_type,
                                $modelKey => $fila->{$modelKey},
                                $team => 0,
                            ]);
                        }
                    }

                    $permisos = DB::table($roleHasPermissions)->where($rolePivot, $viejo)->pluck($permissionPivot);

                    foreach ($permisos as $permiso) {
                        $ya = DB::table($roleHasPermissions)
                            ->where($rolePivot, $bueno)
                            ->where($permissionPivot, $permiso)
                            ->exists();

                        if (! $ya) {
                            DB::table($roleHasPermissions)->insert([
                                $rolePivot => $bueno,
                                $permissionPivot => $permiso,
                            ]);
                        }
                    }

                    DB::table($modelHasRoles)->where($rolePivot, $viejo)->delete();
                    DB::table($roleHasPermissions)->where($rolePivot, $viejo)->delete();
                    DB::table($roles)->where('id', $viejo)->delete();
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No se recrea el duplicado: era el bug.
    }
};
