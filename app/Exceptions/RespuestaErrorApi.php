<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * TG-224 (G3) — Respuesta JSON de error para todo lo que está bajo /api.
 *
 * Mantiene el formato de la sección 1.7 del contrato ({"message": "..."})
 * con mensajes en español y nunca expone el detalle técnico (excepción,
 * archivo, línea, trace, SQL), ni siquiera con APP_DEBUG=true. El detalle
 * lo sigue reportando Laravel al log antes de llegar aquí.
 *
 * Se registra en bootstrap/app.php con $exceptions->render(...).
 */
final class RespuestaErrorApi
{
    public const NO_AUTENTICADO = 'Tu sesión no es válida o expiró. Inicia sesión de nuevo.';

    public const SIN_PERMISO = 'No tienes permiso para realizar esta acción.';

    public const NO_ENCONTRADO = 'No se encontró lo que buscas.';

    public const METODO_NO_PERMITIDO = 'Esta acción no está disponible.';

    public const SESION_EXPIRADA = 'Tu sesión expiró. Recarga la página e intenta de nuevo.';

    public const DEMASIADOS_INTENTOS = 'Demasiados intentos. Espera un momento e intenta de nuevo.';

    public const SOLICITUD_INVALIDA = 'No se pudo procesar la solicitud.';

    public const NO_DISPONIBLE = 'El servicio no está disponible por el momento. Intenta más tarde.';

    public const ERROR_SERVIDOR = 'Ocurrió un error en el servidor. Intenta de nuevo más tarde.';

    /**
     * Devuelve null cuando Laravel debe seguir con su respuesta normal
     * (rutas web, validación 422, respuestas ya armadas).
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        // 422 con "errors" por campo y respuestas ya construidas: se respetan.
        if ($e instanceof ValidationException || $e instanceof HttpResponseException) {
            return null;
        }

        if ($e instanceof AuthenticationException) {
            return self::json(self::NO_AUTENTICADO, 401);
        }

        if ($e instanceof HttpExceptionInterface) {
            $estado = $e->getStatusCode();

            return self::json(self::mensajeHttp($estado, $e->getMessage()), $estado, $e->getHeaders());
        }

        // Cualquier otra cosa es un error inesperado del servidor.
        return self::json(self::ERROR_SERVIDOR, 500);
    }

    private static function mensajeHttp(int $estado, string $mensaje): string
    {
        return match (true) {
            // Los 403 traen mensajes en español: los abort(403, '...') del
            // código y los que bootstrap/app.php traduce (Spatie, policies).
            $estado === 403 => $mensaje !== '' ? $mensaje : self::SIN_PERMISO,
            $estado === 401 => self::NO_AUTENTICADO,
            $estado === 404 => self::NO_ENCONTRADO,
            $estado === 405 => self::METODO_NO_PERMITIDO,
            $estado === 419 => self::SESION_EXPIRADA,
            $estado === 429 => self::DEMASIADOS_INTENTOS,
            $estado === 503 => self::NO_DISPONIBLE,
            $estado >= 500 => self::ERROR_SERVIDOR,
            default => self::SOLICITUD_INVALIDA,
        };
    }

    private static function json(string $mensaje, int $estado, array $headers = []): JsonResponse
    {
        return response()->json(['message' => $mensaje], $estado, $headers);
    }
}
