<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('configuracion_ciclo_dias_recepcion', function (Blueprint $table) {
            $table->foreign(['configuracion_ciclo_id'], 'fk_configuracion_ciclo_dias_1')->references(['id'])->on('configuraciones_ciclo')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('configuracion_ciclo_dias_recepcion', function (Blueprint $table) {
            $table->dropForeign('fk_configuracion_ciclo_dias_1');
        });
    }
};
