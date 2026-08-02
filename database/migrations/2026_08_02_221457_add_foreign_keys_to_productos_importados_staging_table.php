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
        Schema::table('productos_importados_staging', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_productos_importados_sta_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'importacion_id'], 'fk_productos_importados_sta_2')->references(['distribuidora_id', 'id'])->on('importaciones_catalogo')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'producto_creado_id'], 'fk_productos_importados_sta_3')->references(['distribuidora_id', 'id'])->on('productos')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productos_importados_staging', function (Blueprint $table) {
            $table->dropForeign('fk_productos_importados_sta_1');
            $table->dropForeign('fk_productos_importados_sta_2');
            $table->dropForeign('fk_productos_importados_sta_3');
        });
    }
};
