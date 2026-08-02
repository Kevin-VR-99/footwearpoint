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
        Schema::create('suscripciones', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('plan_id')->index('fk_suscripciones_2');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->enum('estado', ['prueba', 'activa', 'vencida', 'suspendida', 'cancelada']);
            $table->decimal('precio_base_contratado', 12);
            $table->unsignedInteger('lineas_incluidas_contratadas');
            $table->decimal('precio_linea_extra_contratado', 12);
            $table->unsignedInteger('lineas_extra_contratadas')->default(0);
            $table->boolean('renovacion_automatica')->default(false);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['distribuidora_id', 'estado'], 'ix_suscripcion_tenant_estado');
            $table->unique(['distribuidora_id', 'id'], 'uq_suscripciones_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suscripciones');
    }
};
