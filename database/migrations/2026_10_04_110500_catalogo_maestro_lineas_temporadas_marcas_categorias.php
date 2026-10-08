<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TG-209 (Ola 2, K2) — Paso 5 de 6: líneas, temporadas, marcas y categorías.
 *
 * Aquí se invierte la relación más importante del catálogo (D1):
 *
 *   ANTES: la línea pertenecía a una temporada (lineas.campana_id).
 *   AHORA: la temporada pertenece a una línea (campanas.linea_id), porque
 *          cada línea tiene sus propias temporadas ("Impuls Otoño-Invierno
 *          2026"). Además, solo una temporada activa por línea (D7), que se
 *          valida en el modelo Campana.
 *
 * Los nombres pasan a ser únicos en todo el sistema: ya no puede existir
 * "Cklass" dos veces, una por distribuidora.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La línea de cada temporada se saca de la relación que había al
        // revés. Que cada temporada tenga exactamente una línea ya se revisó
        // en el primer paso de la Ola 2.
        Schema::table('campanas', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('linea_id')->nullable()->after('id');
        });

        DB::statement('UPDATE `campanas` c SET c.`linea_id` = (SELECT MIN(l.`id`) FROM `lineas` l WHERE l.`campana_id` = c.`id`)');

        DB::statement('ALTER TABLE `campanas` MODIFY `linea_id` BIGINT UNSIGNED NOT NULL');

        // Las llaves se sueltan antes que nada: cuelgan de los índices dobles
        // que se van a quitar (la de lineas apunta a campanas y las de
        // linea_marca a líneas y marcas).
        Schema::table('lineas', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_lineas_1');
            $tabla->dropForeign('fk_lineas_2');
        });

        Schema::table('linea_marca', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_linea_marca_1');
            $tabla->dropForeign('fk_linea_marca_2');
            $tabla->dropForeign('fk_linea_marca_3');
        });

        Schema::table('campanas', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_campanas_1');
            $tabla->dropForeign('fk_campanas_2');
            $tabla->dropUnique('uq_campanas_tenant_id');
            $tabla->dropUnique('uq_campana_tenant_nombre');
            $tabla->dropIndex('fk_campanas_2');
            $tabla->dropColumn(['distribuidora_id', 'marca_id']);

            $tabla->unique(['linea_id', 'nombre'], 'uq_campana_linea_nombre');
        });

        // Red de seguridad para D7: solo UNA temporada activa por línea.
        //
        // La columna se calcula sola: vale la línea cuando la temporada está
        // activa, y nulo cuando no. Como los nulos no cuentan para un índice
        // único, la base deja muchas temporadas no activas por línea pero solo
        // una activa, aunque alguien lo intente con un update masivo que no
        // pase por el modelo. El mensaje bonito lo da el modelo Campana.
        DB::statement(
            'ALTER TABLE `campanas` '
            ."ADD COLUMN `linea_activa_id` BIGINT UNSIGNED AS (IF(`estado` = 'activa', `linea_id`, NULL)) PERSISTENT, "
            .'ADD UNIQUE KEY `uq_campana_una_activa_por_linea` (`linea_activa_id`)'
        );

        Schema::table('lineas', function (Blueprint $tabla) {
            $tabla->dropUnique('uq_lineas_tenant_id');
            $tabla->dropUnique('uq_linea_tenant_campana_nombre');
            $tabla->dropColumn(['distribuidora_id', 'campana_id']);

            // Para la tienda pública y la página de FootwearPoint.
            $tabla->string('logotipo_url', 500)->nullable()->after('descripcion');
            $tabla->unique('nombre', 'uq_linea_nombre');
        });

        Schema::table('marcas', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_marcas_1');
            $tabla->dropUnique('uq_marcas_tenant_id');
            $tabla->dropUnique('uq_marca_tenant_nombre');
            $tabla->dropColumn('distribuidora_id');

            $tabla->unique('nombre', 'uq_marca_nombre');
        });

        Schema::table('categorias_producto', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_categorias_producto_1');
            $tabla->dropUnique('uq_categorias_producto_tenant_id');
            $tabla->dropUnique('uq_categoria_tenant_nombre');
            $tabla->dropColumn('distribuidora_id');

            $tabla->unique('nombre', 'uq_categoria_nombre');
        });

        // Qué marcas maneja cada línea.
        Schema::table('linea_marca', function (Blueprint $tabla) {
            $tabla->dropUnique('uq_linea_marca_tenant_id');
            $tabla->dropIndex('fk_linea_marca_2');
            $tabla->dropIndex('fk_linea_marca_3');
            $tabla->dropColumn('distribuidora_id');
        });
    }

    public function down(): void
    {
        Schema::table('linea_marca', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('distribuidora_id')->after('id');
            $tabla->unique(['distribuidora_id', 'id'], 'uq_linea_marca_tenant_id');
            $tabla->index(['distribuidora_id', 'linea_id'], 'fk_linea_marca_2');
            $tabla->index(['distribuidora_id', 'marca_id'], 'fk_linea_marca_3');
        });

        Schema::table('categorias_producto', function (Blueprint $tabla) {
            $tabla->dropUnique('uq_categoria_nombre');
            $tabla->unsignedBigInteger('distribuidora_id')->after('id');
            $tabla->unique(['distribuidora_id', 'id'], 'uq_categorias_producto_tenant_id');
            $tabla->unique(['distribuidora_id', 'nombre'], 'uq_categoria_tenant_nombre');
        });

        Schema::table('marcas', function (Blueprint $tabla) {
            $tabla->dropUnique('uq_marca_nombre');
            $tabla->unsignedBigInteger('distribuidora_id')->after('id');
            $tabla->unique(['distribuidora_id', 'id'], 'uq_marcas_tenant_id');
            $tabla->unique(['distribuidora_id', 'nombre'], 'uq_marca_tenant_nombre');
        });

        Schema::table('lineas', function (Blueprint $tabla) {
            $tabla->dropUnique('uq_linea_nombre');
            $tabla->dropColumn('logotipo_url');
            $tabla->unsignedBigInteger('distribuidora_id')->after('id');
            $tabla->unsignedBigInteger('campana_id')->after('distribuidora_id');
            $tabla->unique(['distribuidora_id', 'id'], 'uq_lineas_tenant_id');
            $tabla->unique(['distribuidora_id', 'campana_id', 'nombre'], 'uq_linea_tenant_campana_nombre');
        });

        DB::statement('ALTER TABLE `campanas` DROP KEY `uq_campana_una_activa_por_linea`, DROP COLUMN `linea_activa_id`');

        Schema::table('campanas', function (Blueprint $tabla) {
            $tabla->dropUnique('uq_campana_linea_nombre');
            $tabla->dropColumn('linea_id');
            $tabla->unsignedBigInteger('distribuidora_id')->after('id');
            $tabla->unsignedBigInteger('marca_id')->nullable()->after('distribuidora_id');
            $tabla->unique(['distribuidora_id', 'id'], 'uq_campanas_tenant_id');
            $tabla->unique(['distribuidora_id', 'nombre'], 'uq_campana_tenant_nombre');
            $tabla->index(['distribuidora_id', 'marca_id'], 'fk_campanas_2');
        });
    }
};
