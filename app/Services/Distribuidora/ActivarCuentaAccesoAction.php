<?php

namespace App\Services\Distribuidora;

use App\Models\ClienteDirecto;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * E3-07 (TG-133) — Darle correo y contraseña a un revendedor o cliente
 * directo que ya existe, para que pueda entrar a la app móvil.
 *
 * Hasta Sprint 2 estos dos eran solo un registro de contacto: la columna
 * usuario_id existía en revendedores y clientes_directos pero siempre iba
 * vacía. Aquí se crea el Usuario, se liga por usuario_id y se le da su rol.
 *
 * Es la única puerta para hacerlo: la usan la API y la pantalla
 * Configuración del panel web, para no tener la regla en dos lugares.
 */
class ActivarCuentaAccesoAction
{
    public function paraRevendedor(RevendedorDistribuidora $afiliacion, string $email, string $password): Usuario
    {
        // usuario_id vive en la tabla global "revendedores" (la persona), no
        // en la afiliación; la distribuidora sí sale de la afiliación.
        return $this->activar(
            $afiliacion->revendedor,
            (int) $afiliacion->distribuidora_id,
            'revendedor',
            $email,
            $password,
        );
    }

    public function paraClienteDirecto(ClienteDirecto $cliente, string $email, string $password): Usuario
    {
        return $this->activar(
            $cliente,
            (int) $cliente->distribuidora_id,
            'cliente_directo',
            $email,
            $password,
        );
    }

    private function activar(Model $titular, int $distribuidoraId, string $rol, string $email, string $password): Usuario
    {
        $email = trim($email);

        if ($titular->usuario_id !== null) {
            throw ValidationException::withMessages([
                'acceso_email' => ['Este registro ya tiene una cuenta de acceso.'],
            ]);
        }

        // El correo es único en toda la base, así que esto también cumple la
        // regla acordada de "una cuenta = una distribuidora": si la persona ya
        // tiene cuenta con otra distribuidora, necesita un correo distinto.
        // Tenant::id() tiene además una red de seguridad por si llegara a
        // existir un caso doble con datos viejos.
        if (Usuario::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'acceso_email' => [
                    'Ese correo ya tiene una cuenta. Si esta persona ya usa la app con otra distribuidora, necesita un correo distinto.',
                ],
            ]);
        }

        return DB::transaction(function () use ($titular, $distribuidoraId, $rol, $email, $password) {
            $usuario = Usuario::create([
                'nombre'   => $titular->nombre,
                'email'    => $email,
                'password' => Hash::make($password),
                'telefono' => $titular->telefono,
                'estado'   => 'activo',
            ]);

            $titular->usuario_id = $usuario->id;
            $titular->save();

            $this->asignarRol($usuario, $rol, $distribuidoraId);

            return $usuario;
        });
    }

    /**
     * Los roles viven por distribuidora (team_id). En la distribuidora demo
     * los crea el seeder, pero en una distribuidora real nadie los ha creado
     * todavía: por eso firstOrCreate, en el momento en que se necesitan.
     */
    private function asignarRol(Usuario $usuario, string $rol, int $distribuidoraId): void
    {
        $registrar = app(PermissionRegistrar::class);
        $teamAnterior = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($distribuidoraId);

            $role = Role::firstOrCreate([
                'name'       => $rol,
                'guard_name' => 'web',
                'team_id'    => $distribuidoraId,
            ]);

            $usuario->assignRole($role);
        } finally {
            // Se regresa el team que había: quien llama (el admin en su
            // petición) sigue trabajando dentro de su propia distribuidora.
            $registrar->setPermissionsTeamId($teamAnterior);
            $registrar->forgetCachedPermissions();
        }
    }
}
