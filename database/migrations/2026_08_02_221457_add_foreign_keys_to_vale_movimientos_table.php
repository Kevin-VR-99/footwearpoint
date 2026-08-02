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
        Schema::table('vale_movimientos', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_vale_movimientos_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'vale_id'], 'fk_vale_movimientos_2')->references(['distribuidora_id', 'id'])->on('vales')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'pedido_id'], 'fk_vale_movimientos_3')->references(['distribuidora_id', 'id'])->on('pedidos')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'venta_directa_id'], 'fk_vale_movimientos_4')->references(['distribuidora_id', 'id'])->on('ventas_directas')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'registrado_por_staff_id'], 'fk_vale_movimientos_5')->references(['distribuidora_id', 'id'])->on('distribuidora_staff')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vale_movimientos', function (Blueprint $table) {
            $table->dropForeign('fk_vale_movimientos_1');
            $table->dropForeign('fk_vale_movimientos_2');
            $table->dropForeign('fk_vale_movimientos_3');
            $table->dropForeign('fk_vale_movimientos_4');
            $table->dropForeign('fk_vale_movimientos_5');
        });
    }
};
