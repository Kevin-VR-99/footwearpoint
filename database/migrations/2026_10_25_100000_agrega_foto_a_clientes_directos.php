<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto de perfil del cliente directo, igual que empleados y clientes
 * mayoristas. Opcional: sin foto se muestran las iniciales.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clientes_directos', 'foto_url')) {
            return;
        }

        Schema::table('clientes_directos', function (Blueprint $tabla) {
            $tabla->string('foto_url', 500)->nullable()->after('telefono');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clientes_directos', 'foto_url')) {
            return;
        }

        Schema::table('clientes_directos', function (Blueprint $tabla) {
            $tabla->dropColumn('foto_url');
        });
    }
};
