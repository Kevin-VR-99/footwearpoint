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
        Schema::table('ciclos_compra', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_ciclos_compra_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'configuracion_ciclo_id'], 'fk_ciclos_compra_2')->references(['distribuidora_id', 'id'])->on('configuraciones_ciclo')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ciclos_compra', function (Blueprint $table) {
            $table->dropForeign('fk_ciclos_compra_1');
            $table->dropForeign('fk_ciclos_compra_2');
        });
    }
};
