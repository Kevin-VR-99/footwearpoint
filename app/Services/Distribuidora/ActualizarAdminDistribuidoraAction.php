<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * TG-194 — El admin general corrige nombre y correo del administrador de una
 * distribuidora y, si hace falta, le pone una contraseña nueva.
 *
 * - El correo es único entre usuarios (ignorando el suyo). Como lo cambia el
 *   admin general, queda verificado (email_verified_at puesto).
 * - La contraseña es opcional: vacía no cambia nada.
 * - En la auditoría quedan nombre y correo (antes y después) y solo la marca
 *   de que se restableció la contraseña; la contraseña nunca.
 */
class ActualizarAdminDistribuidoraAction
{
    public const ACCION_AUDITORIA = 'distribuidora.admin_actualizado';

    public const MENSAJE_SIN_ADMIN = 'Esta distribuidora no tiene administrador registrado.';

    public function __construct(private RegistrarAuditoriaAction $auditoria)
    {
    }

    /** El administrador activo de la distribuidora, o null si no tiene. */
    public function administradorDe(Distribuidora $distribuidora): ?Usuario
    {
        $staff = Tenant::forzar((int) $distribuidora->id, fn () => DistribuidoraStaff::query()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('tipo', 'administrador')
            ->where('estado', 'activo')
            ->orderBy('id')
            ->first());

        return $staff ? Usuario::find($staff->usuario_id) : null;
    }

    /**
     * @param  array  $datos  nombre, email y opcionales password, password_confirmation.
     *
     * @throws ValidationException con los errores por campo (sin prefijo).
     * @throws OperacionInvalidaException si la distribuidora no tiene administrador.
     */
    public function ejecutar(Distribuidora $distribuidora, array $datos): Usuario
    {
        $usuario = $this->administradorDe($distribuidora);

        if ($usuario === null) {
            throw new OperacionInvalidaException(self::MENSAJE_SIN_ADMIN, 422);
        }

        $datos['nombre'] = trim((string) ($datos['nombre'] ?? ''));
        $datos['email'] = trim((string) ($datos['email'] ?? ''));
        $datos['password'] = (string) ($datos['password'] ?? '');
        $datos['password_confirmation'] = (string) ($datos['password_confirmation'] ?? '');

        Validator::make($datos, [
            'nombre'   => ['required', 'string', 'max:150'],
            'email'    => ['required', 'email', 'max:190', Rule::unique('usuarios', 'email')->ignore($usuario->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ], [
            'nombre.required'    => 'El nombre del administrador es obligatorio.',
            'nombre.max'         => 'El nombre del administrador no puede pasar de 150 caracteres.',
            'email.required'     => 'El correo del administrador es obligatorio.',
            'email.email'        => 'El correo del administrador no es válido.',
            'email.max'          => 'El correo del administrador no puede pasar de 190 caracteres.',
            'email.unique'       => 'Ese correo ya tiene una cuenta.',
            'password.min'       => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación no coincide con la contraseña.',
        ])->validate();

        $previos = [];
        $nuevos = [];
        foreach (['nombre', 'email'] as $campo) {
            if ($usuario->{$campo} !== $datos[$campo]) {
                $previos[$campo] = $usuario->{$campo};
                $nuevos[$campo] = $datos[$campo];
            }
        }
        $cambiaPassword = $datos['password'] !== '';

        if ($previos === [] && ! $cambiaPassword) {
            return $usuario;
        }

        Tenant::forzar((int) $distribuidora->id, fn () => DB::transaction(function () use ($usuario, $datos, $previos, $nuevos, $cambiaPassword) {
            $usuario->nombre = $datos['nombre'];
            $usuario->email = $datos['email'];

            // Lo cambia el admin general: el correo nuevo queda verificado.
            if (isset($nuevos['email']) || $usuario->email_verified_at === null) {
                $usuario->forceFill(['email_verified_at' => $usuario->email_verified_at ?? now()]);
            }

            if ($cambiaPassword) {
                $usuario->forceFill([
                    'password'       => Hash::make($datos['password']),
                    'remember_token' => Str::random(60),
                ]);
                $nuevos['password_restablecida'] = true;
            }

            $usuario->save();

            $this->auditoria->ejecutar(
                self::ACCION_AUDITORIA,
                'usuario',
                (int) $usuario->id,
                $previos === [] ? null : $previos,
                $nuevos,
            );
        }));

        return $usuario->refresh();
    }
}
