<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-208 (Ola 1, G16) — Categorías del directorio público.
 *
 * Son las etiquetas con las que se busca una distribuidora en el directorio
 * (por ejemplo "Calzado infantil" o "Dama"). No tienen nada que ver con
 * categorias_producto, que clasifica el calzado dentro del catálogo.
 *
 * Las administra el admin general, por eso no llevan distribuidora_id. Una
 * distribuidora puede estar en varias, de ahí la tabla puente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_directorio', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('nombre', 120)->unique('uq_categoria_directorio_nombre');
            $tabla->boolean('activa')->default(true);
            $tabla->dateTime('created_at')->useCurrent();
            $tabla->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('distribuidora_categoria_directorio', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('distribuidora_id');
            $tabla->unsignedBigInteger('categoria_directorio_id');

            $tabla->primary(['distribuidora_id', 'categoria_directorio_id'], 'pk_distribuidora_categoria_directorio');

            $tabla->foreign('distribuidora_id', 'fk_distribuidora_categoria_dir_1')
                ->references('id')
                ->on('distribuidoras')
                ->onUpdate('restrict')
                ->onDelete('cascade');

            $tabla->foreign('categoria_directorio_id', 'fk_distribuidora_categoria_dir_2')
                ->references('id')
                ->on('categorias_directorio')
                ->onUpdate('restrict')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribuidora_categoria_directorio');
        Schema::dropIfExists('categorias_directorio');
    }
};
