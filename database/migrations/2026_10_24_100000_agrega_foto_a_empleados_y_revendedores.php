<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto de perfil. El empleado la guarda en su cuenta (usuarios) y el cliente
 * mayorista en su ficha de contacto (revendedores). Ambas son opcionales: sin
 * foto se muestran las iniciales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $tabla) {
            $tabla->string('foto_url', 500)->nullable()->after('telefono');
        });

        Schema::table('revendedores', function (Blueprint $tabla) {
            $tabla->string('foto_url', 500)->nullable()->after('telefono');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $tabla) {
            $tabla->dropColumn('foto_url');
        });

        Schema::table('revendedores', function (Blueprint $tabla) {
            $tabla->dropColumn('foto_url');
        });
    }
};
