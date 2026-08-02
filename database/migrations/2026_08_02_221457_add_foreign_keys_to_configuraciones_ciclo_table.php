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
        Schema::table('configuraciones_ciclo', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_configuraciones_ciclo_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('configuraciones_ciclo', function (Blueprint $table) {
            $table->dropForeign('fk_configuraciones_ciclo_1');
        });
    }
};
