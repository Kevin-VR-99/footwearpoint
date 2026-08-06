<?php

namespace App\Services\Catalogo;

use App\Exceptions\OperacionInvalidaException;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\ProductoCampana;
use App\Models\Variante;

class GestionarDisponibilidadVarianteCampanaAction
{
    /**
     * DECISIÓN PROVISIONAL MÍA: valido que la variante pertenezca al mismo
     * producto que la publicación (producto_campana) — no tendría sentido
     * fijar disponibilidad de una variante de OTRO producto dentro de esta
     * publicación. No viene escrito así en ningún documento. Confirma con
     * el equipo.
     */
    public function crear(array $datos): DisponibilidadVarianteCampana
    {
        $productoCampana = ProductoCampana::findOrFail($datos['producto_campana_id']);
        $variante = Variante::findOrFail($datos['variante_id']);

        if ($variante->producto_id !== $productoCampana->producto_id) {
            throw new OperacionInvalidaException(
                'La variante debe pertenecer al mismo producto que la publicación (producto_campana).',
                409
            );
        }

        return DisponibilidadVarianteCampana::create([
            'producto_campana_id' => $productoCampana->id,
            'variante_id'         => $variante->id,
            'estado'              => $datos['estado'],
            'fecha_verificacion'  => now(),
        ]);
    }

    public function actualizar(DisponibilidadVarianteCampana $disponibilidad, array $datos): DisponibilidadVarianteCampana
    {
        $disponibilidad->estado = $datos['estado'];
        $disponibilidad->fecha_verificacion = now();
        $disponibilidad->save();

        return $disponibilidad->fresh();
    }
}
