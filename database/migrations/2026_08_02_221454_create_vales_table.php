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
        Schema::create('vales', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('cliente_directo_id')->nullable();
            $table->unsignedBigInteger('revendedor_distribuidora_id')->nullable();
            $table->string('folio', 50);
            $table->decimal('monto_original', 12);
            $table->decimal('saldo_actual', 12);
            $table->dateTime('fecha_emision')->useCurrent();
            $table->dateTime('fecha_vencimiento');
            $table->enum('estado', ['activo', 'agotado', 'vencido', 'bloqueado'])->default('activo');
            $table->string('motivo', 300)->nullable();
            $table->unsignedBigInteger('pedido_origen_id')->nullable();
            $table->unsignedBigInteger('creado_por_staff_id');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['distribuidora_id', 'pedido_origen_id'], 'fk_vales_4');
            $table->index(['distribuidora_id', 'creado_por_staff_id'], 'fk_vales_5');
            $table->index(['distribuidora_id', 'cliente_directo_id'], 'ix_vale_cliente');
            $table->index(['distribuidora_id', 'revendedor_distribuidora_id'], 'ix_vale_revendedor');
            $table->index(['distribuidora_id', 'estado'], 'ix_vale_tenant_estado');
            $table->unique(['distribuidora_id', 'id'], 'uq_vales_tenant_id');
            $table->unique(['distribuidora_id', 'folio'], 'uq_vale_tenant_folio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vales');
    }
};
