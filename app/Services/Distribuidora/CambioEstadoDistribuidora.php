<?php

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;

/**
 * TG-196 (G5) — Resultado de aprobar, rechazar, suspender o reactivar una
 * distribuidora: cómo quedó y si se le pudo avisar por correo.
 *
 * El cambio de estado nunca depende del correo: si el aviso falla, el
 * cambio se queda y quien lo hizo ve que no se pudo avisar.
 */
final class CambioEstadoDistribuidora
{
    public function __construct(
        public readonly Distribuidora $distribuidora,
        public readonly bool $avisoEnviado,
    ) {
    }
}
