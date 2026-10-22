<?php

namespace App\Http\Middleware;

use App\Services\Auth\PermisoModuloService;
use App\Support\MensajeError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un permiso de módulo (o fino) del personal en la ruta.
 * Uso: ->middleware(ExigirPermisoModulo::class.':caja.operar')
 */
class ExigirPermisoModulo
{
    public function handle(Request $request, Closure $next, string $permiso): Response
    {
        abort_unless(
            app(PermisoModuloService::class)->puede($request->user(), $permiso),
            403,
            MensajeError::SIN_PERMISO,
        );

        return $next($request);
    }
}
