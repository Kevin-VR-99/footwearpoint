<?php

namespace App\Http\Middleware;

use App\Services\Auth\AccesoPanelWebService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * TG-184 (A1) — Cuida las rutas del panel web.
 *
 * Si quien tiene la sesion no es personal (un revendedor o un cliente que
 * entro antes de esta correccion, o que escribe la direccion a mano), se le
 * cierra la sesion y se le regresa al login con un mensaje en espanol que le
 * dice que use la app. No se responde 403 a secas: la idea es decirle a donde
 * ir, no solo negarle el paso.
 *
 * TG-195 (G4): tambien saca al personal de una distribuidora rechazada, con
 * un aviso que incluye el motivo del rechazo.
 *
 * Este middleware NO cubre /logout a proposito: si lo cubriera, alguien sin
 * acceso quedaria atrapado sin poder salir, que es justo lo que pasa hoy
 * cuando se desactiva a un empleado con la sesion abierta.
 */
class SoloPersonalPanel
{
    public function __construct(private AccesoPanelWebService $acceso)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::user();
        $motivo = $usuario ? $this->acceso->motivoSinAcceso($usuario) : null;

        if ($motivo !== null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with('aviso_acceso', $motivo);
        }

        return $next($request);
    }
}
