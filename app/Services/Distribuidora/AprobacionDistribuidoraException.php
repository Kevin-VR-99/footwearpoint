<?php

namespace App\Services\Distribuidora;

use App\Exceptions\MensajeParaUsuario;
use RuntimeException;

/**
 * TG-194 (G2) — Por qué no se pudo aprobar una distribuidora.
 *
 * El panel y la API responden cada uno con su propio mensaje y código, así
 * que la acción solo dice el motivo y cada quien decide cómo mostrarlo.
 */
class AprobacionDistribuidoraException extends RuntimeException implements MensajeParaUsuario
{
    public const NO_PENDIENTE = 'no_pendiente';

    public const SIN_PLAN = 'sin_plan';

    private function __construct(public readonly string $motivo, string $mensaje)
    {
        parent::__construct($mensaje);
    }

    public static function noPendiente(): self
    {
        return new self(self::NO_PENDIENTE, 'Solo se pueden aprobar distribuidoras pendientes.');
    }

    public static function sinPlan(): self
    {
        return new self(self::SIN_PLAN, 'No hay planes de suscripción configurados.');
    }

    public function esNoPendiente(): bool
    {
        return $this->motivo === self::NO_PENDIENTE;
    }
}
