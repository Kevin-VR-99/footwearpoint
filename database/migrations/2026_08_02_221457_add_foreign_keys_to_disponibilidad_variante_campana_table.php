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
        Schema::table('disponibilidad_variante_campana', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_disponibilidad_variante__1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'producto_campana_id'], 'fk_disponibilidad_variante__2')->references(['distribuidora_id', 'id'])->on('producto_campana')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'variante_id'], 'fk_disponibilidad_variante__3')->references(['distribuidora_id', 'id'])->on('variantes')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('disponibilidad_variante_campana', function (Blueprint $table) {
            $table->dropForeign('fk_disponibilidad_variante__1');
            $table->dropForeign('fk_disponibilidad_variante__2');
            $table->dropForeign('fk_disponibilidad_variante__3');
        });
    }
};
