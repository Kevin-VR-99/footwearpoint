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
        Schema::create('pagos', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('pedido_id')->nullable();
            $table->unsignedBigInteger('venta_directa_id')->nullable();
            $table->string('folio', 60);
            $table->enum('tipo', ['anticipo', 'saldo_pedido', 'total_revendedor', 'venta_directa', 'reembolso_anticipo']);
            $table->enum('direccion', ['entrada', 'salida'])->default('entrada');
            $table->enum('metodo', ['efectivo', 'transferencia', 'tarjeta', 'mercado_pago', 'otro']);
            $table->decimal('monto', 12);
            $table->dateTime('fecha_pago')->useCurrent();
            $table->string('referencia', 190)->nullable();
            $table->string('proveedor_pago', 80)->nullable();
            $table->string('referencia_externa', 190)->nullable();
            $table->enum('estado', ['pendiente', 'aplicado', 'fallido', 'revertido'])->default('aplicado');
            $table->unsignedBigInteger('registrado_por_staff_id');
            $table->dateTime('created_at')->useCurrent();

            $table->index(['distribuidora_id', 'pedido_id'], 'fk_pagos_2');
            $table->index(['distribuidora_id', 'venta_directa_id'], 'fk_pagos_3');
            $table->index(['distribuidora_id', 'registrado_por_staff_id'], 'fk_pagos_4');
            $table->index(['distribuidora_id', 'fecha_pago'], 'ix_pago_tenant_fecha');
            $table->unique(['distribuidora_id', 'id'], 'uq_pagos_tenant_id');
            $table->unique(['distribuidora_id', 'folio'], 'uq_pago_tenant_folio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
