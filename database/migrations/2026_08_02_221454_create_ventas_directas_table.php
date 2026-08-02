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
        Schema::create('ventas_directas', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('cliente_directo_id')->nullable();
            $table->string('folio', 50);
            $table->dateTime('fecha_venta')->useCurrent();
            $table->decimal('subtotal', 12);
            $table->decimal('descuento', 12)->default(0);
            $table->decimal('total', 12);
            $table->enum('estado', ['completada', 'anulada'])->default('completada');
            $table->unsignedBigInteger('registrada_por_staff_id');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['distribuidora_id', 'sucursal_id'], 'fk_ventas_directas_2');
            $table->index(['distribuidora_id', 'cliente_directo_id'], 'fk_ventas_directas_3');
            $table->index(['distribuidora_id', 'registrada_por_staff_id'], 'fk_ventas_directas_4');
            $table->index(['distribuidora_id', 'fecha_venta'], 'ix_venta_tenant_fecha');
            $table->unique(['distribuidora_id', 'id'], 'uq_ventas_directas_tenant_id');
            $table->unique(['distribuidora_id', 'folio'], 'uq_venta_tenant_folio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ventas_directas');
    }
};
