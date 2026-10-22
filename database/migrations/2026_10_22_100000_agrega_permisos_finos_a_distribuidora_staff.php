<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos finos por empleado (ver PermisoModuloService::PERMISOS_FINOS).
 *
 * Aditiva: null = nunca se configuró, y valen los valores por omisión, que
 * dejan a cada empleado existente con lo mismo que podía hacer hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidora_staff', function (Blueprint $tabla) {
            $tabla->json('permisos_finos')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidora_staff', function (Blueprint $tabla) {
            $tabla->dropColumn('permisos_finos');
        });
    }
};
