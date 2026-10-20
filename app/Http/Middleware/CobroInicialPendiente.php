<?php

namespace App\Http\Middleware;

use App\Models\Suscripcion;
use App\Services\Auth\AccesoPanelWebService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * TG-220 — Cobro antes de acceder.
 *
 * Una distribuidora aprobada que todavía no paga su primer mes solo puede ver
 * la pantalla de pago (distribuidora.pago-inicial) y cerrar sesión. Todo lo
 * demás del panel redirige ahí; en la API responde 402 con un mensaje claro.
 *
 * Corre en los grupos 'web' y 'api' y es middleware persistente de Livewire,
 * así que las acciones de los componentes también quedan bloqueadas (se
 * revisan con la ruta de la página donde vive el componente).
 */
class CobroInicialPendiente
{
    public const MENSAJE = 'Para empezar a usar FootwearPoint, tu distribuidora debe pagar su primer mes. '
        .'El administrador puede pagarlo desde el panel web.';

    /** Rutas web que siguen abiertas mientras falta el primer pago. */
    private const RUTAS_WEB = [
        'distribuidora.pago-inicial',
        'logout',
        'login',
        'mercado-pago.webhook',
        'mercado-pago.retorno',
        'verification.verify',
        'password.request',
        'password.reset',
    ];

    /** Lo único que la API deja hacer (sesión, contraseña, salir y avisos de MP). */
    private const RUTAS_API = [
        'api/auth/me',
        'api/auth/logout',
        'api/perfil',
        'api/perfil/password',
    ];

    public function __construct(private AccesoPanelWebService $acceso)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $esApi = $request->is('api/*');

        // La petición de Livewire en sí se deja pasar: el mismo middleware
        // corre como persistente con la ruta de la página original.
        if (! $esApi && str_contains((string) $request->route()?->getName(), 'livewire.')) {
            return $next($request);
        }

        $usuario = $esApi ? ($request->user('sanctum') ?? Auth::user()) : Auth::user();

        if ($usuario === null || $this->permitida($request, $esApi)) {
            return $next($request);
        }

        $staff = $this->acceso->staffActivo($usuario);

        if ($staff === null || $staff->distribuidora?->estado !== 'activa'
            || Suscripcion::pendientePrimerPago((int) $staff->distribuidora_id) === null) {
            return $next($request);
        }

        if ($esApi || $request->expectsJson()) {
            return response()->json([
                'message'                => self::MENSAJE,
                'pago_inicial_pendiente' => true,
            ], 402);
        }

        return redirect()->route('distribuidora.pago-inicial');
    }

    private function permitida(Request $request, bool $esApi): bool
    {
        if ($esApi) {
            $ruta = trim($request->path(), '/');

            return in_array($ruta, self::RUTAS_API, true) || str_starts_with($ruta, 'api/webhooks');
        }

        $nombre = $request->route()?->getName();

        return $nombre !== null && in_array($nombre, self::RUTAS_WEB, true);
    }
}
