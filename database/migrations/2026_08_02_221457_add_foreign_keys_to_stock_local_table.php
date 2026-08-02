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
        Schema::table('stock_local', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_stock_local_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'sucursal_id'], 'fk_stock_local_2')->references(['distribuidora_id', 'id'])->on('sucursales')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'variante_id'], 'fk_stock_local_3')->references(['distribuidora_id', 'id'])->on('variantes')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_local', function (Blueprint $table) {
            $table->dropForeign('fk_stock_local_1');
            $table->dropForeign('fk_stock_local_2');
            $table->dropForeign('fk_stock_local_3');
        });
    }
};
