<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterEmpleadoRequest;
use App\Models\DistribuidoraStaff;
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
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;

class AuthController extends Controller
{
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

        // Token Sanctum
        $token = $usuario->createToken('auth_token')->plainTextToken;

        return response()->json([
            'data' => [
                'token'      => $token,
                'token_type' => 'Bearer',
            ] + $this->datosDeSesion($usuario),
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
     * Responde lo mismo que el login, pero sin token nuevo.
     */
    public function me(Request $request)
    {
        $usuario = $request->user();

        // El token sigue siendo válido aunque la cuenta se haya desactivado
        // después del login. Se trata como sesión vencida (401) para que la
        // app regrese a la pantalla de login, y el token se revoca de una vez.
        if ($usuario->estado !== 'activo') {
            $usuario->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Tu cuenta no está activa.',
            ], 401);
        }

        return response()->json([
            'data' => $this->datosDeSesion($usuario),
        ]);
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

    public function logout(Request $request)
    {
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

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $status = Password::broker('users')->sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'Se ha enviado el enlace de recuperación al correo.',
            ]);
        }

        return response()->json([
            'message' => 'No se pudo enviar el enlace de recuperación.',
            'error'   => __($status),
        ], 422);
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
