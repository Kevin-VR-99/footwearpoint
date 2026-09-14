<?php

namespace App\Services\Perfil;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Cambiar la contraseña desde el perfil (E1-05 / TG-110).
 *
 * Decisión del equipo: al cambiarla se cierran las sesiones de los demás
 * teléfonos (se borran sus tokens) y solo sigue activa la del teléfono donde
 * se hizo el cambio. Es lo esperado si alguien la cambia porque sospecha que
 * otra persona la conoce.
 */
class CambiarPasswordAction
{
    public function ejecutar(
        Usuario $usuario,
        string $passwordActual,
        string $passwordNueva,
        ?PersonalAccessToken $tokenActual,
    ): void {
        if (! Hash::check($passwordActual, $usuario->password)) {
            throw ValidationException::withMessages([
                'password_actual' => ['La contraseña actual no es correcta.'],
            ]);
        }

        DB::transaction(function () use ($usuario, $passwordNueva, $tokenActual) {
            $usuario->forceFill([
                'password' => Hash::make($passwordNueva),
            ])->setRememberToken(Str::random(60));

            $usuario->save();

            // Si la petición no trae token de la API (no hay uno que conservar),
            // se cierran todas las sesiones de la app.
            $usuario->tokens()
                ->when($tokenActual !== null, fn ($tokens) => $tokens->whereKeyNot($tokenActual->getKey()))
                ->delete();
        });
    }
}
