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
        Schema::table('movimientos_stock', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_movimientos_stock_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'stock_local_id'], 'fk_movimientos_stock_2')->references(['distribuidora_id', 'id'])->on('stock_local')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'venta_detalle_id'], 'fk_movimientos_stock_3')->references(['distribuidora_id', 'id'])->on('venta_directa_detalle')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'registrado_por_staff_id'], 'fk_movimientos_stock_4')->references(['distribuidora_id', 'id'])->on('distribuidora_staff')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos_stock', function (Blueprint $table) {
            $table->dropForeign('fk_movimientos_stock_1');
            $table->dropForeign('fk_movimientos_stock_2');
            $table->dropForeign('fk_movimientos_stock_3');
            $table->dropForeign('fk_movimientos_stock_4');
        });
    }
};
