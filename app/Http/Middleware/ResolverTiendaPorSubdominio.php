<?php

namespace App\Http\Middleware;

use App\Services\Tienda\TiendaPublica;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TG-232 (G13) — Reconoce la distribuidora por la dirección con la que se
 * entra: {subdominio}.{FOOTWEARPOINT_DOMINIO}. Busca primero por subdominio y
 * luego por slug, solo activas (TiendaPublica::porSubdominio).
 *
 * Si no hay tienda (no existe, no está activa, nombre reservado o inválido)
 * responde un 404 amigable con enlace al directorio, sin decir el motivo.
 *
 * La tienda sigue trabajando con el slug: aquí solo se cambia el parámetro
 * {subdominio} de la ruta por el {slug} de la distribuidora.
 */
class ResolverTiendaPorSubdominio
{
    public function __construct(private readonly TiendaPublica $tienda)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $ruta = $request->route();
        $etiqueta = (string) $ruta?->parameter('subdominio', '');

        $distribuidora = $etiqueta !== '' ? $this->tienda->porSubdominio($etiqueta) : null;

        if ($distribuidora === null) {
            return response()->view('errors.tienda-no-encontrada', [], 404);
        }

        $ruta->forgetParameter('subdominio');
        $ruta->setParameter('slug', (string) $distribuidora->slug);

        return $next($request);
    }
}
