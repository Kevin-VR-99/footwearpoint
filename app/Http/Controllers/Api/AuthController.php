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
use App\Models\Distribuidora;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\RestablecerPasswordAction;
use App\Services\Auth\EnviarEnlaceRecuperacionAction;
use App\Services\Auth\LimiteIntentosLogin;

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

    public function login(LoginRequest $request, LimiteIntentosLogin $limite)
    {
        // TG-185 (A2): mismo limite que el panel web. Se revisa ANTES de mirar
        // la contrasena, para que agotar los intentos sirva de freno real.
        if ($limite->bloqueado($request->email, $request->ip())) {
            return response()->json([
                'message' => $limite->mensaje($limite->segundosRestantes($request->email, $request->ip())),
            ], 429);
        }

        $usuario = Usuario::where('email', $request->email)->first();

        if (!$usuario || !Hash::check($request->password, $usuario->password)) {
            $limite->registrarFallo($request->email, $request->ip());

            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        // Entro bien: los fallos anteriores ya no cuentan.
        $limite->limpiar($request->email, $request->ip());

        if ($usuario->estado !== 'activo') {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta no está activa.'],
            ]);
        }

        // TG-196 (G5) / TG-220: si su distribuidora está pendiente, suspendida
        // o rechazada no
        // puede operar. Se revisa ANTES de la regla del personal (TG-93):
        // sin distribuidora, el rol del personal saldría en null y esa regla
        // ya no lo detendría. Tampoco se crea token.
        $sinOperacion = Tenant::distribuidoraSinOperacionDe((int) $usuario->id);

        if ($sinOperacion !== null) {
            throw ValidationException::withMessages([
                'email' => [$sinOperacion->mensajeSinOperacionParaApp()],
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
                'email' => ['Esta aplicación es solo para clientes mayoristas y clientes directos.'],
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

        // TG-196 (G5): su distribuidora se suspendió o rechazó después del
        // login. Igual que con la cuenta inactiva: 401, se revoca el token y
        // la app regresa al login, donde verá el mismo aviso.
        $sinOperacion = Tenant::distribuidoraSinOperacionDe((int) $usuario->id);

        if ($sinOperacion !== null) {
            $usuario->currentAccessToken()?->delete();

            return response()->json([
                'message' => $sinOperacion->mensajeSinOperacionParaApp(),
            ], 401);
        }

        $sesion = $this->datosDeSesion($usuario);

        // Mismo rechazo que el login (TG-93). Normalmente el personal interno
        // ni siquiera tiene un token de la app, porque el login ya se lo
        // niega; esto cubre un token viejo, sacado antes de esa regla.
        if ($this->esRolSoloWeb($sesion['rol'])) {
            $usuario->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Esta aplicación es solo para clientes mayoristas y clientes directos.',
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

        // Validación robusta y flexible de aceptación de legales vigentes (basta con que existan)
        $avisoAceptado = AceptacionLegal::where('usuario_id', $usuario->id)
            ->where('tipo_documento', 'aviso_privacidad')
            ->exists();

        $terminosAceptados = AceptacionLegal::where('usuario_id', $usuario->id)
            ->where('tipo_documento', 'terminos_condiciones')
            ->exists();

        $legalesAceptados = $avisoAceptado && $terminosAceptados;

        return [
            'usuario' => [
                'id'       => $usuario->id,
                'nombre'   => $usuario->nombreVisible(),
                'email'    => $usuario->email,
                'telefono' => $usuario->telefonoVisible(),
                'estado'   => $usuario->estado,
            ],
            'rol'              => $rol,
            'distribuidora_id' => $distribuidoraId,
            // TG-281: la app enseñaba el numero ("Distribuidora: 1"), que no
            // le dice nada a nadie. Con el nombre, el dato sirve para algo.
            'distribuidora' => $distribuidoraId === null
                ? null
                : Distribuidora::withoutGlobalScopes()->whereKey($distribuidoraId)->value('nombre_comercial'),
            'legales_aceptados' => $legalesAceptados,
            // TG-193: si es true, la app debe mandarla a cambiar su
            // contraseña antes de dejarla usar lo demás.
            'debe_cambiar_password' => (bool) $usuario->debe_cambiar_password,
            // TG-189 (A11): si es false, la app muestra el aviso y el botón
            // para reenviar el enlace. No la saca de la app: solo enviar un
            // pedido pide el correo confirmado.
            'email_verificado' => $usuario->hasVerifiedEmail(),
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

            // TG-224: sin scope a propósito, esta consulta RESUELVE la distribuidora del usuario (ver Tenant::desdeStaff).
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

            Tenant::forzar($distribuidoraId, fn () => \App\Models\DistribuidoraStaff::create([
                'distribuidora_id' => $distribuidoraId,
                'usuario_id'       => $usuario->id,
                'tipo'             => 'empleado',
                'estado'           => 'activo',
                'fecha_alta'       => now(),
            ]));

            setPermissionsTeamId($distribuidoraId);
            $usuario->assignRole('empleado');

            return response()->json([
                'data' => [
                    'id'               => $usuario->id,
                    'nombre'           => $usuario->nombreVisible(),
                    'email'            => $usuario->email,
                    'telefono'         => $usuario->telefonoVisible(),
                    'estado'           => $usuario->estado,
                    'rol'              => 'empleado',
                    'distribuidora_id' => $distribuidoraId,
                ],
                'message' => 'Empleado registrado correctamente.',
            ], 201);
        } catch (\Throwable $e) {
            // TG-224 (G3): el detalle técnico solo va al log; nunca se
            // devuelven mensaje, archivo ni línea de la excepción.
            report($e);

            return response()->json([
                'message' => 'No se pudo registrar al empleado. Intenta de nuevo.',
            ], 500);
        }
    }

    public function aceptarLegales(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Llegó petición de legales:', $request->all());
        $usuario = $request->user();
        $ip = $request->ip();

        // Acepta de forma flexible cualquier estructura que mande la app
        $documentos = $request->input('documentos', [
            ['tipo_documento' => 'aviso_privacidad', 'version' => '1.0'],
            ['tipo_documento' => 'terminos_condiciones', 'version' => '1.0'],
        ]);

        $aceptadas = [];

        foreach ($documentos as $doc) {
            $tipo = is_array($doc) ? ($doc['tipo_documento'] ?? 'aviso_privacidad') : 'aviso_privacidad';
            $version = is_array($doc) ? ($doc['version'] ?? '1.0') : '1.0';

            $aceptacion = AceptacionLegal::updateOrCreate(
                [
                    'usuario_id'     => $usuario->id,
                    'tipo_documento' => $tipo,
                ],
                [
                    'version'          => $version,
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

    /**
     * POST /api/auth/reenviar-verificacion (TG-189 / A11).
     *
     * El botón "no me llegó" de la app. Siempre responde lo mismo, haya o no
     * haya algo que mandar: así nadie puede usarlo para averiguar si una
     * cuenta ya confirmó o no.
     */
    public function reenviarVerificacion(Request $request)
    {
        $usuario = $request->user();

        if ($usuario !== null && ! $usuario->hasVerifiedEmail()) {
            $usuario->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'Si tu correo está sin confirmar, te mandamos un enlace nuevo. Revisa tu bandeja y la carpeta de spam.',
        ]);
    }

    /**
     * Además de cambiar la contraseña, revoca todos los tokens de la cuenta
     * (TG-142). Si falla, siempre el mismo error, sin decir si el correo
     * existe. Ver RestablecerPasswordAction.
     */
    public function resetPassword(ResetPasswordRequest $request, RestablecerPasswordAction $accion)
    {
        $restablecida = $accion->ejecutar(
            $request->input('email'),
            $request->input('password'),
            (string) $request->input('password_confirmation'),
            $request->input('token'),
        );

        if (! $restablecida) {
            throw ValidationException::withMessages([
                'token' => [RestablecerPasswordAction::MENSAJE_ENLACE_INVALIDO],
            ]);
        }

        return response()->json([
            'message' => 'Contraseña restablecida correctamente.',
        ]);
    }
}