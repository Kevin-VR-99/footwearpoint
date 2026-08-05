<?php

namespace App\Services\VentaDirecta;

use App\Models\Pago;
use App\Models\VentaDirecta;

final class ResultadoVentaDirecta
{
    /**
     * @param array<int, \App\Models\VentaDirectaDetalle> $detalles
     */
    public function __construct(
        public readonly VentaDirecta $venta,
        public readonly array $detalles,
        public readonly Pago $pago,
    ) {
    }
}