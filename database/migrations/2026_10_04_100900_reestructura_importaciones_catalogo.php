<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-208 (Ola 1, A6 a A9) — Las importaciones con IA pasan a ser del admin
 * general.
 *
 * El catálogo va a ser uno solo, compartido, que administra el admin general
 * (Ola 2). Por eso quien importa un catálogo con IA ya no es una
 * distribuidora:
 *
 *   - se quita distribuidora_id de las dos tablas;
 *   - iniciada_por / revisada_por dejan de apuntar a distribuidora_staff y
 *     apuntan a usuarios, porque el admin general no es staff de ninguna
 *     distribuidora;
 *   - se agrega a qué línea y a qué temporada va el catálogo importado;
 *   - se agrega lo que cuesta cada importación: modelo de IA, páginas, tokens
 *     y dólares, para poder medirlo.
 *
 * Esto se puede hacer ahora, en la ola que "solo agrega", porque estas dos
 * tablas todavía no las usa ningún código ni tienen datos: solo existían sus
 * modelos. Así Ailton empieza la IA con la forma definitiva y no se rehace
 * nada en la Ola 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Primero se sueltan las llaves de staging: una de ellas apunta a la
        // llave doble de importaciones_catalogo, que se va a quitar.
        Schema::table('productos_importados_staging', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_productos_importados_sta_1');
            $tabla->dropForeign('fk_productos_importados_sta_2');
            $tabla->dropForeign('fk_productos_importados_sta_3');
            $tabla->dropUnique('uq_productos_importados_staging_tenant_id');
            $tabla->dropIndex('fk_productos_importados_sta_2');
            $tabla->dropIndex('fk_productos_importados_sta_3');
            $tabla->dropColumn('distribuidora_id');
        });

        Schema::table('importaciones_catalogo', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_importaciones_catalogo_1');
            $tabla->dropForeign('fk_importaciones_catalogo_2');
            $tabla->dropForeign('fk_importaciones_catalogo_3');
            $tabla->dropUnique('uq_importaciones_catalogo_tenant_id');
            $tabla->dropIndex('fk_importaciones_catalogo_2');
            $tabla->dropIndex('fk_importaciones_catalogo_3');
            $tabla->dropColumn(['distribuidora_id', 'iniciada_por_staff_id', 'revisada_por_staff_id']);
        });

        Schema::table('importaciones_catalogo', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('linea_id')->after('id');
            $tabla->unsignedBigInteger('campana_id')->nullable()->after('linea_id');
            $tabla->unsignedBigInteger('iniciada_por_usuario_id')->after('estado');
            $tabla->unsignedBigInteger('revisada_por_usuario_id')->nullable()->after('iniciada_por_usuario_id');

            // Lo que costó la importación (A9): se llena conforme se procesa.
            $tabla->string('modelo_ia', 80)->nullable()->after('proveedor_ia');
            $tabla->unsignedInteger('paginas')->nullable()->after('modelo_ia');
            $tabla->unsignedInteger('tokens_entrada')->nullable()->after('paginas');
            $tabla->unsignedInteger('tokens_salida')->nullable()->after('tokens_entrada');
            $tabla->decimal('costo_usd', 10, 4)->nullable()->after('tokens_salida');

            $tabla->foreign('linea_id', 'fk_importaciones_catalogo_linea')
                ->references('id')->on('lineas')->onUpdate('restrict')->onDelete('restrict');

            $tabla->foreign('campana_id', 'fk_importaciones_catalogo_campana')
                ->references('id')->on('campanas')->onUpdate('restrict')->onDelete('restrict');

            $tabla->foreign('iniciada_por_usuario_id', 'fk_importaciones_catalogo_iniciada')
                ->references('id')->on('usuarios')->onUpdate('restrict')->onDelete('restrict');

            $tabla->foreign('revisada_por_usuario_id', 'fk_importaciones_catalogo_revisada')
                ->references('id')->on('usuarios')->onUpdate('restrict')->onDelete('restrict');
        });

        Schema::table('productos_importados_staging', function (Blueprint $tabla) {
            // Qué producto de temporada se creó a partir de esta fila, además
            // del producto: un catálogo importado crea los dos (A8, A9).
            $tabla->unsignedBigInteger('producto_campana_creado_id')->nullable()->after('producto_creado_id');

            $tabla->foreign('importacion_id', 'fk_productos_importados_sta_importacion')
                ->references('id')->on('importaciones_catalogo')->onUpdate('restrict')->onDelete('cascade');

            $tabla->foreign('producto_creado_id', 'fk_productos_importados_sta_producto')
                ->references('id')->on('productos')->onUpdate('restrict')->onDelete('set null');

            $tabla->foreign('producto_campana_creado_id', 'fk_productos_importados_sta_producto_campana')
                ->references('id')->on('producto_campana')->onUpdate('restrict')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('productos_importados_staging', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_productos_importados_sta_importacion');
            $tabla->dropForeign('fk_productos_importados_sta_producto');
            $tabla->dropForeign('fk_productos_importados_sta_producto_campana');
            $tabla->dropColumn('producto_campana_creado_id');
        });

        Schema::table('importaciones_catalogo', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_importaciones_catalogo_linea');
            $tabla->dropForeign('fk_importaciones_catalogo_campana');
            $tabla->dropForeign('fk_importaciones_catalogo_iniciada');
            $tabla->dropForeign('fk_importaciones_catalogo_revisada');
            $tabla->dropColumn([
                'linea_id',
                'campana_id',
                'iniciada_por_usuario_id',
                'revisada_por_usuario_id',
                'modelo_ia',
                'paginas',
                'tokens_entrada',
                'tokens_salida',
                'costo_usd',
            ]);
        });

        Schema::table('importaciones_catalogo', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('distribuidora_id')->after('id');
            $tabla->unsignedBigInteger('iniciada_por_staff_id')->after('estado');
            $tabla->unsignedBigInteger('revisada_por_staff_id')->nullable()->after('iniciada_por_staff_id');

            $tabla->unique(['distribuidora_id', 'id'], 'uq_importaciones_catalogo_tenant_id');
            $tabla->index(['distribuidora_id', 'iniciada_por_staff_id'], 'fk_importaciones_catalogo_2');
            $tabla->index(['distribuidora_id', 'revisada_por_staff_id'], 'fk_importaciones_catalogo_3');

            $tabla->foreign('distribuidora_id', 'fk_importaciones_catalogo_1')
                ->references('id')->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');

            $tabla->foreign(['distribuidora_id', 'iniciada_por_staff_id'], 'fk_importaciones_catalogo_2')
                ->references(['distribuidora_id', 'id'])->on('distribuidora_staff')
                ->onUpdate('restrict')->onDelete('restrict');

            $tabla->foreign(['distribuidora_id', 'revisada_por_staff_id'], 'fk_importaciones_catalogo_3')
                ->references(['distribuidora_id', 'id'])->on('distribuidora_staff')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        Schema::table('productos_importados_staging', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('distribuidora_id')->after('id');

            $tabla->unique(['distribuidora_id', 'id'], 'uq_productos_importados_staging_tenant_id');
            $tabla->index(['distribuidora_id', 'importacion_id'], 'fk_productos_importados_sta_2');
            $tabla->index(['distribuidora_id', 'producto_creado_id'], 'fk_productos_importados_sta_3');

            $tabla->foreign('distribuidora_id', 'fk_productos_importados_sta_1')
                ->references('id')->on('distribuidoras')->onUpdate('restrict')->onDelete('cascade');

            $tabla->foreign(['distribuidora_id', 'importacion_id'], 'fk_productos_importados_sta_2')
                ->references(['distribuidora_id', 'id'])->on('importaciones_catalogo')
                ->onUpdate('restrict')->onDelete('cascade');

            $tabla->foreign(['distribuidora_id', 'producto_creado_id'], 'fk_productos_importados_sta_3')
                ->references(['distribuidora_id', 'id'])->on('productos')
                ->onUpdate('restrict')->onDelete('restrict');
        });
    }
};
