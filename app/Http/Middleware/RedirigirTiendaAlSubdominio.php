<?php

namespace App\Http\Middleware;

use App\Models\Distribuidora;
use App\Services\Tienda\DominioTienda;
use App\Services\Tienda\EnlaceTienda;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TG-232 (G12/G13) — Con FOOTWEARPOINT_DOMINIO configurado, las direcciones
 * viejas /tienda/{slug} y /tienda/{slug}/productos/{id} redirigen (301) al
 * subdominio de la tienda, conservando los filtros (?marca=, ?q=, ...).
 *
 * Si la distribuidora no existe, no está activa o no puede tener subdominio,
 * sigue como antes (la propia tienda decide el 404).
 */
class RedirigirTiendaAlSubdominio
{
    public function __construct(private readonly EnlaceTienda $enlaces)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! DominioTienda::configurado()) {
            return $next($request);
        }

        $ruta = $request->route();
        $slug = (string) $ruta?->parameter('slug', '');
        $distribuidora = preg_match('/^[a-z0-9-]{1,120}$/', $slug) === 1
            ? Distribuidora::query()->where('slug', $slug)->where('estado', 'activa')->first()
            : null;

        if ($distribuidora === null || $this->enlaces->subdominio($distribuidora) === null) {
            return $next($request);
        }

        $producto = $ruta->parameter('productoCampana');
        $destino = $producto !== null
            ? (ctype_digit((string) $producto) ? $this->enlaces->producto($distribuidora, (int) $producto) : null)
            : $this->enlaces->url($distribuidora);

        if ($destino === null) {
            return $next($request);
        }

        $consulta = $request->getQueryString();

        return redirect()->away($destino.($consulta ? '?'.$consulta : ''), 301);
    }
}
