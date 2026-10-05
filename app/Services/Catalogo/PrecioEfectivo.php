<?php

namespace App\Services\Catalogo;

use App\Models\ConfiguracionDistribuidora;
use App\Models\OfertaDistribuidora;
use App\Models\ProductoCampana;

/**
 * Con qué precio se vende un producto en ESTA distribuidora (TG-212, D8).
 *
 * El único lugar del proyecto donde se calcula un precio. En K6 lo van a usar
 * el catálogo de la app, los pedidos, el punto de venta y la tienda pública,
 * para que todos digan lo mismo.
 *
 * Son dos precios:
 *
 *   - MENUDEO: el precio_publico del catálogo. Es el mismo para todas las
 *     distribuidoras y ninguna lo cambia. Lo paga el cliente directo.
 *   - MAYOREO: lo paga el cliente mayorista (el revendedor). Si la
 *     distribuidora le puso precio propio a ese producto, ese; si no, el de
 *     menudeo menos su descuento general.
 */
class PrecioEfectivo
{
    /** @var array<int, float|null> precio propio por producto de temporada, ya consultado */
    private array $preciosPropios = [];

    private ?float $descuento = null;

    public function menudeo(ProductoCampana $productoCampana): float
    {
        return round((float) $productoCampana->precio_publico, 2);
    }

    public function mayoreo(ProductoCampana $productoCampana): float
    {
        $propio = $this->precioPropio((int) $productoCampana->id);

        if ($propio !== null) {
            return round($propio, 2);
        }

        return $this->conDescuento($this->menudeo($productoCampana));
    }

    /** @return array{menudeo: float, mayoreo: float} */
    public function ambos(ProductoCampana $productoCampana): array
    {
        return [
            'menudeo' => $this->menudeo($productoCampana),
            'mayoreo' => $this->mayoreo($productoCampana),
        ];
    }

    /**
     * Para listas largas (el catálogo): deja consultados de una sola vez los
     * precios propios de todos esos productos, en lugar de uno por uno.
     *
     * @param  iterable<ProductoCampana>|array<int>  $productos
     */
    public function precargar(iterable $productos): static
    {
        $ids = [];

        foreach ($productos as $producto) {
            $id = $producto instanceof ProductoCampana ? (int) $producto->id : (int) $producto;

            if (! array_key_exists($id, $this->preciosPropios)) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return $this;
        }

        $ofertas = OfertaDistribuidora::whereIn('producto_campana_id', $ids)
            ->pluck('precio_mayorista', 'producto_campana_id');

        foreach ($ids as $id) {
            $precio = $ofertas[$id] ?? null;
            $this->preciosPropios[$id] = $precio === null ? null : (float) $precio;
        }

        return $this;
    }

    /** El descuento general de la distribuidora, en porcentaje. */
    public function descuentoMayorista(): float
    {
        if ($this->descuento === null) {
            $configurado = (float) (ConfiguracionDistribuidora::query()->value('descuento_mayorista_pct') ?? 0);

            // La columna acepta cualquier número; aquí no se dejan pasar
            // descuentos que darían precios negativos o más caros que el
            // menudeo. El campo se valida al guardarlo (FijarDescuentoMayoristaAction).
            $this->descuento = max(0.0, min(99.99, $configurado));
        }

        return $this->descuento;
    }

    /** Vuelve a leer configuración y ofertas (después de cambiarlas). */
    public function olvidarLoConsultado(): void
    {
        $this->preciosPropios = [];
        $this->descuento = null;
    }

    private function conDescuento(float $precio): float
    {
        return round($precio * (1 - ($this->descuentoMayorista() / 100)), 2);
    }

    private function precioPropio(int $productoCampanaId): ?float
    {
        if (! array_key_exists($productoCampanaId, $this->preciosPropios)) {
            $this->precargar([$productoCampanaId]);
        }

        return $this->preciosPropios[$productoCampanaId];
    }
}
