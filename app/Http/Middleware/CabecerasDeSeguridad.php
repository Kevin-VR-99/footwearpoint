<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad para todas las respuestas.
 *
 * - X-Frame-Options SAMEORIGIN: nadie de fuera puede meter el panel en un
 *   iframe (clickjacking). Los iframes propios (visor de archivos) y los que
 *   nosotros insertamos (mapa de Google) siguen funcionando: esta cabecera
 *   solo limita quién nos enmarca a nosotros.
 * - X-Content-Type-Options nosniff, Referrer-Policy y un Permissions-Policy
 *   mínimo (la web no usa cámara, micrófono ni ubicación).
 * - Se quita X-Powered-By para no anunciar la versión de PHP.
 *
 * Mercado Pago (Checkout Pro y OAuth) se usa por redirección, no en iframe,
 * así que no le afecta. Si una respuesta ya trae su propio valor, se respeta.
 */
class CabecerasDeSeguridad
{
    public const CABECERAS = [
        'X-Frame-Options'        => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=(), usb=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        foreach (self::CABECERAS as $nombre => $valor) {
            if (! $respuesta->headers->has($nombre)) {
                $respuesta->headers->set($nombre, $valor);
            }
        }

        $respuesta->headers->remove('X-Powered-By');

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $respuesta;
    }
}
