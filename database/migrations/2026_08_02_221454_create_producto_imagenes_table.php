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
        Schema::create('producto_imagenes', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('producto_campana_id');
            $table->string('url', 500);
            $table->unsignedSmallInteger('orden')->default(1);
            $table->boolean('es_principal')->default(false);
            $table->dateTime('created_at')->useCurrent();

            $table->index(['distribuidora_id', 'producto_campana_id', 'orden'], 'ix_imagen_producto_campana');
            $table->unique(['distribuidora_id', 'id'], 'uq_producto_imagenes_tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producto_imagenes');
    }
};
