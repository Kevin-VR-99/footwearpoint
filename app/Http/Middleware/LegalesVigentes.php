<?php

namespace App\Http\Middleware;

use App\Services\Auth\AccesoPanelWebService;
use App\Support\DocumentosLegales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * TG-323 (K24) — Pide aceptar la versión vigente de los documentos legales.
 *
 * Si el aviso o los términos cambian de versión, quien aceptó una anterior
 * solo puede aceptar la nueva (o salir). En el panel se le manda a /legales;
 * la API responde 403 y /api/auth/me dice qué falta, que es lo que usa la app
 * para mostrar su pantalla de aceptar.
 *
 * Corre antes que los candados de pago (CobroInicialPendiente y
 * SoloPagoPorFaltaDePago), y esos dejan pasar /legales y
 * /api/auth/aceptar-legales, para que nadie quede dando vueltas entre dos
 * pantallas.
 */
class LegalesVigentes
{
    public const MENSAJE = 'Actualizamos el aviso de privacidad o los términos y condiciones. Acéptalos para seguir.';

    /** Rutas del panel que siguen abiertas mientras falta aceptar. */
    private const RUTAS_WEB = [
        'legales.aceptar',
        'logout',
        'login',
        'aviso-privacidad',
        'terminos',
        'verification.notice',
        'verification.verify',
        'verification.send',
        'password.request',
        'password.reset',
        'password.email',
        'password.update',
        'mercado-pago.webhook',
        'mercado-pago.retorno',
    ];

    /** Lo único que la API deja hacer mientras falta aceptar. */
    private const RUTAS_API = [
        'api/auth/me',
        'api/auth/logout',
        'api/auth/aceptar-legales',
        'api/auth/reenviar-verificacion',
        'api/perfil',
        'api/perfil/password',
        'api/perfil/recapturar-telefono',
    ];

    public function __construct(private AccesoPanelWebService $acceso)
    {
    }

    /**
     * Devuelve mixed a propósito: en las páginas de Livewire, redirect()
     * devuelve el Redirector de Livewire, que no es una Response de Symfony
     * (con «: Response» la página tronaba con 500).
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $esApi = $request->is('api/*');

        // La petición de Livewire en sí pasa: este middleware también corre
        // como persistente, con la ruta de la página donde vive el componente.
        if (! $esApi && str_contains((string) $request->route()?->getName(), 'livewire.')) {
            return $next($request);
        }

        $usuario = $esApi ? ($request->user('sanctum') ?? Auth::user()) : Auth::user();

        if ($usuario === null || $this->permitida($request, $esApi)) {
            return $next($request);
        }

        // En el panel solo se detiene al personal de una distribuidora
        // activa. A quien no puede entrar (pendiente, suspendida, personal
        // dado de baja) lo saca 'solo.personal' con su propio mensaje; si
        // aquí se le mandara a /legales, ese mensaje se perdería.
        if (! $esApi) {
            $staff = $this->acceso->staffActivo($usuario);

            if ($staff === null || $staff->distribuidora?->estado !== 'activa') {
                return $next($request);
            }
        }

        if (DocumentosLegales::alCorriente($usuario)) {
            return $next($request);
        }

        if ($esApi || $request->expectsJson()) {
            return response()->json([
                'message' => self::MENSAJE,
                'legales_pendientes' => true,
            ], 403);
        }

        return redirect()->route('legales.aceptar');
    }

    private function permitida(Request $request, bool $esApi): bool
    {
        if ($esApi) {
            $ruta = trim($request->path(), '/');

            return in_array($ruta, self::RUTAS_API, true) || str_starts_with($ruta, 'api/webhooks');
        }

        $ruta = $request->route();

        // Las páginas públicas (inicio, tiendas, marketplace) no piden sesión
        // y no se detienen: el candado es para lo que se usa con cuenta.
        if ($ruta === null || ! in_array('auth', $ruta->gatherMiddleware(), true)) {
            return true;
        }

        return in_array($ruta->getName(), self::RUTAS_WEB, true);
    }
}
