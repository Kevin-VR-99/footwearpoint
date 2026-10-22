<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auditoría 21-oct-2026 (punto 4) — Content-Security-Policy en modo
 * Report-Only: el navegador NO bloquea nada, solo avisa a /api/csp-report
 * lo que se bloquearía. Cuando el log quede limpio unos días, se cambia
 * CSP_MODO=enforce para que la política sea obligatoria.
 *
 * - Genera un nonce por petición con Vite::useCspNonce(); @vite,
 *   @livewireScripts/@livewireStyles lo ponen solos y los <script> propios
 *   lo llevan con nonce="{{ Vite::cspNonce() }}".
 * - Alpine (dentro de Livewire) evalúa expresiones con new Function(), así
 *   que script-src necesita 'unsafe-eval' mientras no se use la versión
 *   CSP-safe (config livewire.csp_safe).
 * - style-src lleva 'unsafe-inline' por los atributos style="" y los estilos
 *   que Livewire/Alpine inyectan (x-show, x-transition).
 * - Mercado Pago se usa por redirección (Checkout Pro y OAuth); form-action
 *   lo permite porque el navegador revisa también las redirecciones.
 * - img-src admite https: porque las fotos viven en S3/CDN y los logotipos
 *   pueden venir de otro dominio.
 */
class PoliticaSeguridadContenido
{
    public function handle(Request $request, Closure $next): Response
    {
        $modo = (string) config('app.csp_modo', 'report-only');

        if ($modo === 'off') {
            return self::conUpgradeInsecure($next($request), $modo);
        }

        Vite::useCspNonce();

        $respuesta = $next($request);

        $tipo = (string) $respuesta->headers->get('Content-Type', '');
        if ($tipo !== '' && ! str_contains($tipo, 'text/html')) {
            return $respuesta;
        }

        $cabecera = $modo === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';

        if (! $respuesta->headers->has($cabecera)) {
            $respuesta->headers->set($cabecera, self::politica((string) Vite::cspNonce()));
        }

        return self::conUpgradeInsecure($respuesta, $modo);
    }

    /**
     * upgrade-insecure-requests (antes un header() suelto en AppServiceProvider):
     * con APP_URL https el navegador pide por https cualquier recurso http://.
     * En modo enforce va dentro de la política; en report-only/off el
     * navegador ignora esa directiva en Report-Only, así que viaja sola en una
     * cabecera Content-Security-Policy que no restringe nada más.
     */
    private static function conUpgradeInsecure(Response $respuesta, string $modo): Response
    {
        if (! self::usaHttps() || $modo === 'enforce') {
            return $respuesta;
        }

        if (! $respuesta->headers->has('Content-Security-Policy')) {
            $respuesta->headers->set('Content-Security-Policy', 'upgrade-insecure-requests');
        }

        return $respuesta;
    }

    private static function usaHttps(): bool
    {
        return str_starts_with((string) config('app.url'), 'https://');
    }

    public static function politica(string $nonce): string
    {
        $dev = '';
        if (Vite::isRunningHot()) {
            $hot = rtrim((string) @file_get_contents(public_path('hot')));
            $ws = preg_replace('#^http#', 'ws', $hot);
            $dev = ' '.$hot.' '.$ws;
        }

        $mp = 'https://*.mercadopago.com https://*.mercadopago.com.mx https://*.mercadolibre.com https://*.mercadolibre.com.mx';

        $directivas = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'".$dev,
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net".$dev,
            "font-src 'self' data: https://fonts.gstatic.com https://fonts.bunny.net",
            "img-src 'self' data: blob: https:",
            "connect-src 'self'".$dev,
            "frame-src 'self' blob: https:",
            "form-action 'self' {$mp}",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            'report-uri '.route('csp.reporte', absolute: false),
        ];

        if (self::usaHttps()) {
            $directivas[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directivas);
    }
}
