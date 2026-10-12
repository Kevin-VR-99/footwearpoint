<?php

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * TG-194 (G2) — Alta de una distribuidora por el admin general, junto con su
 * administrador.
 *
 * El administrador es obligatorio: una distribuidora sin nadie que la
 * administre no puede operar. Se crea en la misma transacción que la
 * distribuidora, con tipo 'administrador' en distribuidora_staff y el rol
 * admin_distribuidora (con sus permisos) dentro de su distribuidora, tanto si
 * queda activa como pendiente.
 */
class CrearDistribuidoraAction
{
    public function __construct(
        private PrepararDistribuidoraAction $preparar,
        private ProvisionarRolesDistribuidoraAction $roles,
    ) {
    }

    /**
     * @param  array  $datos  nombre_comercial, slug y opcionales: razon_social, rfc,
     *                        subdominio, descripcion_publica, direccion_publica,
     *                        telefono_publico, email_publico, horario_publico,
     *                        marketplace_visible, plan_solicitado_id (TG-220).
     * @param  array  $administrador  nombre, email, password.
     * @param  bool  $activar  true: queda activa; false: queda pendiente de aprobar.
     */
    public function ejecutar(array $datos, array $administrador, bool $activar): Distribuidora
    {
        $nombreAdmin = trim((string) ($administrador['nombre'] ?? ''));
        $emailAdmin = trim((string) ($administrador['email'] ?? ''));
        $passwordAdmin = (string) ($administrador['password'] ?? '');

        if ($nombreAdmin === '' || $emailAdmin === '' || $passwordAdmin === '') {
            throw ValidationException::withMessages([
                'admin_email' => ['La distribuidora necesita su administrador: nombre, correo y contraseña.'],
            ]);
        }

        $estado = $activar ? 'activa' : 'pendiente';
        $plan = $this->preparar->planPorDefecto();

        return DB::transaction(function () use ($datos, $estado, $plan, $nombreAdmin, $emailAdmin, $passwordAdmin) {
            $slug = $datos['slug'];

            $distribuidora = Distribuidora::create([
                'nombre_comercial'    => $datos['nombre_comercial'],
                'razon_social'        => $this->textoONull($datos['razon_social'] ?? null),
                'rfc'                 => self::normalizarRfc($datos['rfc'] ?? null),
                'slug'                => $slug,
                'subdominio'          => $this->textoONull($datos['subdominio'] ?? null) ?? $slug,
                'descripcion_publica' => $this->textoONull($datos['descripcion_publica'] ?? null),
                'direccion_publica'   => $this->textoONull($datos['direccion_publica'] ?? null),
                'telefono_publico'    => $this->textoONull($datos['telefono_publico'] ?? null),
                'email_publico'       => $this->textoONull($datos['email_publico'] ?? null),
                'horario_publico'     => $this->textoONull($datos['horario_publico'] ?? null),
                // Solo una distribuidora activa puede verse en el marketplace.
                'marketplace_visible' => ! empty($datos['marketplace_visible']) && $estado === 'activa',
                'estado'              => $estado,
                // TG-220: lo elige quien pide su alta en el formulario
                // público; AprobarDistribuidoraAction lo usa al aprobar.
                'plan_solicitado_id'  => $datos['plan_solicitado_id'] ?? null,
                'fecha_solicitud'     => now(),
                'fecha_aprobacion'    => $estado === 'activa' ? now() : null,
            ]);

            $this->preparar->datosIniciales($distribuidora);

            // La pendiente recibe su suscripción al aprobarse.
            if ($plan && $estado === 'activa') {
                $this->preparar->suscripcionInicial($distribuidora, $plan);
            }

            $usuario = Usuario::create([
                'nombre'   => $nombreAdmin,
                'email'    => $emailAdmin,
                'password' => Hash::make($passwordAdmin),
                'estado'   => 'activo',
            ]);

            Tenant::forzar($distribuidora->id, fn () => DistribuidoraStaff::create([
                'distribuidora_id' => $distribuidora->id,
                'usuario_id'       => $usuario->id,
                // El enum de distribuidora_staff.tipo es administrador|empleado.
                'tipo'             => 'administrador',
                'estado'           => 'activo',
                'fecha_alta'       => now(),
            ]));

            $this->roles->ejecutar($distribuidora);
            $this->roles->asignarAdministrador($usuario, $distribuidora);

            // TG-220 (A11): el correo para confirmar que la cuenta es suya,
            // igual que en ActivarCuentaAccesoAction. Va después de la
            // transacción para no mandarlo si algo falla y la distribuidora
            // termina no existiendo. No bloquea nada: la solicitud se revisa
            // igual, y el admin general ve si el correo está confirmado.
            //
            // Si el correo falla, el alta NO se deshace: para entonces ya está
            // guardada, y quien pidió su alta no tiene por qué ver un error de
            // nuestro servidor de correo. Queda en el log.
            DB::afterCommit(function () use ($usuario) {
                try {
                    $usuario->sendEmailVerificationNotification();
                } catch (Throwable $e) {
                    report($e);
                }
            });

            return $distribuidora;
        });
    }

    /** RFC en mayúsculas y sin espacios alrededor; vacío se guarda como null. */
    public static function normalizarRfc(?string $rfc): ?string
    {
        $rfc = mb_strtoupper(trim((string) $rfc));

        return $rfc === '' ? null : $rfc;
    }

    private function textoONull(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
