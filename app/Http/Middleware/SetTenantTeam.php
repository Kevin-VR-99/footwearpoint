<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SetTenantTeam
{
    // Debe correr DESPUÉS de auth:sanctum (necesita saber quién inició
    // sesión) y ANTES de cualquier role:... o permission:... en la ruta.
    // Reutiliza App\Support\Tenant::id(), que ya resuelve la distribuidora
    // del usuario autenticado vía distribuidora_staff — así no se duplica
    // esa lógica.
    public function handle(Request $request, Closure $next): Response
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(Tenant::id());

        return $next($request);
    }
}