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
        Schema::table('revendedor_distribuidora', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_revendedor_distribuidora_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['revendedor_id'], 'fk_revendedor_distribuidora_2')->references(['id'])->on('revendedores')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('revendedor_distribuidora', function (Blueprint $table) {
            $table->dropForeign('fk_revendedor_distribuidora_1');
            $table->dropForeign('fk_revendedor_distribuidora_2');
        });
    }
};
