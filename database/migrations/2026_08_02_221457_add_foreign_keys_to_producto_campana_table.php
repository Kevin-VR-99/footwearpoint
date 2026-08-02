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
        Schema::table('producto_campana', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_producto_campana_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'producto_id'], 'fk_producto_campana_2')->references(['distribuidora_id', 'id'])->on('productos')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'campana_id'], 'fk_producto_campana_3')->references(['distribuidora_id', 'id'])->on('campanas')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('producto_campana', function (Blueprint $table) {
            $table->dropForeign('fk_producto_campana_1');
            $table->dropForeign('fk_producto_campana_2');
            $table->dropForeign('fk_producto_campana_3');
        });
    }
};
