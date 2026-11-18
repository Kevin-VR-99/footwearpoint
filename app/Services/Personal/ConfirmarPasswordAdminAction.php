<?php

namespace App\Services\Personal;

use App\Models\Usuario;
use App\Services\Auth\LimiteIntentosLogin;
use App\Support\PersonalPlataforma;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * TG-337 (K39) — Los cambios delicados piden la contraseña del admin general.
 *
 * El límite va por cuenta y no por IP: quien tomó una sesión abierta no debe
 * poder seguir probando contraseñas cambiando de red.
 */
class ConfirmarPasswordAdminAction
{
    public const CAMPO = 'password_confirmacion';

    public function __construct(private LimiteIntentosLogin $limite)
    {
    }

    /** @throws ValidationException si está bloqueado o la contraseña no es la suya. */
    public function exigir(Usuario $admin, string $password): void
    {
        abort_unless(PersonalPlataforma::esAdminGeneral($admin), 403, 'Solo admin general.');

        $llave = 'confirmar-personal:'.$admin->id;

        if ($this->limite->bloqueado($llave, null)) {
            throw ValidationException::withMessages([
                self::CAMPO => [$this->limite->mensaje($this->limite->segundosRestantes($llave, null))],
            ]);
        }

        if ($password === '' || ! Hash::check($password, (string) $admin->getAuthPassword())) {
            $this->limite->registrarFallo($llave, null);

            throw ValidationException::withMessages([self::CAMPO => ['Tu contraseña no es correcta.']]);
        }

        $this->limite->limpiar($llave, null);
    }
}
