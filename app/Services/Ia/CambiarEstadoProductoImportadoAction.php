<?php

namespace App\Services\Ia;

use App\Models\ProductoImportadoStaging;
use InvalidArgumentException;

/**
 * TG-239 (A9) — Descartar un producto propuesto, o recuperarlo.
 *
 * La IA a veces propone cosas que no son producto: una foto de ambiente que
 * parecía un zapato, el mismo modelo repetido en dos páginas, un artículo que
 * no es calzado. Descartarlo lo deja fuera sin borrarlo, para que se pueda
 * recuperar si alguien se equivocó y para que quede el rastro de lo que la IA
 * propuso de más.
 */
class CambiarEstadoProductoImportadoAction
{
    public function __construct(private CorregirProductoImportadoAction $correccion)
    {
    }

    public function descartar(ProductoImportadoStaging $fila): void
    {
        $this->cambiar($fila, 'descartado');
    }

    /** Vuelve a "pendiente": hay que revisarlo otra vez desde cero. */
    public function recuperar(ProductoImportadoStaging $fila): void
    {
        $this->cambiar($fila, 'pendiente');
    }

    private function cambiar(ProductoImportadoStaging $fila, string $estado): void
    {
        if (! in_array($estado, ['pendiente', 'descartado'], true)) {
            throw new InvalidArgumentException("Estado no válido: {$estado}.");
        }

        $fila->update(['estado' => $estado]);

        $this->correccion->anotarQuienRevisa($fila);
    }
}
