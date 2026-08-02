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
        Schema::table('pedido_detalle', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_pedido_detalle_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'pedido_id'], 'fk_pedido_detalle_2')->references(['distribuidora_id', 'id'])->on('pedidos')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'producto_campana_id'], 'fk_pedido_detalle_3')->references(['distribuidora_id', 'id'])->on('producto_campana')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'variante_id'], 'fk_pedido_detalle_4')->references(['distribuidora_id', 'id'])->on('variantes')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pedido_detalle', function (Blueprint $table) {
            $table->dropForeign('fk_pedido_detalle_1');
            $table->dropForeign('fk_pedido_detalle_2');
            $table->dropForeign('fk_pedido_detalle_3');
            $table->dropForeign('fk_pedido_detalle_4');
        });
    }
};
