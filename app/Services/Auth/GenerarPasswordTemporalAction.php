<?php

namespace App\Services\Auth;

use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TG-193 (A4) — Contrasena temporal para un revendedor o un cliente.
 *
 * Es el respaldo del enlace por correo (TG-192): sirve cuando la persona no
 * tiene correo a la mano, cuando el enlace no le llega, o cuando esta en el
 * mostrador y hay que resolverle en el momento.
 *
 * La contrasena se muestra UNA sola vez al personal, para que se la dicte: en
 * la base solo queda su hash, como cualquier otra.
 *
 * Al generarla se marca la cuenta con debe_cambiar_password, y el middleware
 * DebeCambiarPassword obliga a cambiarla antes de poder usar la app.
 *
 * NO se permite si la cuenta la comparten dos distribuidoras: ver
 * CuentaEnVariasDistribuidoras. La revision va aqui dentro, y no solo en la
 * pantalla, para que no se pueda saltar desde fuera.
 */
class GenerarPasswordTemporalAction
{
    /** Largo de la contrasena generada. */
    private const LARGO = 10;

    /**
     * Sin caracteres que se confunden al dictarlos (O/0, l/I/1) ni simbolos
     * que compliquen escribirlos en el teclado del celular.
     */
    private const LETRAS = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    public function __construct(
        private RegistrarAuditoriaAction $auditoria,
        private CuentaEnVariasDistribuidoras $compartida,
    ) {
    }

    /**
     * Genera la contrasena, la deja puesta y la regresa en claro.
     *
     * @param  string  $entidadTipo  'revendedor' o 'cliente_directo', para la bitacora.
     * @param  int     $entidadId    Id del contacto, para poder rastrearlo.
     *
     * @throws ValidationException Si la cuenta la usa otra distribuidora.
     */
    public function ejecutar(Usuario $cuenta, string $entidadTipo, int $entidadId): string
    {
        if ($this->compartida->estaCompartida($cuenta)) {
            throw ValidationException::withMessages([
                'password_temporal' => [CuentaEnVariasDistribuidoras::MENSAJE],
            ]);
        }

        $password = $this->generar();

        DB::transaction(function () use ($cuenta, $password) {
            $cuenta->forceFill([
                'password'              => Hash::make($password),
                'debe_cambiar_password' => true,
            ])->setRememberToken(Str::random(60));

            $cuenta->save();

            // Si alguien tenia la sesion abierta con la contrasena vieja, se
            // cierra: la cuenta acaba de cambiar de dueno de hecho.
            $cuenta->tokens()->delete();
        });

        $this->auditoria->ejecutar(
            accion: 'password.temporal_generada',
            entidadTipo: $entidadTipo,
            entidadId: $entidadId,
            datosNuevos: ['email' => $cuenta->email],
        );

        return $password;
    }

    private function generar(): string
    {
        $maximo = strlen(self::LETRAS) - 1;
        $password = '';

        for ($i = 0; $i < self::LARGO; $i++) {
            $password .= self::LETRAS[random_int(0, $maximo)];
        }

        return $password;
    }
}
