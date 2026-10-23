<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántas páginas de un catálogo ya leyó la IA, para la barra de avance.
 *
 * paginas es el total. paginas_leidas es lo que ya quedó guardado.
 * leyendo_hasta es el bloque que está en curso, todavía sin guardar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importaciones_catalogo', function (Blueprint $tabla) {
            $tabla->unsignedInteger('paginas_leidas')->nullable()->after('paginas');
            $tabla->unsignedInteger('leyendo_hasta')->nullable()->after('paginas_leidas');
        });
    }

    public function down(): void
    {
        Schema::table('importaciones_catalogo', function (Blueprint $tabla) {
            $tabla->dropColumn(['paginas_leidas', 'leyendo_hasta']);
        });
    }
};
