<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-209 (Ola 2, K2) — Paso 2 de 6: disponibilidad e imágenes.
 *
 * La disponibilidad la define el catálogo (la fábrica) y es igual para todas
 * las distribuidoras (D2); la foto también es del catálogo. Las dos pierden
 * distribuidora_id.
 *
 * Sus llaves se vuelven a poner, ya simples, en el último paso: las tablas a
 * las que apuntan todavía se van a mover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disponibilidad_variante_campana', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_disponibilidad_variante__1');
            $tabla->dropForeign('fk_disponibilidad_variante__2');
            $tabla->dropForeign('fk_disponibilidad_variante__3');
            $tabla->dropUnique('uq_disponibilidad_variante_campana_tenant_id');
            $tabla->dropUnique('uq_disp_variante_campana');
            $tabla->dropIndex('fk_disponibilidad_variante__3');
            $tabla->dropColumn('distribuidora_id');

            $tabla->unique(['producto_campana_id', 'variante_id'], 'uq_disp_variante_campana');
        });

        Schema::table('producto_imagenes', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_producto_imagenes_1');
            $tabla->dropForeign('fk_producto_imagenes_2');
            $tabla->dropUnique('uq_producto_imagenes_tenant_id');
            $tabla->dropIndex('ix_imagen_producto_campana');
            $tabla->dropColumn('distribuidora_id');

            $tabla->index(['producto_campana_id', 'orden'], 'ix_imagen_producto_campana');
        });
    }

    public function down(): void
    {
        Schema::table('producto_imagenes', function (Blueprint $tabla) {
            $tabla->dropIndex('ix_imagen_producto_campana');
            $tabla->unsignedBigInteger('distribuidora_id')->after('id');
            $tabla->unique(['distribuidora_id', 'id'], 'uq_producto_imagenes_tenant_id');
            $tabla->index(['distribuidora_id', 'producto_campana_id', 'orden'], 'ix_imagen_producto_campana');
        });

        Schema::table('disponibilidad_variante_campana', function (Blueprint $tabla) {
            $tabla->dropUnique('uq_disp_variante_campana');
            $tabla->unsignedBigInteger('distribuidora_id')->after('id');
            $tabla->unique(['distribuidora_id', 'id'], 'uq_disponibilidad_variante_campana_tenant_id');
            $tabla->unique(['distribuidora_id', 'producto_campana_id', 'variante_id'], 'uq_disp_variante_campana');
            $tabla->index(['distribuidora_id', 'variante_id'], 'fk_disponibilidad_variante__3');
        });
    }
};
