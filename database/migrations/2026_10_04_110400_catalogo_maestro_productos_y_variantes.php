<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-209 (Ola 2, K2) — Paso 4 de 6: productos y variantes.
 *
 * El producto deja de amarrarse a una línea (D5): el mismo modelo puede salir
 * en los catálogos de dos líneas, cada uno con su código. La línea se sabe por
 * la temporada en la que está publicado.
 *
 * Un producto es un modelo en un color, como viene en los catálogos (D9), y
 * sus variantes son las tallas. Por eso el modelo es único dentro de la marca
 * y el SKU es único en todo el sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variantes', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_variantes_1');
            $tabla->dropForeign('fk_variantes_2');
            $tabla->dropUnique('uq_variantes_tenant_id');
            $tabla->dropUnique('uq_variante_combinacion');
            $tabla->dropUnique('uq_variante_tenant_sku');
            $tabla->dropColumn('distribuidora_id');

            $tabla->unique(['producto_id', 'talla_id', 'color_id'], 'uq_variante_combinacion');
            $tabla->unique('sku', 'uq_variante_sku');
        });

        Schema::table('productos', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_productos_1');
            $tabla->dropForeign('fk_productos_2');
            $tabla->dropForeign('fk_productos_3');
            $tabla->dropUnique('uq_productos_tenant_id');
            $tabla->dropUnique('uq_producto_tenant_marca_modelo');
            $tabla->dropIndex('fk_productos_3');
            $tabla->dropColumn(['distribuidora_id', 'linea_id']);

            $tabla->unique(['marca_id', 'modelo'], 'uq_producto_marca_modelo');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $tabla) {
            $tabla->dropUnique('uq_producto_marca_modelo');
            $tabla->unsignedBigInteger('distribuidora_id')->after('id');
            $tabla->unsignedBigInteger('linea_id')->nullable()->after('marca_id');

            $tabla->unique(['distribuidora_id', 'id'], 'uq_productos_tenant_id');
            $tabla->unique(['distribuidora_id', 'marca_id', 'modelo'], 'uq_producto_tenant_marca_modelo');
            $tabla->index(['distribuidora_id', 'categoria_id'], 'fk_productos_3');
        });

        Schema::table('variantes', function (Blueprint $tabla) {
            $tabla->dropUnique('uq_variante_combinacion');
            $tabla->dropUnique('uq_variante_sku');
            $tabla->unsignedBigInteger('distribuidora_id')->after('id');

            $tabla->unique(['distribuidora_id', 'id'], 'uq_variantes_tenant_id');
            $tabla->unique(['distribuidora_id', 'producto_id', 'talla_id', 'color_id'], 'uq_variante_combinacion');
            $tabla->unique(['distribuidora_id', 'sku'], 'uq_variante_tenant_sku');
        });
    }
};
