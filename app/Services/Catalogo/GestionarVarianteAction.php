<?php

namespace App\Services\Catalogo;

use App\Models\Color;
use App\Models\Producto;
use App\Models\Talla;
use App\Models\Variante;

class GestionarVarianteAction
{
    /**
     * DECISIÓN PROVISIONAL MÍA: ningún documento especifica un formato
     * exacto de SKU, solo dice "generar sku automático". Uso
     * MODELO-TALLA-COLOR (todo en mayúsculas, sin espacios ni símbolos),
     * por ejemplo "AM90-27-NEGRO". Confirma con el equipo si prefieren
     * otro formato (por ejemplo, con el nombre de la distribuidora o un
     * consecutivo numérico).
     */
    public function crear(array $datos): Variante
    {
        $producto = Producto::findOrFail($datos['producto_id']);
        $talla = Talla::findOrFail($datos['talla_id']);
        $color = Color::findOrFail($datos['color_id']);

        $sku = $this->generarSkuUnico($producto, $talla, $color);

        return Variante::create([
            'producto_id'             => $producto->id,
            'talla_id'                => $talla->id,
            'color_id'                => $color->id,
            'nombre_color_comercial'  => $datos['nombre_color_comercial'] ?? null,
            'sku'                     => $sku,
            'activa'                  => $datos['activa'] ?? true,
        ]);
    }

    public function actualizar(Variante $variante, array $datos): Variante
    {
        // producto_id/talla_id/color_id/sku nunca llegan aquí (el Form
        // Request no los permite en edición).
        $variante->fill($datos);
        $variante->save();

        return $variante->fresh();
    }

    private function generarSkuUnico(Producto $producto, Talla $talla, Color $color): string
    {
        $base = $this->normalizar($producto->modelo) . '-' . $this->normalizar($talla->valor) . '-' . $this->normalizar($color->nombre);

        // Salvaguarda: el SKU se deriva de la combinación producto+talla+
        // color, que ya es única por su propia restricción
        // (uq_variante_combinacion) — pero si dos combinaciones distintas
        // normalizaran al mismo texto (caso raro), se agrega un sufijo
        // numérico para no romper uq_variante_tenant_sku.
        $sku = $base;
        $intento = 1;

        while (Variante::where('sku', $sku)->exists()) {
            $intento++;
            $sku = "{$base}-{$intento}";
        }

        return $sku;
    }

    private function normalizar(string $texto): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $texto));
    }
}
