<?php

namespace App\Services\Reembolso;

use RuntimeException;

/** Falla de Mercado Pago que vale la pena reintentar (sin respuesta, 5xx, 429). */
class ReembolsoTemporalException extends RuntimeException
{
}
