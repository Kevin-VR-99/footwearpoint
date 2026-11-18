<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use App\Services\Auth\AccesoPanelWebService;
use App\Support\PersonalPlataforma;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * TG-336 (K38) — En el panel, la contraseña temporal se cambia antes de seguir.
 *
 * Es el equivalente web de DebeCambiarPassword, que solo cuida la API. Solo
 * detiene a quien sí puede usar el panel: a los demás los saca 'solo.personal'
 * con su propio mensaje.
 */
class PanelDebeCambiarPassword
{
    public const MENSAJE = 'Tienes una contraseña temporal. Cámbiala para poder seguir.';

    private const RUTAS_PERMITIDAS = [
        'password.cambiar',
        // Aceptar los documentos legales va primero, igual que en la app.
        'legales.aceptar',
        'logout',
        'aviso-privacidad',
        'terminos',
        'verification.verify',
    ];

    public function __construct(private AccesoPanelWebService $acceso)
    {
    }

    /** Devuelve mixed por lo mismo que LegalesVigentes: el Redirector de Livewire. */
    public function handle(Request $request, Closure $next): mixed
    {
        // La petición de Livewire en sí pasa: este middleware también corre
        // como persistente, con la ruta de la página donde vive el componente.
        if (str_contains((string) $request->route()?->getName(), 'livewire.')) {
            return $next($request);
        }

        $usuario = Auth::user();

        if (! $usuario?->debe_cambiar_password || $this->permitida($request) || ! $this->usaElPanel($usuario)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MENSAJE, 'debe_cambiar_password' => true], 403);
        }

        return redirect()->route('password.cambiar');
    }

    private function permitida(Request $request): bool
    {
        $ruta = $request->route();

        // Las páginas públicas no piden sesión y no se detienen.
        if ($ruta === null || ! in_array('auth', $ruta->gatherMiddleware(), true)) {
            return true;
        }

        return in_array($ruta->getName(), self::RUTAS_PERMITIDAS, true);
    }

    /** Sin AccesoPanelWebService::esAdminGeneral(), que cambia el equipo de permisos. */
    private function usaElPanel(Usuario $usuario): bool
    {
        if (PersonalPlataforma::esAdminGeneral($usuario)) {
            return true;
        }

        $distribuidora = $this->acceso->staffActivo($usuario)?->distribuidora;

        return $distribuidora !== null
            && ($distribuidora->puedeOperar() || $distribuidora->suspendidaPorFaltaDePago());
    }
}
