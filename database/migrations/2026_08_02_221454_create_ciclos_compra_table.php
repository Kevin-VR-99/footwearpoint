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
        Schema::create('ciclos_compra', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('configuracion_ciclo_id')->nullable();
            $table->string('nombre', 120);
            $table->dateTime('fecha_apertura');
            $table->dateTime('fecha_cierre');
            $table->dateTime('fecha_solicitud_fabrica')->nullable();
            $table->date('fecha_estimada_llegada')->nullable();
            $table->dateTime('fecha_recepcion')->nullable();
            $table->enum('estado', ['abierto', 'cerrado', 'solicitado', 'en_transito', 'recibido', 'finalizado'])->default('abierto');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['distribuidora_id', 'configuracion_ciclo_id'], 'fk_ciclos_compra_2');
            $table->index(['distribuidora_id', 'estado'], 'ix_ciclo_tenant_estado');
            $table->unique(['distribuidora_id', 'id'], 'uq_ciclos_compra_tenant_id');
            $table->unique(['distribuidora_id', 'nombre'], 'uq_ciclo_tenant_nombre');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ciclos_compra');
    }
};
