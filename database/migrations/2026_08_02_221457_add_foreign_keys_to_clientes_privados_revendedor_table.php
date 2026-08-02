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
        Schema::table('clientes_privados_revendedor', function (Blueprint $table) {
            $table->foreign(['revendedor_id'], 'fk_clientes_privados_revend_1')->references(['id'])->on('revendedores')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clientes_privados_revendedor', function (Blueprint $table) {
            $table->dropForeign('fk_clientes_privados_revend_1');
        });
    }
};
