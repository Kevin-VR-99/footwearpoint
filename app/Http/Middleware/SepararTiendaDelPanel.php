<?php

namespace App\Http\Middleware;

use App\Services\Tienda\DominioTienda;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * TG-232 (G12/G13) — En el subdominio de una tienda solo vive la tienda.
 *
 * - Todo lo que se arma con url()/route() en esa petición (incluidos el
 *   script de Livewire y su dirección de actualización) sale con el MISMO
 *   host del subdominio. AppServiceProvider fuerza APP_URL como raíz; si no
 *   se cambiara aquí, Livewire mandaría sus peticiones al dominio principal
 *   (otro origen, otra cookie) y los filtros de la tienda no funcionarían.
 * - El panel, el login y lo demás de la web redirigen al dominio principal
 *   (APP_URL): la sesión del panel nunca vive en un subdominio.
 * - La API responde 404 en un subdominio: la app usa APP_URL.
 *
 * Sin FOOTWEARPOINT_DOMINIO, o en el dominio principal, no hace nada.
 */
class SepararTiendaDelPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DominioTienda::etiquetaDelHost($request->getHost()) === null) {
            return $next($request);
        }

        if ($request->is('api', 'api/*')) {
            abort(404);
        }

        // Va como middleware global (antes de las rutas y de 'auth'): en el
        // subdominio solo se atiende la tienda ('/' y '/productos/{id}') y
        // lo de Livewire (script y actualizaciones, /livewire-xxxx/...).
        $esDeLaTienda = $request->is('/', 'productos/*', 'livewire*');

        if (! $esDeLaTienda) {
            return redirect()->away(DominioTienda::principal().$request->getRequestUri());
        }

        URL::forceRootUrl($request->getSchemeAndHttpHost());

        try {
            return $next($request);
        } finally {
            // Igual que AppServiceProvider, para la siguiente petición del
            // mismo proceso (pruebas, servidores que reutilizan el proceso).
            $appUrl = DominioTienda::principal();
            URL::forceRootUrl(str_starts_with($appUrl, 'https://') ? $appUrl : null);
        }
    }
}
