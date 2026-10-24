<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use App\Support\SesionDelPanel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seguridad oct-2026: para el admin general la cookie de sesión es de
 * navegador (expire_on_close) en lugar de durar SESSION_LIFETIME. StartSession
 * arma la cookie al final de la petición leyendo esta configuración, así que
 * basta con ajustarla mientras corre la petición.
 */
class CookieDeSesionDelAdminGeneral
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        $usuario = Auth::guard('web')->user();

        if ($usuario instanceof Usuario && ! SesionDelPanel::esPersistente($usuario)) {
            config(['session.expire_on_close' => true]);
        }

        return $respuesta;
    }
}
