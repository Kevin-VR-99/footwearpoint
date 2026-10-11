<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-277 (A8) — Dónde guardar el identificador del archivo en el proveedor de
 * IA.
 *
 * Al subir el catálogo, Anthropic devuelve un identificador del archivo (por
 * ejemplo file_01XVEoHeJaS2cMK8eEvH6zeQ). Hoy ese dato vive en el caché
 * (ExtraerProductosDelCatalogoAction), así que se pierde si se reinicia el
 * worker y hay que volver a subir el catálogo completo.
 *
 * Aquí solo se agrega la columna. Pasar el dato del caché a la columna va en
 * otro PR.
 *
 * Nullable y sin valor por omisión: una importación que todavía no subió su
 * archivo la deja en null. Sin índice ni llave foránea: no se busca por este
 * campo y el identificador es de un servicio de fuera, no de otra tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importaciones_catalogo', function (Blueprint $tabla) {
            $tabla->string('archivo_ia_id', 100)->nullable()->after('archivo_url');
        });
    }

    public function down(): void
    {
        Schema::table('importaciones_catalogo', function (Blueprint $tabla) {
            $tabla->dropColumn('archivo_ia_id');
        });
    }
};
