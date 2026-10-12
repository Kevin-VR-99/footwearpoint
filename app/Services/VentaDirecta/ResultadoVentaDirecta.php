<?php

namespace App\Services\VentaDirecta;

use App\Models\Pago;
use App\Models\Vale;
use App\Models\VentaDirecta;

final class ResultadoVentaDirecta
{
    /**
     * TG-250: si un vale cubre todo el total no hay pago ($pago null).
     *
     * @param array<int, \App\Models\VentaDirectaDetalle> $detalles
     */
    public function __construct(
        public readonly VentaDirecta $venta,
        public readonly array $detalles,
        public readonly ?Pago $pago,
        public readonly ?Vale $vale = null,
        public readonly float $montoVale = 0.0,
    ) {
    }

    /** Lo que se cobró con dinero (total menos el vale). */
    public function porCobrar(): float
    {
        return round((float) $this->venta->total - $this->montoVale, 2);
    }
}
