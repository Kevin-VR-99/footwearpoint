<?php

namespace App\Support;

use App\Services\Tienda\DominioTienda;
use Throwable;

/**
 * TG-264 (U4) - A dónde lleva el botón "Volver al inicio" de las páginas de
 * error.
 *
 * - En el subdominio de una tienda: al inicio de esa tienda.
 * - Con sesión en el dominio principal: a su panel (PanelDelUsuario).
 * - Si no: al inicio del dominio principal.
 *
 * Nunca falla: la página de error puede estar mostrando justo un 500.
 */
final class InicioParaError
{
    public static function url(): string
    {
        try {
            $request = request();

            if (DominioTienda::etiquetaDelHost($request->getHost()) !== null) {
                return $request->getSchemeAndHttpHost();
            }

            $usuario = auth()->user();

            if ($usuario instanceof \App\Models\Usuario) {
                return PanelDelUsuario::para($usuario)['url'];
            }
        } catch (Throwable) {
            // Sin sesión utilizable o sin base de datos: se usa el principal.
        }

        return DominioTienda::principal() ?: url('/');
    }
}
