<?php

namespace App\Exceptions;

use Exception;

/**
 * TG-246 — El empleado no tiene el permiso de módulo que pide la acción
 * (cerrar ciclo, reportes financieros, ...). El mensaje ya va en español
 * para mostrarse tal cual con MensajeError::paraUsuario().
 */
class SinPermisoModuloException extends Exception implements MensajeParaUsuario
{
    public function __construct(string $message = 'No tienes permiso para realizar esta acción. Pídeselo al administrador de tu distribuidora.')
    {
        parent::__construct($message);
    }
}
