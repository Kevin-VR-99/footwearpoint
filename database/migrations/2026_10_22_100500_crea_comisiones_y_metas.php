<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comisiones y metas de clientes mayoristas (revendedores). Aditiva:
 * - % por omisión de la distribuidora (0 = sin comisión, como hoy).
 * - % propio por cliente mayorista (nulo = usa el de la distribuidora).
 * - Metas mensuales de venta pagada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
            $tabla->decimal('comision_porcentaje_default', 5, 2)->default(0);
        });

        Schema::table('revendedor_distribuidora', function (Blueprint $tabla) {
            $tabla->decimal('comision_porcentaje', 5, 2)->nullable();
        });

        Schema::create('metas_revendedor', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->unsignedBigInteger('distribuidora_id');
            $tabla->unsignedBigInteger('revendedor_distribuidora_id');
            $tabla->char('periodo', 7)->comment('AAAA-MM');
            $tabla->decimal('monto_meta', 12, 2);
            $tabla->timestamps();

            $tabla->unique(['distribuidora_id', 'revendedor_distribuidora_id', 'periodo'], 'uq_meta_revendedor_periodo');
            $tabla->foreign('distribuidora_id', 'fk_metas_rev_1')->references('id')->on('distribuidoras')->onDelete('cascade');
            $tabla->foreign('revendedor_distribuidora_id', 'fk_metas_rev_2')->references('id')->on('revendedor_distribuidora')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metas_revendedor');
        Schema::table('revendedor_distribuidora', fn (Blueprint $t) => $t->dropColumn('comision_porcentaje'));
        Schema::table('configuraciones_distribuidora', fn (Blueprint $t) => $t->dropColumn('comision_porcentaje_default'));
    }
};
