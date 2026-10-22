<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario físico: una sesión de conteo (de un modelo o de todo) con lo
 * esperado al iniciar y lo contado. Al aplicar, las diferencias se vuelven
 * ajustes de stock (MovimientoStock) en un solo paso. Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conteos_inventario', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->unsignedBigInteger('distribuidora_id');
            $tabla->unsignedBigInteger('sucursal_id');
            $tabla->unsignedBigInteger('producto_id')->nullable()->comment('Nulo = todo el inventario.');
            $tabla->enum('estado', ['abierto', 'aplicado', 'cancelado'])->default('abierto');
            $tabla->unsignedBigInteger('creado_por_staff_id');
            $tabla->unsignedBigInteger('aplicado_por_staff_id')->nullable();
            $tabla->dateTime('aplicado_at')->nullable();
            $tabla->string('notas', 500)->nullable();
            $tabla->timestamps();

            $tabla->unique(['distribuidora_id', 'id'], 'uq_conteos_tenant_id');
            $tabla->index(['distribuidora_id', 'estado'], 'ix_conteos_estado');
            $tabla->foreign('distribuidora_id', 'fk_conteos_1')->references('id')->on('distribuidoras')->onDelete('cascade');
            $tabla->foreign(['distribuidora_id', 'sucursal_id'], 'fk_conteos_2')->references(['distribuidora_id', 'id'])->on('sucursales');
            $tabla->foreign('creado_por_staff_id', 'fk_conteos_3')->references('id')->on('distribuidora_staff');
            $tabla->foreign('aplicado_por_staff_id', 'fk_conteos_4')->references('id')->on('distribuidora_staff');
        });

        Schema::create('conteos_inventario_renglones', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->unsignedBigInteger('distribuidora_id');
            $tabla->unsignedBigInteger('conteo_id');
            $tabla->unsignedBigInteger('variante_id');
            $tabla->unsignedInteger('esperado')->default(0);
            $tabla->unsignedInteger('contado')->nullable()->comment('Nulo = no se contó; no se ajusta.');
            $tabla->integer('ajuste_aplicado')->nullable();
            $tabla->timestamps();

            $tabla->unique(['conteo_id', 'variante_id'], 'uq_conteo_variante');
            $tabla->foreign(['distribuidora_id', 'conteo_id'], 'fk_conteo_renglon_1')->references(['distribuidora_id', 'id'])->on('conteos_inventario')->onDelete('cascade');
            $tabla->foreign('variante_id', 'fk_conteo_renglon_2')->references('id')->on('variantes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conteos_inventario_renglones');
        Schema::dropIfExists('conteos_inventario');
    }
};
