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
        Schema::create('productos_importados_staging', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('importacion_id');
            $table->json('datos_extraidos');
            $table->json('campos_dudosos')->nullable();
            $table->enum('estado', ['pendiente', 'corregido', 'aprobado', 'descartado'])->default('pendiente');
            $table->unsignedBigInteger('producto_creado_id')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['distribuidora_id', 'importacion_id'], 'fk_productos_importados_sta_2');
            $table->index(['distribuidora_id', 'producto_creado_id'], 'fk_productos_importados_sta_3');
            $table->unique(['distribuidora_id', 'id'], 'uq_productos_importados_staging_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos_importados_staging');
    }
};
