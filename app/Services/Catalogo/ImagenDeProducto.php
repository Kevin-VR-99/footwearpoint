<?php

namespace App\Services\Catalogo;

use App\Models\ProductoImagen;
use Illuminate\Support\Collection;

/**
 * La foto vive en la temporada (producto_campana), no en la talla.
 * Si hay varias, gana la principal; si no, la de menor orden.
 */
class ImagenDeProducto
{
    /**
     * @param  iterable<int|string|null>  $productoCampanaIds
     * @return array<int, string> producto_campana_id => url
     */
    public function porProductoCampana(iterable $productoCampanaIds): array
    {
        $ids = $this->ids($productoCampanaIds);

        if ($ids->isEmpty()) {
            return [];
        }

        $filas = ProductoImagen::query()
            ->whereIn('producto_campana_id', $ids)
            ->orderByDesc('es_principal')
            ->orderBy('orden')
            ->orderBy('id')
            ->get(['producto_campana_id', 'url']);

        return $this->primeraUrl($filas, 'producto_campana_id');
    }

    /**
     * Una foto por modelo, para existencias: el stock es de la talla, no de la temporada.
     *
     * @param  iterable<int|string|null>  $productoIds
     * @return array<int, string> producto_id => url
     */
    public function porProducto(iterable $productoIds): array
    {
        $ids = $this->ids($productoIds);

        if ($ids->isEmpty()) {
            return [];
        }

        $filas = ProductoImagen::query()
            ->join('producto_campana', 'producto_campana.id', '=', 'producto_imagenes.producto_campana_id')
            ->whereIn('producto_campana.producto_id', $ids)
            ->orderByDesc('producto_imagenes.es_principal')
            ->orderBy('producto_imagenes.orden')
            ->orderBy('producto_imagenes.id')
            ->get(['producto_campana.producto_id as producto_id', 'producto_imagenes.url as url']);

        return $this->primeraUrl($filas, 'producto_id');
    }

    /**
     * @param  iterable<int|string|null>  $ids
     * @return Collection<int, int>
     */
    private function ids(iterable $ids): Collection
    {
        return collect($ids)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, object>  $filas
     * @return array<int, string>
     */
    private function primeraUrl(Collection $filas, string $llave): array
    {
        $mapa = [];

        foreach ($filas as $fila) {
            $id = (int) $fila->{$llave};
            $url = trim((string) $fila->url);

            if ($url === '' || isset($mapa[$id])) {
                continue;
            }

            $mapa[$id] = $url;
        }

        return $mapa;
    }
}
