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
        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_pedidos_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'sucursal_id'], 'fk_pedidos_2')->references(['distribuidora_id', 'id'])->on('sucursales')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'cliente_directo_id'], 'fk_pedidos_3')->references(['distribuidora_id', 'id'])->on('clientes_directos')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'revendedor_distribuidora_id'], 'fk_pedidos_4')->references(['distribuidora_id', 'id'])->on('revendedor_distribuidora')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'ciclo_compra_id'], 'fk_pedidos_5')->references(['distribuidora_id', 'id'])->on('ciclos_compra')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'capturado_por_staff_id'], 'fk_pedidos_6')->references(['distribuidora_id', 'id'])->on('distribuidora_staff')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropForeign('fk_pedidos_1');
            $table->dropForeign('fk_pedidos_2');
            $table->dropForeign('fk_pedidos_3');
            $table->dropForeign('fk_pedidos_4');
            $table->dropForeign('fk_pedidos_5');
            $table->dropForeign('fk_pedidos_6');
        });
    }
};
