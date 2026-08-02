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
        Schema::table('importaciones_catalogo', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_importaciones_catalogo_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['distribuidora_id', 'iniciada_por_staff_id'], 'fk_importaciones_catalogo_2')->references(['distribuidora_id', 'id'])->on('distribuidora_staff')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'revisada_por_staff_id'], 'fk_importaciones_catalogo_3')->references(['distribuidora_id', 'id'])->on('distribuidora_staff')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('importaciones_catalogo', function (Blueprint $table) {
            $table->dropForeign('fk_importaciones_catalogo_1');
            $table->dropForeign('fk_importaciones_catalogo_2');
            $table->dropForeign('fk_importaciones_catalogo_3');
        });
    }
};
