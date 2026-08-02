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
        Schema::table('productos', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_productos_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'marca_id'], 'fk_productos_2')->references(['distribuidora_id', 'id'])->on('marcas')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'categoria_id'], 'fk_productos_3')->references(['distribuidora_id', 'id'])->on('categorias_producto')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropForeign('fk_productos_1');
            $table->dropForeign('fk_productos_2');
            $table->dropForeign('fk_productos_3');
        });
    }
};
