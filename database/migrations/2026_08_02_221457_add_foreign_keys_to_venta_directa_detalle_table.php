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
        Schema::table('venta_directa_detalle', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_venta_directa_detalle_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'venta_directa_id'], 'fk_venta_directa_detalle_2')->references(['distribuidora_id', 'id'])->on('ventas_directas')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'stock_local_id'], 'fk_venta_directa_detalle_3')->references(['distribuidora_id', 'id'])->on('stock_local')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'producto_campana_id'], 'fk_venta_directa_detalle_4')->references(['distribuidora_id', 'id'])->on('producto_campana')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'variante_id'], 'fk_venta_directa_detalle_5')->references(['distribuidora_id', 'id'])->on('variantes')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venta_directa_detalle', function (Blueprint $table) {
            $table->dropForeign('fk_venta_directa_detalle_1');
            $table->dropForeign('fk_venta_directa_detalle_2');
            $table->dropForeign('fk_venta_directa_detalle_3');
            $table->dropForeign('fk_venta_directa_detalle_4');
            $table->dropForeign('fk_venta_directa_detalle_5');
        });
    }
};
