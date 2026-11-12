<?php

namespace App\Http\Middleware;

use App\Models\ClienteDirecto;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si la depuración dejó el teléfono en null por estar repetido, la cuenta
 * solo puede ver su sesión, cerrarla y guardar el teléfono nuevo.
 */
class DebeRecapturarTelefono
{
    public const MENSAJE = 'Tu teléfono ya no está en el registro. Escríbelo de nuevo para seguir.';

    private const RUTAS_PERMITIDAS = [
        'api/auth/me',
        'api/auth/logout',
        'api/auth/aceptar-legales',
        'api/auth/reenviar-verificacion',
        'api/perfil',
        'api/perfil/recapturar-telefono',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user('sanctum') ?? Auth::user();

        if ($usuario && ClienteDirecto::debeRecapturarTelefono($usuario) && ! $this->permitida($request)) {
            return response()->json([
                'message' => self::MENSAJE,
                'debe_recapturar_telefono' => true,
            ], 403);
        }

        return $next($request);
    }

    private function permitida(Request $request): bool
    {
        return in_array(trim($request->path(), '/'), self::RUTAS_PERMITIDAS, true);
    }
}
