<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TG-209 (Ola 2, K2) — Paso 3 de 6: el producto dentro de la temporada.
 *
 * Lo que cambia (sección 4.1):
 *
 *  - precio_minorista_sugerido pasa a llamarse precio_publico: es EL precio
 *    de menudeo del catálogo, igual para todas las distribuidoras (D8);
 *  - se va precio_mayorista: los catálogos de fábrica solo traen un precio, y
 *    el mayoreo lo pone cada distribuidora (Ola 2, ofertas_distribuidora);
 *  - se va publicado: publicar u ocultar pasa a ser decisión de cada
 *    distribuidora, en su propia capa;
 *  - se va estado_disponibilidad: la disponibilidad se sabe por variante;
 *  - entra activo: el admin general puede retirar un producto de la temporada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producto_campana', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_producto_campana_1');
            $tabla->dropForeign('fk_producto_campana_2');
            $tabla->dropForeign('fk_producto_campana_3');
            $tabla->dropUnique('uq_producto_campana_tenant_id');
            $tabla->dropUnique('uq_codigo_campana');
            $tabla->dropUnique('uq_producto_campana');
            $tabla->dropColumn(['distribuidora_id', 'precio_mayorista', 'publicado', 'estado_disponibilidad']);
        });

        Schema::table('producto_campana', function (Blueprint $tabla) {
            $tabla->renameColumn('precio_minorista_sugerido', 'precio_publico');
            $tabla->boolean('activo')->default(true)->after('codigo_catalogo');

            $tabla->unique(['campana_id', 'producto_id'], 'uq_producto_campana');
            $tabla->unique(['campana_id', 'codigo_catalogo'], 'uq_codigo_campana');
        });
    }

    public function down(): void
    {
        Schema::table('producto_campana', function (Blueprint $tabla) {
            $tabla->dropUnique('uq_producto_campana');
            $tabla->dropUnique('uq_codigo_campana');
            $tabla->renameColumn('precio_publico', 'precio_minorista_sugerido');
            $tabla->dropColumn('activo');
        });

        Schema::table('producto_campana', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('distribuidora_id')->after('id');
            $tabla->decimal('precio_mayorista', 12, 2)->after('codigo_catalogo');
            $tabla->enum('estado_disponibilidad', ['disponible', 'bajo_pedido', 'no_disponible'])
                ->default('bajo_pedido')->after('precio_minorista_sugerido');
            $tabla->boolean('publicado')->default(false)->after('estado_disponibilidad');

            $tabla->unique(['distribuidora_id', 'id'], 'uq_producto_campana_tenant_id');
            $tabla->unique(['distribuidora_id', 'campana_id', 'codigo_catalogo'], 'uq_codigo_campana');
            $tabla->unique(['distribuidora_id', 'producto_id', 'campana_id'], 'uq_producto_campana');
        });
    }
};
