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
        Schema::table('variantes', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_variantes_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'producto_id'], 'fk_variantes_2')->references(['distribuidora_id', 'id'])->on('productos')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['talla_id'], 'fk_variantes_3')->references(['id'])->on('tallas')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['color_id'], 'fk_variantes_4')->references(['id'])->on('colores')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('variantes', function (Blueprint $table) {
            $table->dropForeign('fk_variantes_1');
            $table->dropForeign('fk_variantes_2');
            $table->dropForeign('fk_variantes_3');
            $table->dropForeign('fk_variantes_4');
        });
    }
};
