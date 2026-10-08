<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Password;

/**
 * Pedir el enlace para restablecer la contraseña (TG-141).
 *
 * Decisión del equipo, por seguridad: la respuesta es SIEMPRE la misma,
 * exista o no el correo. Si el sistema contestara distinto, cualquiera podría
 * probar correos uno por uno y averiguar quién tiene cuenta (enumeración de
 * usuarios).
 *
 * Por eso aquí se ignora a propósito el resultado del broker. Son tres casos
 * y los tres se ven igual desde fuera:
 *   - RESET_LINK_SENT  -> el correo existe y se mandó el enlace.
 *   - INVALID_USER     -> el correo no existe.
 *   - RESET_THROTTLED  -> se pidió hace muy poco. Este también delataría la
 *                         cuenta, porque a un correo inexistente nunca se le
 *                         pide esperar.
 *
 * La usan la API (app móvil) y la pantalla web, para que las dos digan
 * exactamente lo mismo.
 */
class EnviarEnlaceRecuperacionAction
{
    public const MENSAJE = 'Si el correo pertenece a una cuenta, te llegará un enlace para restablecer tu contraseña.';

    public function ejecutar(string $email): string
    {
        Password::broker('users')->sendResetLink(['email' => $email]);

        return self::MENSAJE;
    }
}
