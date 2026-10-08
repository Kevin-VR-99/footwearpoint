<?php

namespace App\Services\Auth;

use App\Models\Usuario;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Restablecer la contraseña con el enlace que llegó por correo.
 *
 * La usan la API y la pantalla web, que antes tenían este mismo código
 * copiado dos veces.
 *
 * TG-142 (decisión del equipo): al restablecer se revocan TODOS los tokens de
 * Sanctum de la cuenta. Si alguien restablece es porque olvidó la contraseña o
 * sospecha que otra persona la conoce, y cualquier celular que ya tuviera la
 * sesión abierta tiene que volver a iniciar sesión. No se conserva ninguno:
 * el enlace del correo se usa sin sesión, así que no hay "dispositivo actual".
 *
 * Y cuando falla, el motivo NO se revela (mismo criterio que TG-141). Laravel
 * distingue "no existe un usuario con ese correo" de "el enlace no es válido":
 * con eso, cualquiera podía mandar correos con un enlace inventado y averiguar
 * quién tiene cuenta. Por eso aquí solo se devuelve si se pudo o no, y quien
 * llama muestra siempre MENSAJE_ENLACE_INVALIDO.
 */
class RestablecerPasswordAction
{
    public const MENSAJE_ENLACE_INVALIDO = 'El enlace no es válido o ya venció. Solicita uno nuevo.';

    /**
     * @return bool true si se cambió la contraseña; false por cualquier motivo
     *              (correo inexistente, enlace inventado o vencido), sin decir cuál.
     */
    public function ejecutar(string $email, string $password, string $passwordConfirmation, string $token): bool
    {
        $estado = Password::broker('users')->reset(
            [
                'email'                 => $email,
                'password'              => $password,
                'password_confirmation' => $passwordConfirmation,
                'token'                 => $token,
            ],
            function (Usuario $usuario, string $password) {
                $usuario->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $usuario->save();

                // Solo corre si el enlace era válido: con un enlace falso o
                // vencido el broker ni siquiera llega aquí.
                $usuario->tokens()->delete();

                event(new PasswordReset($usuario));
            }
        );

        return $estado === Password::PASSWORD_RESET;
    }
}
