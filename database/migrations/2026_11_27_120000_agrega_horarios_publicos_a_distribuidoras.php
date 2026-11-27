<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horario público estructurado (grupos de días y rangos).
 *
 * Es aditiva: no toca horario_publico, que sigue siendo el texto que se
 * muestra en el marketplace, la tienda y la API. Las filas que solo tienen
 * texto libre quedan con esta columna en null hasta que alguien las rearma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuidoras', function (Blueprint $tabla) {
            $tabla->json('horarios_publicos')->nullable()->after('horario_publico');
        });
    }

    public function down(): void
    {
        Schema::table('distribuidoras', function (Blueprint $tabla) {
            $tabla->dropColumn('horarios_publicos');
        });
    }
};
