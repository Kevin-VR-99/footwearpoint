<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * TG-193 (A4) — Obliga a cambiar la contrasena temporal.
 *
 * Mientras la cuenta tenga debe_cambiar_password, la API solo deja hacer tres
 * cosas: ver su sesion, cambiar la contrasena y cerrar sesion. Todo lo demas
 * responde 403 con un mensaje claro.
 *
 * El candado vive en el servidor a proposito: si solo se confiara en que la
 * app mande a la pantalla de cambio, cualquiera con el token podria seguir
 * usando la API con la contrasena que le dictaron en el mostrador.
 */
class DebeCambiarPassword
{
    public const MENSAJE = 'Tienes una contraseña temporal. Cámbiala para poder seguir usando la app.';

    /** Lo unico que se permite mientras no la cambie. */
    private const RUTAS_PERMITIDAS = [
        'api/auth/me',
        'api/auth/logout',
        'api/perfil',
        'api/perfil/password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Se pregunta por el guard de la API (el token Bearer) y no por el
        // guard por omisión, porque este middleware corre en todo /api, antes
        // de que 'auth:sanctum' deje resuelto al usuario.
        $usuario = $request->user('sanctum') ?? Auth::user();

        if ($usuario && $usuario->debe_cambiar_password && ! $this->permitida($request)) {
            return response()->json([
                'message'               => self::MENSAJE,
                'debe_cambiar_password' => true,
            ], 403);
        }

        return $next($request);
    }

    private function permitida(Request $request): bool
    {
        return in_array(trim($request->path(), '/'), self::RUTAS_PERMITIDAS, true);
    }
}
