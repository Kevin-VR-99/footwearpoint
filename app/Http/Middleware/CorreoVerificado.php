<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TG-189 (A11 / E1-03) — Las acciones delicadas piden el correo confirmado.
 *
 * El criterio de E1-03 dice: "ciertas acciones sensibles (pagos, pedidos)
 * requieren cuenta verificada". Aquí "sensible" no es cualquier cosa: es
 * ENVIAR un pedido y pagarlo. Armar el borrador y mirar el catálogo se
 * pueden hacer sin confirmar, para que nadie se tope con un muro antes de
 * entender qué le piden.
 *
 * Solo aplica a quien usa la app (cliente mayorista y cliente). Al personal
 * de mostrador lo dio de alta la distribuidora en persona; bloquearlo sería
 * frenar el negocio por un correo.
 *
 * Se puede apagar con AUTH_VERIFICACION_OBLIGATORIA=false, porque las cuentas
 * que ya existían tienen el correo sin confirmar.
 */
class CorreoVerificado
{
    public const MENSAJE = 'Confirma tu correo para poder enviar pedidos. Te mandamos un enlace cuando te dieron la cuenta; si no lo encuentras, pide otro desde tu perfil.';

    /** A quién se le pide. El personal no entra aquí. */
    private const ROLES = ['revendedor', 'cliente_directo'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth.verification.obligatoria')) {
            return $next($request);
        }

        $usuario = $request->user();

        if ($usuario === null || $usuario->hasVerifiedEmail() || ! $this->leAplica($usuario)) {
            return $next($request);
        }

        return response()->json([
            'message'          => self::MENSAJE,
            'correo_sin_verificar' => true,
        ], 403);
    }

    private function leAplica($usuario): bool
    {
        foreach (self::ROLES as $rol) {
            if ($usuario->hasRole($rol)) {
                return true;
            }
        }

        return false;
    }
}
