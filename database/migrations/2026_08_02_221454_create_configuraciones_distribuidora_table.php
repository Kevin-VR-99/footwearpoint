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
        Schema::create('configuraciones_distribuidora', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->unique('uq_config_tenant')->comment('Tenant propietario del registro.');
            $table->decimal('anticipo_por_producto', 12)->default(100);
            $table->unsignedSmallInteger('dias_solicitud_cambio')->default(12);
            $table->unsignedSmallInteger('dias_gestion_devolucion')->default(20);
            $table->unsignedSmallInteger('dias_vigencia_vale')->default(90);
            $table->unsignedSmallInteger('dias_maximos_recoleccion')->default(5);
            $table->char('moneda', 3)->default('MXN');
            $table->string('zona_horaria', 60)->default('America/Mexico_City');
            $table->string('mercado_pago_account_id', 190)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['distribuidora_id', 'id'], 'uq_configuraciones_distribuidora_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuraciones_distribuidora');
    }
};
