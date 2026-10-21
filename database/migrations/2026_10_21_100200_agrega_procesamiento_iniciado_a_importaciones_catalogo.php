<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se mandó a procesar cada catálogo con la IA. Sirve para los topes de
 * gasto (importaciones por día y gasto del mes, ver config/ia.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('importaciones_catalogo', 'procesamiento_iniciado_at')) {
            Schema::table('importaciones_catalogo', function (Blueprint $tabla) {
                $tabla->dateTime('procesamiento_iniciado_at')->nullable()->after('costo_usd');
                $tabla->index('procesamiento_iniciado_at', 'ix_importaciones_catalogo_procesamiento');
            });
        }
    }

    public function down(): void
    {
        Schema::table('importaciones_catalogo', function (Blueprint $tabla) {
            $tabla->dropIndex('ix_importaciones_catalogo_procesamiento');
            $tabla->dropColumn('procesamiento_iniciado_at');
        });
    }
};
