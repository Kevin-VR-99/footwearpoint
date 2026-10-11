<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TG-209 (Ola 2, K2) — Paso 1 de 6: revisar los datos y soltar las llaves.
 *
 * El catálogo deja de ser de cada distribuidora y pasa a ser uno solo
 * (sección 4.1 del diseño). Antes de mover nada:
 *
 * 1. Se revisa que los datos que haya encajen en la forma nueva. Si no
 *    encajan, la migración se detiene y dice exactamente qué pasa. No se
 *    borra nada: el diseño dice que estos datos no se migran (D6), así que lo
 *    que toca es volver a construir la base con migrate:fresh --seed.
 *
 * 2. Se sueltan las llaves que apuntan al catálogo desde fuera (pedidos,
 *    ventas, stock y destacados). Sin esto no se pueden quitar los índices
 *    dobles de los que esas llaves cuelgan. Se vuelven a poner, ya simples,
 *    en el último paso.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->revisarQueLosDatosEncajen();

        Schema::table('pedido_detalle', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_pedido_detalle_3');
            $tabla->dropForeign('fk_pedido_detalle_4');
        });

        Schema::table('venta_directa_detalle', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_venta_directa_detalle_4');
            $tabla->dropForeign('fk_venta_directa_detalle_5');
        });

        Schema::table('stock_local', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_stock_local_3');
        });

        Schema::table('productos_destacados', function (Blueprint $tabla) {
            $tabla->dropForeign('fk_productos_destacados_2');
        });
    }

    public function down(): void
    {
        Schema::table('productos_destacados', function (Blueprint $tabla) {
            $tabla->foreign(['distribuidora_id', 'producto_campana_id'], 'fk_productos_destacados_2')
                ->references(['distribuidora_id', 'id'])->on('producto_campana')
                ->onUpdate('restrict')->onDelete('cascade');
        });

        Schema::table('stock_local', function (Blueprint $tabla) {
            $tabla->foreign(['distribuidora_id', 'variante_id'], 'fk_stock_local_3')
                ->references(['distribuidora_id', 'id'])->on('variantes')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        Schema::table('venta_directa_detalle', function (Blueprint $tabla) {
            $tabla->foreign(['distribuidora_id', 'producto_campana_id'], 'fk_venta_directa_detalle_4')
                ->references(['distribuidora_id', 'id'])->on('producto_campana')
                ->onUpdate('restrict')->onDelete('restrict');

            $tabla->foreign(['distribuidora_id', 'variante_id'], 'fk_venta_directa_detalle_5')
                ->references(['distribuidora_id', 'id'])->on('variantes')
                ->onUpdate('restrict')->onDelete('restrict');
        });

        Schema::table('pedido_detalle', function (Blueprint $tabla) {
            $tabla->foreign(['distribuidora_id', 'producto_campana_id'], 'fk_pedido_detalle_3')
                ->references(['distribuidora_id', 'id'])->on('producto_campana')
                ->onUpdate('restrict')->onDelete('restrict');

            $tabla->foreign(['distribuidora_id', 'variante_id'], 'fk_pedido_detalle_4')
                ->references(['distribuidora_id', 'id'])->on('variantes')
                ->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Los datos de hoy son de prueba y vienen de un catálogo por
     * distribuidora. En el catálogo compartido hay cosas que ya no caben: dos
     * distribuidoras con la misma marca, o una temporada repartida entre
     * varias líneas. Si aparece algo así, mejor detenerse que dejar la base a
     * medias.
     */
    private function revisarQueLosDatosEncajen(): void
    {
        $problemas = [];

        foreach (['lineas' => 'líneas', 'marcas' => 'marcas', 'categorias_producto' => 'categorías'] as $tabla => $nombre) {
            $repetidos = DB::table($tabla)
                ->select('nombre')
                ->groupBy('nombre')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('nombre');

            if ($repetidos->isNotEmpty()) {
                $problemas[] = "Hay $nombre con el mismo nombre en distintas distribuidoras: ".$repetidos->join(', ').'.';
            }
        }

        $skus = DB::table('variantes')->select('sku')->groupBy('sku')->havingRaw('COUNT(*) > 1')->count();
        if ($skus > 0) {
            $problemas[] = "Hay $skus SKU repetidos entre distribuidoras; en el catálogo compartido el SKU es único.";
        }

        $modelos = DB::table('productos')
            ->select('marca_id', 'modelo')
            ->groupBy('marca_id', 'modelo')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        if ($modelos > 0) {
            $problemas[] = "Hay $modelos modelos repetidos dentro de una misma marca.";
        }

        foreach ([['producto_id', 'producto'], ['codigo_catalogo', 'código de catálogo']] as [$columna, $nombre]) {
            $repetidos = DB::table('producto_campana')
                ->select('campana_id', $columna)
                ->groupBy('campana_id', $columna)
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($repetidos > 0) {
                $problemas[] = "Hay $repetidos temporadas con el mismo $nombre repetido.";
            }
        }

        // La temporada pasa a pertenecer a UNA línea (D1).
        $conVariasLineas = DB::table('lineas')
            ->select('campana_id')
            ->groupBy('campana_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        if ($conVariasLineas > 0) {
            $problemas[] = "Hay $conVariasLineas temporadas repartidas entre dos o más líneas; en el diseño nuevo cada temporada pertenece a una sola.";
        }

        $sinLinea = DB::table('campanas')
            ->whereNotIn('id', DB::table('lineas')->select('campana_id'))
            ->count();
        if ($sinLinea > 0) {
            $problemas[] = "Hay $sinLinea temporadas sin ninguna línea; en el diseño nuevo toda temporada pertenece a una línea.";
        }

        if ($problemas !== []) {
            throw new RuntimeException(
                "No se puede convertir el catálogo actual al catálogo compartido:\n - "
                .implode("\n - ", $problemas)
                ."\n\nEstos datos son de prueba y no se migran (decisión D6 del diseño)."
                ."\nCorre:  php artisan migrate:fresh --seed"
            );
        }
    }
};
