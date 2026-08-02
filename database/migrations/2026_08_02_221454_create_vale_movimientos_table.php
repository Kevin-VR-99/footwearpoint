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
        Schema::create('vale_movimientos', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('vale_id');
            $table->enum('tipo', ['emision', 'aplicacion', 'ajuste_positivo', 'ajuste_negativo', 'vencimiento']);
            $table->decimal('monto', 12);
            $table->decimal('saldo_anterior', 12);
            $table->decimal('saldo_posterior', 12);
            $table->unsignedBigInteger('pedido_id')->nullable();
            $table->unsignedBigInteger('venta_directa_id')->nullable();
            $table->unsignedBigInteger('registrado_por_staff_id');
            $table->string('observaciones', 300)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['distribuidora_id', 'pedido_id'], 'fk_vale_movimientos_3');
            $table->index(['distribuidora_id', 'venta_directa_id'], 'fk_vale_movimientos_4');
            $table->index(['distribuidora_id', 'registrado_por_staff_id'], 'fk_vale_movimientos_5');
            $table->index(['distribuidora_id', 'vale_id', 'created_at'], 'ix_vale_mov_fecha');
            $table->unique(['distribuidora_id', 'id'], 'uq_vale_movimientos_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vale_movimientos');
    }
};
