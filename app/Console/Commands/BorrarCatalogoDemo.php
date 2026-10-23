<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan catalogo:borrar-demo [--confirmar]
 *
 * Quita del catálogo lo que dejó el seeder de demostración y deja solo lo que
 * entró por la importación con IA (productos_importados_staging).
 *
 * - Un producto de temporada es "demo" si ninguna importación lo creó.
 * - Si un pedido o una venta lo usa, NO se borra: se desactiva, para no
 *   romper el historial. Igual con el producto y sus tallas.
 * - Sin --confirmar solo enseña lo que haría. Se puede correr otra vez.
 */
class BorrarCatalogoDemo extends Command
{
    protected $signature = 'catalogo:borrar-demo {--confirmar : Borra de verdad; sin esto solo enseña lo que haría}';

    protected $description = 'Borra los productos del catálogo de demostración y deja solo los importados con IA';

    public function handle(): int
    {
        $confirmar = (bool) $this->option('confirmar');

        $deIa = DB::table('productos_importados_staging')->whereNotNull('producto_campana_creado_id')->pluck('producto_campana_creado_id');
        $productosDeIa = DB::table('productos_importados_staging')->whereNotNull('producto_creado_id')->pluck('producto_creado_id');

        $demo = DB::table('producto_campana as pc')
            ->join('productos as p', 'p.id', '=', 'pc.producto_id')
            ->whereNotIn('pc.id', $deIa)
            ->orderBy('pc.id')
            ->get(['pc.id', 'pc.producto_id', 'pc.codigo_catalogo', 'pc.activo', 'p.nombre']);

        $resumen = ['borrados' => 0, 'desactivados' => 0, 'productos_borrados' => 0, 'productos_desactivados' => 0, 'imagenes' => 0];

        $hacer = function () use ($demo, $productosDeIa, &$resumen, $confirmar) {
            foreach ($demo as $pc) {
                $usado = DB::table('pedido_detalle')->where('producto_campana_id', $pc->id)->exists()
                    || DB::table('venta_directa_detalle')->where('producto_campana_id', $pc->id)->exists();

                if ($usado) {
                    $this->line("DESACTIVA #{$pc->id} {$pc->codigo_catalogo} {$pc->nombre} (tiene pedidos o ventas)");
                    $resumen['desactivados']++;
                    if ($confirmar) {
                        DB::table('producto_campana')->where('id', $pc->id)->update(['activo' => false, 'updated_at' => now()]);
                        DB::table('productos_destacados')->where('producto_campana_id', $pc->id)->delete();
                    }

                    continue;
                }

                $this->line("BORRA #{$pc->id} {$pc->codigo_catalogo} {$pc->nombre}");
                $resumen['borrados']++;
                $resumen['imagenes'] += DB::table('producto_imagenes')->where('producto_campana_id', $pc->id)->count();

                if ($confirmar) {
                    foreach (['producto_imagenes', 'disponibilidad_variante_campana', 'ofertas_distribuidora', 'productos_destacados'] as $tabla) {
                        DB::table($tabla)->where('producto_campana_id', $pc->id)->delete();
                    }
                    DB::table('producto_campana')->where('id', $pc->id)->delete();
                }
            }

            // El producto (modelo) se va solo si ya no está en ninguna
            // temporada, no lo creó la IA y ninguna venta usa sus tallas.
            $productos = $demo->pluck('producto_id')->unique()->diff($productosDeIa);

            foreach ($productos as $productoId) {
                $sigueEnTemporada = DB::table('producto_campana')->where('producto_id', $productoId)
                    ->when(! $confirmar, fn ($q) => $q->whereNotIn('id', $demo->pluck('id')))
                    ->exists();
                $variantes = DB::table('variantes')->where('producto_id', $productoId)->pluck('id');
                $vendido = DB::table('pedido_detalle')->whereIn('variante_id', $variantes)->exists()
                    || DB::table('venta_directa_detalle')->whereIn('variante_id', $variantes)->exists();

                if ($vendido || ($confirmar && $sigueEnTemporada)) {
                    $resumen['productos_desactivados']++;
                    if ($confirmar) {
                        DB::table('productos')->where('id', $productoId)->update(['activo' => false, 'updated_at' => now()]);
                    }

                    continue;
                }

                if ($sigueEnTemporada && ! $confirmar) {
                    continue;
                }

                $resumen['productos_borrados']++;
                if ($confirmar) {
                    foreach (['stock_local', 'conteos_inventario_renglones', 'disponibilidad_variante_campana'] as $tabla) {
                        DB::table($tabla)->whereIn('variante_id', $variantes)->delete();
                    }
                    DB::table('variantes')->where('producto_id', $productoId)->delete();
                    DB::table('productos')->where('id', $productoId)->delete();
                }
            }
        };

        $confirmar ? DB::transaction($hacer) : $hacer();

        $this->info(($confirmar ? 'Hecho' : 'Simulación (usa --confirmar para borrar)').': '
            ."{$resumen['borrados']} productos de temporada borrados, {$resumen['desactivados']} desactivados por tener pedidos, "
            ."{$resumen['productos_borrados']} modelos borrados, {$resumen['productos_desactivados']} modelos desactivados, "
            ."{$resumen['imagenes']} fotos quitadas.");

        return self::SUCCESS;
    }
}