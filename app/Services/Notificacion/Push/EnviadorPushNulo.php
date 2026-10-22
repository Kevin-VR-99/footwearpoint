<?php

namespace App\Services\Notificacion\Push;

use Illuminate\Support\Facades\Log;

/**
 * Se usa cuando el servidor no tiene credenciales de Firebase. No manda nada
 * y lo deja dicho en el log (una vez por proceso), en lugar de fallar en cada
 * aviso con un error difícil de leer. Los avisos siguen quedando en la
 * bandeja de la app; solo falta el push.
 */
class EnviadorPushNulo implements EnviadorPush
{
    private static bool $avisado = false;

    public function enviar(array $tokens, string $titulo, string $mensaje, array $datos = []): array
    {
        if (! self::$avisado) {
            self::$avisado = true;
            Log::warning('Push desactivado: faltan las credenciales de Firebase (FIREBASE_CREDENTIALS o FIREBASE_CREDENTIALS_BASE64). El aviso quedó solo en la bandeja de la app.', [
                'celulares' => count($tokens),
                'tipo'      => $datos['tipo'] ?? null,
            ]);
        }

        return [];
    }

    /** Para las pruebas. */
    public static function reiniciar(): void
    {
        self::$avisado = false;
    }
}
