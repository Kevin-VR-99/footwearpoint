<?php

namespace App\Support;

/**
 * ¿Tiene el servidor credenciales de Firebase? Acepta:
 *  - FIREBASE_CREDENTIALS (o GOOGLE_APPLICATION_CREDENTIALS): ruta al JSON
 *    de la cuenta de servicio, o el JSON mismo en una línea.
 *  - FIREBASE_CREDENTIALS_BASE64: el JSON en base64 (cómodo en Railway). Se
 *    decodifica en memoria y nunca se escribe a disco ni al log.
 */
class CredencialesFirebase
{
    public static function clave(): string
    {
        return 'firebase.projects.'.config('firebase.default', 'app').'.credentials';
    }

    /** Si viene en base64 y no hay otra, la pasa a la configuración de kreait. */
    public static function aplicarBase64(): void
    {
        $base64 = config('services.firebase.credenciales_base64');

        if (! filled($base64) || filled(config(self::clave()))) {
            return;
        }

        $json = base64_decode((string) $base64, true);
        $datos = $json !== false ? json_decode($json, true) : null;

        if (is_array($datos) && isset($datos['private_key'], $datos['client_email'])) {
            config([self::clave() => $datos]);
        }
    }

    public static function configuradas(): bool
    {
        self::aplicarBase64();
        $credenciales = config(self::clave());

        if (is_array($credenciales)) {
            return $credenciales !== [];
        }

        if (! filled($credenciales)) {
            return false;
        }

        $credenciales = (string) $credenciales;

        // JSON en línea o una ruta que exista.
        return str_starts_with(ltrim($credenciales), '{') || is_file($credenciales) || is_file(base_path($credenciales));
    }
}
