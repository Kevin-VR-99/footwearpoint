<?php

namespace App\Http\Middleware;

use App\Models\DistribuidoraStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPermissionsTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $staff = DistribuidoraStaff::withoutGlobalScopes()
                ->where('usuario_id', $user->id)
                ->first();

            if ($staff) {
                setPermissionsTeamId($staff->distribuidora_id);
            } else {
                setPermissionsTeamId(0);
            }
        }

        return $next($request);
    }
}