<?php

namespace App\Services\Personal;

use App\Models\Usuario;
use App\Services\Auth\EnviarEnlaceRestablecerPanelAction;
use App\Services\Auth\GenerarPasswordTemporalAction;
use App\Support\PersonalPlataforma;
use Illuminate\Validation\ValidationException;

/**
 * TG-337 (K39) — El admin general nunca escribe ni ve una contraseña elegida:
 * genera una temporal, que se muestra una vez, o manda un enlace.
 */
class RestablecerAccesoPersonalAction
{
    public const TEMPORAL = 'temporal';

    public const ENLACE = 'enlace';

    public function __construct(
        private ConfirmarPasswordAdminAction $confirmar,
        private GenerarPasswordTemporalAction $temporal,
        private EnviarEnlaceRestablecerPanelAction $enlace,
        private AvisarCambioPersonalAction $aviso,
    ) {
    }

    /** @return array{password_temporal: ?string, mensaje: string} */
    public function ejecutar(Usuario $admin, Usuario $persona, string $modo, string $password): array
    {
        $this->confirmar->exigir($admin, $password);
        PersonalPlataforma::exigirPersonal($persona);

        if (! in_array($modo, [self::TEMPORAL, self::ENLACE], true)) {
            throw ValidationException::withMessages(['modo_password' => ['Elige cómo restablecer el acceso.']]);
        }

        if ($modo === self::ENLACE) {
            try {
                return [
                    'password_temporal' => null,
                    'mensaje' => $this->enlace->ejecutar($persona, 'usuario', (int) $persona->id),
                ];
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    'modo_password' => [$e->errors()['enlace'][0] ?? 'No se pudo enviar el enlace.'],
                ]);
            }
        }

        try {
            $generada = $this->temporal->ejecutar($persona, 'usuario', (int) $persona->id);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'modo_password' => [$e->errors()['password_temporal'][0] ?? 'No se pudo generar la contraseña temporal.'],
            ]);
        }

        $fallo = $this->aviso->ejecutar(
            $persona,
            'Un administrador restableció tu contraseña de FootwearPoint. La anterior ya no sirve; te dará una temporal que deberás cambiar al entrar.',
        );

        return ['password_temporal' => $generada, 'mensaje' => $fallo ?? 'Contraseña temporal generada.'];
    }
}
