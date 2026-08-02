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
        Schema::create('disponibilidad_variante_campana', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('producto_campana_id');
            $table->unsignedBigInteger('variante_id');
            $table->enum('estado', ['disponible', 'bajo_pedido', 'no_disponible']);
            $table->dateTime('fecha_verificacion')->nullable();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['distribuidora_id', 'variante_id'], 'fk_disponibilidad_variante__3');
            $table->unique(['distribuidora_id', 'id'], 'uq_disponibilidad_variante_campana_tenant_id');
            $table->unique(['distribuidora_id', 'producto_campana_id', 'variante_id'], 'uq_disp_variante_campana');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disponibilidad_variante_campana');
    }
};
