<?php

namespace App\Services\Catalogo;

use App\Exceptions\OperacionInvalidaException;
use App\Models\DistribuidoraLinea;
use App\Models\OfertaDistribuidora;
use App\Models\ProductoCampana;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué parte del catálogo compartido ve una distribuidora (regla 4.4, TG-213).
 *
 * El catálogo es uno solo, pero cada distribuidora vende solo un pedazo:
 *
 *   - productos de temporadas ACTIVAS,
 *   - de las LÍNEAS que tiene activas (K3),
 *   - que el admin general no haya retirado de la temporada (activo),
 *   - y que ella misma no haya ocultado (su oferta, K5).
 *
 * Es el único lugar donde se arma esa consulta: la usan el catálogo de la
 * app, los pedidos, el punto de venta y la tienda pública, para que todos
 * muestren y acepten exactamente lo mismo.
 */
class CatalogoVisible
{
    /** La consulta base de productos de temporada que esta distribuidora vende. */
    public function consulta(): Builder
    {
        return ProductoCampana::query()
            ->where('activo', true)
            ->whereHas('campana', fn (Builder $campana) => $campana
                ->where('estado', 'activa')
                ->whereIn('linea_id', DistribuidoraLinea::query()->vigentes()->select('linea_id'))
            )
            ->whereNotIn(
                'producto_campana.id',
                OfertaDistribuidora::query()->where('publicado', false)->select('producto_campana_id')
            );
    }

    public function puedeVender(int $productoCampanaId): bool
    {
        return $this->consulta()->whereKey($productoCampanaId)->exists();
    }

    /**
     * El producto, si esta distribuidora puede venderlo. Si no, explica por qué
     * con el mismo mensaje para "no existe" y "no lo vendes": desde fuera no
     * tiene por qué saberse qué productos hay en el catálogo de los demás.
     */
    public function productoQueVende(int $productoCampanaId): ProductoCampana
    {
        $producto = $this->consulta()->whereKey($productoCampanaId)->first();

        if (! $producto) {
            throw new OperacionInvalidaException(
                'Ese producto no está disponible en el catálogo de tu distribuidora.',
                422
            );
        }

        return $producto;
    }
}
