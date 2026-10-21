<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TG-235 (G17, E17-04) — Strict-Transport-Security: el navegador que ya
 * entró por https no vuelve a intentar http durante un año. Solo se manda en
 * respuestas https (detrás del proxy de Railway, gracias a trustProxies).
 * includeSubDomains: cubre también los subdominios de cada distribuidora.
 */
class AgregarHsts
{
    public const VALOR = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        if ($request->isSecure()) {
            $respuesta->headers->set('Strict-Transport-Security', self::VALOR);
        }

        return $respuesta;
    }
}
