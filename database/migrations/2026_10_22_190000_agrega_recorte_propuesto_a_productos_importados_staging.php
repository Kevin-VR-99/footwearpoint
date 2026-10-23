<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-282 (E5-05) — El recorte que la IA propone para cada producto.
 *
 * La foto NO se publica sola. Nace como propuesta y una persona la confirma,
 * sube otra, o la deja vacía; aprobar no exige foto. Por eso hacen falta dos
 * datos y no uno: dónde quedó el recorte, y si alguien ya lo miró.
 *
 * Los bytes no van en datos_extraidos: ahí vive lo que leyó la IA y es un
 * json que se lee entero en cada pantalla. El recorte se sube al bucket y
 * aquí solo queda su URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos_importados_staging', function (Blueprint $tabla) {
            $tabla->string('foto_propuesta_url', 500)->nullable()->after('campos_dudosos');

            // null = no hubo propuesta (sin recuadro, sin pdftoppm, o el
            // recorte salió mal). Las tres son normales y dejan aprobar.
            $tabla->enum('foto_estado', ['propuesta', 'confirmada', 'descartada'])
                ->nullable()
                ->after('foto_propuesta_url');
        });
    }

    public function down(): void
    {
        Schema::table('productos_importados_staging', function (Blueprint $tabla) {
            $tabla->dropColumn(['foto_propuesta_url', 'foto_estado']);
        });
    }
};
