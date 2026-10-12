<?php

namespace App\Services\Catalogo;

use App\Models\Campana;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;

/**
 * TG-247 — Qué datos dependen de una línea, marca, temporada o producto del
 * catálogo maestro, para decidir si se puede borrar.
 *
 * Los modelos del catálogo no usan SoftDeletes, así que borrar es definitivo:
 * si algo depende del registro, el borrado se bloquea y se sugiere
 * desactivarlo.
 *
 * Las tablas de operación (stock, pedidos, ventas, precios y destacados) son
 * de cada distribuidora. Aquí se cuentan con DB::table y no con sus modelos
 * porque hay que ver las de TODAS las distribuidoras sin depender del
 * TenantScope (Tenant::forzar solo fija UNA distribuidora). Solo se cuenta,
 * nunca se lee ni se modifica nada de ellas.
 */
class DependenciasCatalogo
{
    /** @return array<string,int> descripción => cantidad (solo las que tienen datos) */
    public function deMarca(Marca $marca): array
    {
        return $this->conDatos([
            'producto|productos' => $marca->productos()->count(),
        ]);
    }

    public function deLinea(Linea $linea): array
    {
        return $this->conDatos([
            'temporada|temporadas' => $linea->campanas()->count(),
            'distribuidora que la vende|distribuidoras que la venden' => DB::table('distribuidora_linea')->where('linea_id', $linea->id)->count(),
            'importación de catálogo|importaciones de catálogo' => DB::table('importaciones_catalogo')->where('linea_id', $linea->id)->count(),
        ]);
    }

    public function deCampana(Campana $campana): array
    {
        return $this->conDatos([
            'producto publicado|productos publicados' => $campana->productosCampana()->count(),
            'importación de catálogo|importaciones de catálogo' => DB::table('importaciones_catalogo')->where('campana_id', $campana->id)->count(),
        ]);
    }

    /**
     * Para un producto solo bloquea lo que es de las distribuidoras. Sus
     * variantes, publicaciones, disponibilidad e imágenes son del catálogo y
     * la base las borra junto con él (llaves con ON DELETE CASCADE).
     */
    public function deProducto(Producto $producto): array
    {
        $variantes = DB::table('variantes')->select('id')->where('producto_id', $producto->id);
        $publicaciones = DB::table('producto_campana')->select('id')->where('producto_id', $producto->id);

        $porDetalle = fn (string $tabla) => DB::table($tabla)
            ->where(fn ($q) => $q->whereIn('variante_id', $variantes)->orWhereIn('producto_campana_id', $publicaciones))
            ->count();

        return $this->conDatos([
            'registro de stock en distribuidoras|registros de stock en distribuidoras' => DB::table('stock_local')->whereIn('variante_id', $variantes)->count(),
            'partida de pedido|partidas de pedidos' => $porDetalle('pedido_detalle'),
            'partida de venta de mostrador|partidas de ventas de mostrador' => $porDetalle('venta_directa_detalle'),
            'precio propio de distribuidora|precios propios de distribuidoras' => DB::table('ofertas_distribuidora')->whereIn('producto_campana_id', $publicaciones)->count(),
            'producto destacado por distribuidora|productos destacados por distribuidoras' => DB::table('productos_destacados')->whereIn('producto_campana_id', $publicaciones)->count(),
        ]);
    }

    /** "12 productos y 1 temporada" */
    public function describir(array $dependencias): string
    {
        $partes = [];
        foreach ($dependencias as $clave => $cantidad) {
            [$singular, $plural] = explode('|', $clave);
            $partes[] = $cantidad . ' ' . ($cantidad === 1 ? $singular : $plural);
        }

        if (count($partes) <= 1) {
            return $partes[0] ?? '';
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes) . ' y ' . $ultima;
    }

    private function conDatos(array $conteos): array
    {
        return array_filter(array_map('intval', $conteos), fn (int $n) => $n > 0);
    }
}
