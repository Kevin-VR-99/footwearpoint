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
        Schema::create('solicitudes_cambio', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('pedido_detalle_id')->nullable();
            $table->unsignedBigInteger('venta_detalle_id')->nullable();
            $table->unsignedBigInteger('cliente_directo_id')->nullable();
            $table->unsignedBigInteger('revendedor_distribuidora_id')->nullable();
            $table->dateTime('fecha_entrega_original');
            $table->dateTime('fecha_solicitud')->useCurrent();
            $table->unsignedSmallInteger('dias_solicitud_aplicados');
            $table->dateTime('fecha_limite_solicitud');
            $table->unsignedSmallInteger('dias_gestion_fabrica_aplicados');
            $table->dateTime('fecha_limite_gestion_fabrica');
            $table->enum('estado', ['solicitada', 'autorizada', 'rechazada', 'enviada_fabrica', 'aceptada_fabrica', 'vale_emitido', 'cerrada'])->default('solicitada');
            $table->unsignedBigInteger('vale_generado_id')->nullable();
            $table->string('motivo', 300);
            $table->string('resolucion', 300)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['distribuidora_id', 'pedido_detalle_id'], 'fk_solicitudes_cambio_2');
            $table->index(['distribuidora_id', 'venta_detalle_id'], 'fk_solicitudes_cambio_3');
            $table->index(['distribuidora_id', 'cliente_directo_id'], 'fk_solicitudes_cambio_4');
            $table->index(['distribuidora_id', 'revendedor_distribuidora_id'], 'fk_solicitudes_cambio_5');
            $table->index(['distribuidora_id', 'vale_generado_id'], 'fk_solicitudes_cambio_6');
            $table->unique(['distribuidora_id', 'id'], 'uq_solicitudes_cambio_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitudes_cambio');
    }
};
