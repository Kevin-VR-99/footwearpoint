<?php

namespace App\Support;

use App\Exceptions\MensajeParaUsuario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Throwable;

/**
 * TG-224 (G3) — Convierte una excepción en un mensaje que se puede mostrar.
 *
 * Regla: el usuario nunca ve el error técnico; el detalle solo va al log.
 * Los componentes Livewire lo usan en su catch (\Throwable):
 *
 *     $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo enviar el pedido.');
 */
final class MensajeError
{
    public const GENERICO = 'Ocurrió un error inesperado. Intenta de nuevo.';

    public const NO_ENCONTRADO = 'No se encontró el registro. Recarga la página e intenta de nuevo.';

    public const SIN_PERMISO = 'No tienes permiso para realizar esta acción.';

    public static function paraUsuario(Throwable $e, string $generico = self::GENERICO): string
    {
        // Mensajes de validación: ya están escritos para el usuario.
        if ($e instanceof ValidationException) {
            $primero = collect($e->errors())->flatten()->first();

            return is_string($primero) && $primero !== '' ? $primero : $generico;
        }

        // Excepciones de negocio con mensaje curado (OperacionInvalidaException, ...).
        if ($e instanceof MensajeParaUsuario) {
            return $e->getMessage() !== '' ? $e->getMessage() : $generico;
        }

        // Situaciones esperables (registro borrado, sin permiso): no son fallas
        // del sistema, así que no se reportan, pero tampoco se muestra el
        // mensaje en inglés con el nombre del modelo.
        if ($e instanceof ModelNotFoundException) {
            return self::NO_ENCONTRADO;
        }

        if ($e instanceof AuthorizationException || $e instanceof UnauthorizedException) {
            return self::SIN_PERMISO;
        }

        // Todo lo demás es inesperado: el detalle va al log.
        report($e);

        return $generico;
    }
}
