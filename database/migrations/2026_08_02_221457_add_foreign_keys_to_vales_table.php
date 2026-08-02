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
        Schema::table('vales', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_vales_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'cliente_directo_id'], 'fk_vales_2')->references(['distribuidora_id', 'id'])->on('clientes_directos')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'revendedor_distribuidora_id'], 'fk_vales_3')->references(['distribuidora_id', 'id'])->on('revendedor_distribuidora')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'pedido_origen_id'], 'fk_vales_4')->references(['distribuidora_id', 'id'])->on('pedidos')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'creado_por_staff_id'], 'fk_vales_5')->references(['distribuidora_id', 'id'])->on('distribuidora_staff')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vales', function (Blueprint $table) {
            $table->dropForeign('fk_vales_1');
            $table->dropForeign('fk_vales_2');
            $table->dropForeign('fk_vales_3');
            $table->dropForeign('fk_vales_4');
            $table->dropForeign('fk_vales_5');
        });
    }
};
