<?php

namespace App\Services\Catalogo;

use App\Models\DistribuidoraLinea;
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
 *     menudeo menos el descuento de esa línea. Si la línea no tiene el suyo,
 *     el descuento es 0.
 */
class PrecioEfectivo
{
    /** @var array<int, float|null> precio propio por producto de temporada, ya consultado */
    private array $preciosPropios = [];

    /** @var array<int, float|null>|null porcentaje por línea activa; null en el valor = sin descuento */
    private ?array $descuentosPorLinea = null;

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

        return $this->conDescuento($this->menudeo($productoCampana), $this->lineaId($productoCampana));
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

    /** Ya no hay descuento general. Si la línea no tiene el suyo, es 0. */
    public function descuentoMayorista(): float
    {
        return 0.0;
    }

    /** Vuelve a leer configuración y ofertas (después de cambiarlas). */
    public function olvidarLoConsultado(): void
    {
        $this->preciosPropios = [];
        $this->descuentosPorLinea = null;
    }

    /** El porcentaje de esa línea, o 0 si no tiene el suyo. */
    public function descuentoParaLinea(?int $lineaId): float
    {
        if ($lineaId !== null && array_key_exists($lineaId, $this->descuentosPorLinea()) && $this->descuentosPorLinea()[$lineaId] !== null) {
            return max(0.0, min(99.99, (float) $this->descuentosPorLinea()[$lineaId]));
        }

        return $this->descuentoMayorista();
    }

    private function conDescuento(float $precio, ?int $lineaId = null): float
    {
        return round($precio * (1 - ($this->descuentoParaLinea($lineaId) / 100)), 2);
    }

    private function lineaId(ProductoCampana $productoCampana): ?int
    {
        $lineaId = $productoCampana->campana?->linea_id;

        return $lineaId === null ? null : (int) $lineaId;
    }

    /** @return array<int, float|null> */
    private function descuentosPorLinea(): array
    {
        if ($this->descuentosPorLinea === null) {
            $this->descuentosPorLinea = DistribuidoraLinea::query()
                ->vigentes()
                ->pluck('descuento_mayorista_pct', 'linea_id')
                ->map(fn ($valor) => $valor === null ? null : (float) $valor)
                ->all();
        }

        return $this->descuentosPorLinea;
    }

    private function precioPropio(int $productoCampanaId): ?float
    {
        if (! array_key_exists($productoCampanaId, $this->preciosPropios)) {
            $this->precargar([$productoCampanaId]);
        }

        return $this->preciosPropios[$productoCampanaId];
    }
}
