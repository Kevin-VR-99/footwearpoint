<?php

namespace App\Services\Ia;

/**
 * TG-238 (A8) — Lo que devolvió la IA por un bloque de páginas.
 *
 * @param  array<int, array<string, mixed>>  $productos
 */
class BloqueDeCatalogo
{
    public function __construct(
        public array $productos,
        public UsoDeTokens $uso,
    ) {
    }
}
