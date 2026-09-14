<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterEmpleadoRequest;
use App\Models\DistribuidoraStaff;
use App\Services\Notificacion\GestionarDispositivoFcmAction;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\Auth\AceptarLegalesRequest;
use App\Models\AceptacionLegal;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\EnviarEnlaceRecuperacionAction;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Roles del personal interno: no pueden iniciar sesión por la API.
     *
     * Es una lista de los que se rechazan, no de los que se permiten, a
     * propósito: un revendedor suspendido (rol en null porque no resuelve
     * distribuidora) sigue entrando, y el TenantScope hace que no vea nada.
     */
    private const ROLES_SOLO_WEB = ['admin_general', 'admin_distribuidora', 'empleado'];

    public function login(LoginRequest $request)
    {
        $usuario = Usuario::where('email', $request->email)->first();

        if (!$usuario || !Hash::check($request->password, $usuario->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        if ($usuario->estado !== 'activo') {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta no está activa.'],
            ]);
        }

        $sesion = $this->datosDeSesion($usuario);

        // TG-93 (E1-01): este login lo usa la app móvil, que es solo para
        // revendedor y cliente directo. El personal interno entra por el
        // panel web, que tiene su propio login con sesión y no pasa por aquí.
        // Se revisa ANTES de crear el token, para no dejarle uno válido a
        // quien se está rechazando.
        if ($this->esRolSoloWeb($sesion['rol'])) {
            throw ValidationException::withMessages([
                'email' => ['Esta aplicación es solo para revendedores y clientes directos.'],
            ]);
        }

        // Token Sanctum
        $token = $usuario->createToken('auth_token')->plainTextToken;

        return response()->json([
            'data' => [
                'token'      => $token,
                'token_type' => 'Bearer',
            ] + $sesion,
            'message' => 'Inicio de sesión exitoso.',
        ]);
    }

    /**
     * GET /api/auth/me (TG-140) — ¿de quién es este token?
     *
     * La app móvil solo guarda el token. Al volver a abrirla necesita saber
     * otra vez quién es el usuario, su rol y su distribuidora, y no puede
     * guardarlos en el teléfono: si un admin lo suspende, el teléfono
     * seguiría creyendo que tiene acceso. Por eso se le pregunta al servidor.
     *
     * Responde lo mismo que el login, pero sin token nuevo. Los dos rechazos
     * responden 401 y revocan el token, para que la app regrese al login
     * igual que con cualquier sesión que ya no sirve.
     */
    public function me(Request $request)
    {
        $usuario = $request->user();

        // Sanctum no invalida el token cuando la cuenta se desactiva después
        // del login: sin esta revisión, el token seguiría respondiendo 200.
        if ($usuario->estado !== 'activo') {
            $usuario->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Tu cuenta no está activa.',
            ], 401);
        }

        $sesion = $this->datosDeSesion($usuario);

        // Mismo rechazo que el login (TG-93). Normalmente el personal interno
        // ni siquiera tiene un token de la app, porque el login ya se lo
        // niega; esto cubre un token viejo, sacado antes de esa regla.
        if ($this->esRolSoloWeb($sesion['rol'])) {
            $usuario->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Esta aplicación es solo para revendedores y clientes directos.',
            ], 401);
        }

        return response()->json([
            'data' => $sesion,
        ]);
    }

    private function esRolSoloWeb(?string $rol): bool
    {
        return in_array($rol, self::ROLES_SOLO_WEB, true);
    }

    /**
     * Usuario, rol y distribuidora, en la forma que espera la app. Lo usan
     * login y me para responder exactamente igual.
     */
    private function datosDeSesion(Usuario $usuario): array
    {
        // La distribuidora se resuelve con la misma lógica que usa todo el
        // resto del sistema (TG-134), no con una búsqueda propia: antes aquí
        // solo se miraba distribuidora_staff, así que un revendedor o un
        // cliente directo recibía null y la app móvil se quedaba sin
        // distribuidora ni rol con que trabajar, aunque por dentro el sistema
        // sí supiera de quién era.
        $distribuidoraId = Tenant::paraUsuario((int) $usuario->id);

        // Obtener rol (compatible con Spatie teams)
        $rol = null;

        if ($distribuidoraId) {
            // Rol dentro de la distribuidora (team)
            setPermissionsTeamId($distribuidoraId);
            $rol = $usuario->getRoleNames()->first();
        }

        // Si no tiene rol de distribuidora, buscar rol global (admin_general)
        if (!$rol) {
            setPermissionsTeamId(0);
            $usuario->unsetRelation('roles');
            $rol = $usuario->getRoleNames()->first();
        }

        return [
            'usuario' => [
                'id'       => $usuario->id,
                'nombre'   => $usuario->nombre,
                'email'    => $usuario->email,
                'telefono' => $usuario->telefono,
                'estado'   => $usuario->estado,
            ],
            'rol'              => $rol,
            'distribuidora_id' => $distribuidoraId,
        ];
    }

    /**
     * Al borrar el token de la sesión, la base de datos borra sola el celular
     * que se registró con esa sesión (TG-144, ON DELETE CASCADE), así que deja
     * de recibir push aunque la app no mande nada.
     *
     * fcm_token sigue siendo opcional (TG-136): sirve para celulares
     * registrados antes de ligarlos a la sesión, que la cascada no alcanza.
     */
    public function logout(Request $request, GestionarDispositivoFcmAction $dispositivos)
    {
        $datos = $request->validate([
            'fcm_token' => ['nullable', 'string', 'max:255'],
        ]);

        // Primero el dispositivo, mientras todavía se sabe de quién es la
        // sesión; después se revoca el token.
        if (! empty($datos['fcm_token'])) {
            $dispositivos->quitar($request->user(), $datos['fcm_token']);
        }

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    public function registerEmpleado(RegisterEmpleadoRequest $request)
    {
        try {
            $admin = $request->user();

            // Sin Global Scope para evitar el crash
            $staffAdmin = \App\Models\DistribuidoraStaff::withoutGlobalScopes()
                ->where('usuario_id', $admin->id)
                ->first();

            if (!$staffAdmin) {
                return response()->json([
                    'message' => 'No se encontró distribuidora asociada.',
                ], 403);
            }

            $distribuidoraId = $staffAdmin->distribuidora_id;

            $usuario = Usuario::create([
                'nombre'   => $request->nombre,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
                'telefono' => $request->telefono,
                'estado'   => 'activo',
            ]);

            \App\Models\DistribuidoraStaff::withoutGlobalScopes()->create([
                'distribuidora_id' => $distribuidoraId,
                'usuario_id'       => $usuario->id,
                'tipo'             => 'empleado',
                'estado'           => 'activo',
                'fecha_alta'       => now(),
            ]);

            setPermissionsTeamId($distribuidoraId);
            $usuario->assignRole('empleado');

            return response()->json([
                'data' => [
                    'id'               => $usuario->id,
                    'nombre'           => $usuario->nombre,
                    'email'            => $usuario->email,
                    'telefono'         => $usuario->telefono,
                    'estado'           => $usuario->estado,
                    'rol'              => 'empleado',
                    'distribuidora_id' => $distribuidoraId,
                ],
                'message' => 'Empleado registrado correctamente.',
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al registrar empleado.',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ], 500);
        }
    }

    public function aceptarLegales(AceptarLegalesRequest $request)
    {
        $usuario = $request->user();
        $ip = $request->ip();

        $aceptadas = [];

        foreach ($request->documentos as $doc) {
            $aceptacion = AceptacionLegal::firstOrCreate(
                [
                    'usuario_id'     => $usuario->id,
                    'tipo_documento' => $doc['tipo_documento'],
                    'version'        => $doc['version'],
                ],
                [
                    'fecha_aceptacion' => now(),
                    'ip_origen'        => $ip,
                ]
            );

            $aceptadas[] = [
                'tipo_documento'   => $aceptacion->tipo_documento,
                'version'          => $aceptacion->version,
                'fecha_aceptacion' => $aceptacion->fecha_aceptacion,
            ];
        }

        return response()->json([
            'data'    => $aceptadas,
            'message' => 'Documentos legales aceptados correctamente.',
        ], 201);
    }

    /**
     * Siempre 200 con el mismo mensaje, exista o no el correo (TG-141). Ver
     * EnviarEnlaceRecuperacionAction. El único 422 posible es el de un correo
     * mal escrito, que no revela nada sobre las cuentas.
     */
    public function forgotPassword(ForgotPasswordRequest $request, EnviarEnlaceRecuperacionAction $accion)
    {
        return response()->json([
            'message' => $accion->ejecutar($request->validated('email')),
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        $status = Password::broker('users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Contraseña restablecida correctamente.',
            ]);
        }

        return response()->json([
            'message' => 'No se pudo restablecer la contraseña.',
            'error'   => __($status),
        ], 422);
    }
}
