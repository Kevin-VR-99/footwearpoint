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
        Schema::table('producto_imagenes', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_producto_imagenes_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'producto_campana_id'], 'fk_producto_imagenes_2')->references(['distribuidora_id', 'id'])->on('producto_campana')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('producto_imagenes', function (Blueprint $table) {
            $table->dropForeign('fk_producto_imagenes_1');
            $table->dropForeign('fk_producto_imagenes_2');
        });
    }
};
