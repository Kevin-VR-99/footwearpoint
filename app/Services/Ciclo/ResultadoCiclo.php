<?php

namespace App\Services\Ciclo;

use App\Models\CicloCompra;
use Illuminate\Support\Collection;

final class ResultadoCiclo
{
    /**
     * @param Collection<int, \App\Models\Pedido> $pedidos
     * @param array<int, array<string, mixed>> $consolidado
     */
    public function __construct(
        public readonly CicloCompra $ciclo,
        public readonly Collection $pedidos,
        public readonly array $consolidado,
    ) {
    }
}