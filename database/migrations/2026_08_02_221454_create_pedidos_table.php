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
        Schema::create('pedidos', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('sucursal_id');
            $table->string('folio', 50);
            $table->enum('tipo', ['cliente_directo', 'revendedor']);
            $table->unsignedBigInteger('cliente_directo_id')->nullable();
            $table->unsignedBigInteger('revendedor_distribuidora_id')->nullable();
            $table->unsignedBigInteger('ciclo_compra_id')->nullable();
            $table->enum('estado', ['borrador', 'colocado', 'en_revision', 'confirmado', 'parcialmente_disponible', 'rechazado', 'incluido_en_ciclo', 'solicitado_fabrica', 'en_transito', 'recibido_distribuidora', 'listo_entrega', 'entregado', 'no_surtido', 'vencido_recoleccion', 'descartado'])->default('borrador');
            $table->decimal('subtotal', 12);
            $table->decimal('total', 12);
            $table->dateTime('fecha_colocacion')->nullable();
            $table->date('fecha_estimada_llegada')->nullable();
            $table->dateTime('fecha_listo_entrega')->nullable();
            $table->dateTime('fecha_limite_recoleccion')->nullable();
            $table->dateTime('fecha_entrega')->nullable();
            $table->enum('resolucion_recoleccion', ['pendiente', 'sustitucion', 'reembolso_anticipo'])->default('pendiente');
            $table->unsignedBigInteger('capturado_por_staff_id');
            $table->text('observaciones')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['distribuidora_id', 'sucursal_id'], 'fk_pedidos_2');
            $table->index(['distribuidora_id', 'cliente_directo_id'], 'fk_pedidos_3');
            $table->index(['distribuidora_id', 'ciclo_compra_id'], 'fk_pedidos_5');
            $table->index(['distribuidora_id', 'capturado_por_staff_id'], 'fk_pedidos_6');
            $table->index(['distribuidora_id', 'revendedor_distribuidora_id'], 'ix_pedido_revendedor');
            $table->index(['distribuidora_id', 'estado'], 'ix_pedido_tenant_estado');
            $table->index(['distribuidora_id', 'fecha_colocacion'], 'ix_pedido_tenant_fecha');
            $table->unique(['distribuidora_id', 'id'], 'uq_pedidos_tenant_id');
            $table->unique(['distribuidora_id', 'folio'], 'uq_pedido_tenant_folio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
