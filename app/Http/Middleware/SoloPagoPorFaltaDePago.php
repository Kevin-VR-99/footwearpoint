<?php

namespace App\Http\Middleware;

use App\Services\Auth\AccesoPanelWebService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una distribuidora suspendida por falta de pago solo puede ver la pantalla
 * de pago y cerrar sesión. La suspensión manual sigue cerrando todo el panel.
 */
class SoloPagoPorFaltaDePago
{
    public const MENSAJE = 'Tu distribuidora está suspendida por falta de pago. '
        .'El administrador puede pagarla desde el panel web para reactivarla.';

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

        if (! $esApi && str_contains((string) $request->route()?->getName(), 'livewire.')) {
            return $next($request);
        }

        $usuario = $esApi ? ($request->user('sanctum') ?? $request->user()) : $request->user();

        if ($usuario === null || $this->permitida($request, $esApi)) {
            return $next($request);
        }

        $staff = $this->acceso->staffActivo($usuario);
        $distribuidora = $staff?->distribuidora;

        if ($distribuidora === null || ! $distribuidora->suspendidaPorFaltaDePago()) {
            return $next($request);
        }

        if ($esApi || $request->expectsJson()) {
            return response()->json([
                'message' => self::MENSAJE,
                'suspension_falta_pago' => true,
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
