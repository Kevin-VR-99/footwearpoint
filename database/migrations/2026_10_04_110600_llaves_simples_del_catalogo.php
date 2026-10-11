<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-209 (Ola 2, K2) — Paso 6 de 6: las llaves, ahora simples.
 *
 * Ya que el catálogo es uno solo, nada necesita la distribuidora para
 * identificar un producto, una variante o una temporada: basta el id.
 *
 * Las tablas de operación (stock, pedidos, ventas y destacados) conservan su
 * distribuidora_id, porque el stock y los pedidos sí son de cada
 * distribuidora (sección 4.3); lo único que cambia es cómo apuntan al
 * catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dentro del catálogo
        Schema::table('campanas', function (Blueprint $tabla) {
            $tabla->foreign('linea_id', 'fk_campanas_linea')
                ->references('id')->on('lineas')->onUpdate('restrict')->onDelete('restrict');
        });

        Schema::table('linea_marca', function (Blueprint $tabla) {
            $tabla->foreign('linea_id', 'fk_linea_marca_linea')
                ->references('id')->on('lineas')->onUpdate('restrict')->onDelete('cascade');
            $tabla->foreign('marca_id', 'fk_linea_marca_marca')
                ->references('id')->on('marcas')->onUpdate('restrict')->onDelete('cascade');
        });

        Schema::table('productos', function (Blueprint $tabla) {
            $tabla->foreign('marca_id', 'fk_productos_marca')
                ->references('id')->on('marcas')->onUpdate('restrict')->onDelete('restrict');
            $tabla->foreign('categoria_id', 'fk_productos_categoria')
                ->references('id')->on('categorias_producto')->onUpdate('restrict')->onDelete('restrict');
        });

        Schema::table('variantes', function (Blueprint $tabla) {
            $tabla->foreign('producto_id', 'fk_variantes_producto')
                ->references('id')->on('productos')->onUpdate('restrict')->onDelete('cascade');
        });

        Schema::table('producto_campana', function (Blueprint $tabla) {
            $tabla->foreign('producto_id', 'fk_producto_campana_producto')
                ->references('id')->on('productos')->onUpdate('restrict')->onDelete('cascade');
            $tabla->foreign('campana_id', 'fk_producto_campana_campana')
                ->references('id')->on('campanas')->onUpdate('restrict')->onDelete('cascade');
        });

        Schema::table('disponibilidad_variante_campana', function (Blueprint $tabla) {
            $tabla->foreign('producto_campana_id', 'fk_disponibilidad_producto_campana')
                ->references('id')->on('producto_campana')->onUpdate('restrict')->onDelete('cascade');
            $tabla->foreign('variante_id', 'fk_disponibilidad_variante')
                ->references('id')->on('variantes')->onUpdate('restrict')->onDelete('cascade');
        });

        Schema::table('producto_imagenes', function (Blueprint $tabla) {
            $tabla->foreign('producto_campana_id', 'fk_producto_imagenes_producto_campana')
                ->references('id')->on('producto_campana')->onUpdate('restrict')->onDelete('cascade');
        });

        // Operación: siguen siendo de su distribuidora, pero ya apuntan al
        // catálogo compartido.
        Schema::table('stock_local', function (Blueprint $tabla) {
            $tabla->foreign('variante_id', 'fk_stock_local_variante')
                ->references('id')->on('variantes')->onUpdate('restrict')->onDelete('restrict');
        });

        Schema::table('pedido_detalle', function (Blueprint $tabla) {
            $tabla->foreign('producto_campana_id', 'fk_pedido_detalle_producto_campana')
                ->references('id')->on('producto_campana')->onUpdate('restrict')->onDelete('restrict');
            $tabla->foreign('variante_id', 'fk_pedido_detalle_variante')
                ->references('id')->on('variantes')->onUpdate('restrict')->onDelete('restrict');
        });

        Schema::table('venta_directa_detalle', function (Blueprint $tabla) {
            $tabla->foreign('producto_campana_id', 'fk_venta_directa_detalle_producto_campana')
                ->references('id')->on('producto_campana')->onUpdate('restrict')->onDelete('restrict');
            $tabla->foreign('variante_id', 'fk_venta_directa_detalle_variante')
                ->references('id')->on('variantes')->onUpdate('restrict')->onDelete('restrict');
        });

        Schema::table('productos_destacados', function (Blueprint $tabla) {
            $tabla->foreign('producto_campana_id', 'fk_productos_destacados_producto_campana')
                ->references('id')->on('producto_campana')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('productos_destacados', fn (Blueprint $t) => $t->dropForeign('fk_productos_destacados_producto_campana'));

        Schema::table('venta_directa_detalle', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_venta_directa_detalle_producto_campana');
            $tabla->dropForeign('fk_venta_directa_detalle_variante');
        });

        Schema::table('pedido_detalle', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_pedido_detalle_producto_campana');
            $tabla->dropForeign('fk_pedido_detalle_variante');
        });

        Schema::table('stock_local', fn (Blueprint $t) => $t->dropForeign('fk_stock_local_variante'));
        Schema::table('producto_imagenes', fn (Blueprint $t) => $t->dropForeign('fk_producto_imagenes_producto_campana'));

        Schema::table('disponibilidad_variante_campana', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_disponibilidad_producto_campana');
            $tabla->dropForeign('fk_disponibilidad_variante');
        });

        Schema::table('producto_campana', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_producto_campana_producto');
            $tabla->dropForeign('fk_producto_campana_campana');
        });

        Schema::table('variantes', fn (Blueprint $t) => $t->dropForeign('fk_variantes_producto'));

        Schema::table('productos', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_productos_marca');
            $tabla->dropForeign('fk_productos_categoria');
        });

        Schema::table('linea_marca', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_linea_marca_linea');
            $tabla->dropForeign('fk_linea_marca_marca');
        });

        Schema::table('campanas', fn (Blueprint $t) => $t->dropForeign('fk_campanas_linea'));
    }
};
