<?php

namespace App\Services\Ia;

use RuntimeException;

/**
 * La IA se quedó sin espacio de respuesta (stop_reason = max_tokens) y el
 * JSON llegó cortado. No es un error del catálogo: el bloque traía más
 * productos de los que caben. Quien lee el catálogo parte el bloque en dos y
 * lo vuelve a pedir. Trae lo que se gastó, porque esa vuelta sí se paga.
 */
class RespuestaCortadaPorTokens extends RuntimeException
{
    public function __construct(public int $desde, public int $hasta, public UsoDeTokens $uso)
    {
        parent::__construct("La respuesta de la IA para las páginas {$desde} a {$hasta} no cupo completa.");
    }
}
