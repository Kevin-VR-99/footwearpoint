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
        Schema::create('movimientos_stock', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('stock_local_id');
            $table->enum('tipo', ['entrada', 'venta', 'ajuste_positivo', 'ajuste_negativo', 'devolucion']);
            $table->unsignedInteger('cantidad');
            $table->unsignedInteger('existencia_anterior');
            $table->unsignedInteger('existencia_posterior');
            $table->unsignedBigInteger('venta_detalle_id')->nullable();
            $table->unsignedBigInteger('registrado_por_staff_id');
            $table->string('motivo', 300)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['distribuidora_id', 'stock_local_id'], 'fk_movimientos_stock_2');
            $table->index(['distribuidora_id', 'venta_detalle_id'], 'fk_movimientos_stock_3');
            $table->index(['distribuidora_id', 'registrado_por_staff_id'], 'fk_movimientos_stock_4');
            $table->index(['distribuidora_id', 'created_at'], 'ix_mov_stock_fecha');
            $table->unique(['distribuidora_id', 'id'], 'uq_movimientos_stock_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos_stock');
    }
};
