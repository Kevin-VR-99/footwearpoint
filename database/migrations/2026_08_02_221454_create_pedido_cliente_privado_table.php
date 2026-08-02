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
        Schema::create('pedido_cliente_privado', function (Blueprint $table) {
            $table->unsignedBigInteger('pedido_detalle_id');
            $table->unsignedBigInteger('cliente_privado_id')->index('fk_pedido_cliente_privado_2');
            $table->unsignedInteger('cantidad_asignada');

            $table->primary(['pedido_detalle_id', 'cliente_privado_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedido_cliente_privado');
    }
};
