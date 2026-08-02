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
        Schema::table('ventas_directas', function (Blueprint $table) {
            $table->foreign(['distribuidora_id'], 'fk_ventas_directas_1')->references(['id'])->on('distribuidoras')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'sucursal_id'], 'fk_ventas_directas_2')->references(['distribuidora_id', 'id'])->on('sucursales')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'cliente_directo_id'], 'fk_ventas_directas_3')->references(['distribuidora_id', 'id'])->on('clientes_directos')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'registrada_por_staff_id'], 'fk_ventas_directas_4')->references(['distribuidora_id', 'id'])->on('distribuidora_staff')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventas_directas', function (Blueprint $table) {
            $table->dropForeign('fk_ventas_directas_1');
            $table->dropForeign('fk_ventas_directas_2');
            $table->dropForeign('fk_ventas_directas_3');
            $table->dropForeign('fk_ventas_directas_4');
        });
    }
};
