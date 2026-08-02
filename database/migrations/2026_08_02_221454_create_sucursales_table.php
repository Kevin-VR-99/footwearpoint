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
        Schema::create('sucursales', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->string('nombre', 120);
            $table->string('direccion', 300);
            $table->string('telefono', 30)->nullable();
            $table->boolean('es_principal')->default(false);
            $table->boolean('activa')->default(true);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['distribuidora_id', 'id'], 'uq_sucursales_tenant_id');
            $table->unique(['distribuidora_id', 'nombre'], 'uq_sucursal_tenant_nombre');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sucursales');
    }
};
