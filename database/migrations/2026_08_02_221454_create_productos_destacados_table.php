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
        Schema::create('productos_destacados', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('producto_campana_id');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['distribuidora_id', 'producto_campana_id'], 'uq_destacado_producto_campana');
            $table->unique(['distribuidora_id', 'id'], 'uq_productos_destacados_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos_destacados');
    }
};
