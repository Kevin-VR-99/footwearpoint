<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\DistribuidoraLinea;
use App\Models\OfertaDistribuidora;
use App\Models\ProductoCampana;
use App\Support\Tenant;

/**
 * Lo que la distribuidora cambia de un producto del catálogo (TG-212, E4-14).
 *
 * Cuatro cosas, con las mismas reglas de por medio: ponerle precio de mayoreo
 * propio, quitárselo (vuelve a su descuento general), ocultarlo y volver a
 * mostrarlo.
 *
 * Solo puede tocar productos de líneas que tenga activas (K3): el resto del
 * catálogo ni lo vende ni le corresponde.
 *
 * Si al final no quedó nada distinto de lo normal (sin precio propio y
 * visible), la fila se borra: así la tabla solo guarda lo que de verdad cambió.
 */
class GestionarOfertaDistribuidoraAction
{
    public function ponerPrecioMayorista(int $productoCampanaId, float $precio): OfertaDistribuidora
    {
        $productoCampana = $this->productoQuePuedeTocar($productoCampanaId);
        $menudeo = round((float) $productoCampana->precio_publico, 2);
        $precio = round($precio, 2);

        if ($precio <= 0) {
            throw new OperacionInvalidaException('El precio de mayoreo tiene que ser mayor que cero.', 422);
        }

        if ($precio > $menudeo) {
            throw new OperacionInvalidaException(
                'El precio de mayoreo no puede ser mayor que el precio de catálogo ($'
                .number_format($menudeo, 2).').',
                422
            );
        }

        return $this->guardar($productoCampana->id, ['precio_mayorista' => $precio]);
    }

    /** Vuelve a usar el descuento general de la distribuidora. */
    public function quitarPrecioMayorista(int $productoCampanaId): ?OfertaDistribuidora
    {
        $productoCampana = $this->productoQuePuedeTocar($productoCampanaId);

        return $this->guardar($productoCampana->id, ['precio_mayorista' => null]);
    }

    public function ocultar(int $productoCampanaId): OfertaDistribuidora
    {
        $productoCampana = $this->productoQuePuedeTocar($productoCampanaId);

        return $this->guardar($productoCampana->id, ['publicado' => false]);
    }

    public function mostrar(int $productoCampanaId): ?OfertaDistribuidora
    {
        $productoCampana = $this->productoQuePuedeTocar($productoCampanaId);

        return $this->guardar($productoCampana->id, ['publicado' => true]);
    }

    /**
     * Guarda el cambio sobre la fila de ese producto, creándola si hacía falta,
     * y la borra si ya no dice nada distinto de lo normal.
     */
    private function guardar(int $productoCampanaId, array $cambios): ?OfertaDistribuidora
    {
        $oferta = OfertaDistribuidora::firstOrNew([
            'producto_campana_id' => $productoCampanaId,
        ]);

        $oferta->fill($cambios);

        if ($oferta->exists && $oferta->esIgualALoNormal()) {
            $oferta->delete();

            return null;
        }

        if (! $oferta->exists && $oferta->esIgualALoNormal()) {
            // No hay nada que guardar: así ya se comporta sin fila.
            return null;
        }

        $oferta->distribuidora_id = $oferta->distribuidora_id ?? Tenant::id();
        $oferta->save();

        return $oferta->fresh();
    }

    /**
     * El producto tiene que existir y ser de una línea que la distribuidora
     * tenga activa.
     */
    private function productoQuePuedeTocar(int $productoCampanaId): ProductoCampana
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $productoCampana = ProductoCampana::with('campana')->find($productoCampanaId);

        if (! $productoCampana) {
            throw new OperacionInvalidaException('Ese producto no existe en el catálogo.', 404);
        }

        $vendeEsaLinea = DistribuidoraLinea::query()
            ->vigentes()
            ->where('linea_id', $productoCampana->campana->linea_id)
            ->exists();

        if (! $vendeEsaLinea) {
            throw new OperacionInvalidaException(
                'Ese producto es de una línea que tu distribuidora no tiene activa.',
                409
            );
        }

        return $productoCampana;
    }
}
