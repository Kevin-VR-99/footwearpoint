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
        Schema::create('variantes', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('Identificador interno.');
            $table->unsignedBigInteger('distribuidora_id')->comment('Tenant propietario del registro.');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedBigInteger('talla_id')->index('fk_variantes_3');
            $table->unsignedBigInteger('color_id')->index('fk_variantes_4');
            $table->string('nombre_color_comercial', 100)->nullable();
            $table->string('sku', 120);
            $table->boolean('activa')->default(true);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['distribuidora_id', 'id'], 'uq_variantes_tenant_id');
            $table->unique(['distribuidora_id', 'producto_id', 'talla_id', 'color_id'], 'uq_variante_combinacion');
            $table->unique(['distribuidora_id', 'sku'], 'uq_variante_tenant_sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('variantes');
    }
};
