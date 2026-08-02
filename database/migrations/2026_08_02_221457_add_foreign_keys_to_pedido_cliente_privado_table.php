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
        Schema::table('pedido_cliente_privado', function (Blueprint $table) {
            $table->foreign(['pedido_detalle_id'], 'fk_pedido_cliente_privado_1')->references(['id'])->on('pedido_detalle')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['cliente_privado_id'], 'fk_pedido_cliente_privado_2')->references(['id'])->on('clientes_privados_revendedor')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pedido_cliente_privado', function (Blueprint $table) {
            $table->dropForeign('fk_pedido_cliente_privado_1');
            $table->dropForeign('fk_pedido_cliente_privado_2');
        });
    }
};
