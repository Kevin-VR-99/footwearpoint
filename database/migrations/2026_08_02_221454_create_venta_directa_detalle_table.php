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
        Schema::create('venta_directa_detalle', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('venta_directa_id');
            $table->unsignedBigInteger('stock_local_id');
            $table->unsignedBigInteger('producto_campana_id')->nullable();
            $table->unsignedBigInteger('variante_id');
            $table->string('producto_nombre', 200);
            $table->string('modelo', 120);
            $table->string('talla', 30);
            $table->string('color', 100);
            $table->unsignedInteger('cantidad');
            $table->decimal('precio_unitario', 12);
            $table->decimal('subtotal', 12);

            $table->index(['distribuidora_id', 'venta_directa_id'], 'fk_venta_directa_detalle_2');
            $table->index(['distribuidora_id', 'stock_local_id'], 'fk_venta_directa_detalle_3');
            $table->index(['distribuidora_id', 'producto_campana_id'], 'fk_venta_directa_detalle_4');
            $table->index(['distribuidora_id', 'variante_id'], 'fk_venta_directa_detalle_5');
            $table->unique(['distribuidora_id', 'id'], 'uq_venta_directa_detalle_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venta_directa_detalle');
    }
};
