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
        Schema::create('stock_local', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('sucursal_id');
            $table->unsignedBigInteger('variante_id');
            $table->unsignedInteger('cantidad_disponible')->default(0);
            $table->unsignedInteger('stock_minimo')->default(0);
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['distribuidora_id', 'variante_id'], 'fk_stock_local_3');
            $table->unique(['distribuidora_id', 'id'], 'uq_stock_local_tenant_id');
            $table->unique(['distribuidora_id', 'sucursal_id', 'variante_id'], 'uq_stock_sucursal_variante');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_local');
    }
};
