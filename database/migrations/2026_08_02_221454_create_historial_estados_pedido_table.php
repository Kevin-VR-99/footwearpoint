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
        Schema::create('historial_estados_pedido', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('pedido_id');
            $table->string('estado_anterior', 40)->nullable();
            $table->string('estado_nuevo', 40);
            $table->unsignedBigInteger('cambiado_por_staff_id');
            $table->string('comentario', 300)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['distribuidora_id', 'cambiado_por_staff_id'], 'fk_historial_estados_pedido_3');
            $table->index(['distribuidora_id', 'pedido_id', 'created_at'], 'ix_hist_pedido_fecha');
            $table->unique(['distribuidora_id', 'id'], 'uq_historial_estados_pedido_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('historial_estados_pedido');
    }
};
