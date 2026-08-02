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
        Schema::create('importaciones_catalogo', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->string('archivo_url', 500);
            $table->enum('tipo_archivo', ['pdf', 'imagen', 'fotografia', 'otro']);
            $table->string('proveedor_ia', 80)->nullable();
            $table->enum('estado', ['cargado', 'procesando', 'requiere_revision', 'aprobado', 'rechazado', 'error'])->default('cargado');
            $table->unsignedBigInteger('iniciada_por_staff_id');
            $table->unsignedBigInteger('revisada_por_staff_id')->nullable();
            $table->text('mensaje_error')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index(['distribuidora_id', 'iniciada_por_staff_id'], 'fk_importaciones_catalogo_2');
            $table->index(['distribuidora_id', 'revisada_por_staff_id'], 'fk_importaciones_catalogo_3');
            $table->unique(['distribuidora_id', 'id'], 'uq_importaciones_catalogo_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('importaciones_catalogo');
    }
};
