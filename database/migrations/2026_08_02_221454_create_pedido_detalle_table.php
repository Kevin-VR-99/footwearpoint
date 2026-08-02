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
        Schema::create('pedido_detalle', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('pedido_id');
            $table->unsignedBigInteger('producto_campana_id');
            $table->unsignedBigInteger('variante_id');
            $table->string('producto_nombre', 200);
            $table->string('modelo', 120);
            $table->string('talla', 30);
            $table->string('color', 100);
            $table->unsignedInteger('cantidad');
            $table->decimal('precio_unitario', 12);
            $table->decimal('subtotal', 12);
            $table->decimal('anticipo_requerido', 12)->default(0);
            $table->enum('estado_surtido', ['pendiente', 'disponible', 'parcial', 'solicitado', 'recibido', 'no_surtido'])->default('pendiente');
            $table->unsignedInteger('cantidad_confirmada')->default(0);
            $table->unsignedInteger('cantidad_recibida')->default(0);
            $table->string('motivo_no_surtido', 300)->nullable();
            $table->enum('resolucion_no_surtido', ['pendiente', 'sustitucion', 'reembolso_anticipo'])->default('pendiente');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['distribuidora_id', 'pedido_id'], 'fk_pedido_detalle_2');
            $table->index(['distribuidora_id', 'producto_campana_id'], 'fk_pedido_detalle_3');
            $table->index(['distribuidora_id', 'variante_id'], 'fk_pedido_detalle_4');
            $table->unique(['distribuidora_id', 'id'], 'uq_pedido_detalle_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedido_detalle');
    }
};
