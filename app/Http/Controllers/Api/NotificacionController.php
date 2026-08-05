<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificacionResource;
use App\Models\Notificacion;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificacionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $query = Notificacion::query()
            ->where('usuario_id', Auth::id())
            ->orderByDesc('created_at');

        if ($request->boolean('solo_no_leidas')) {
            $query->whereNull('leida_at');
        }

        $notificaciones = $query->limit(50)->get();

        return response()->json([
            'data' => NotificacionResource::collection($notificaciones),
        ]);
    }

    public function marcarLeida(int $id): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $notificacion = Notificacion::query()
            ->where('usuario_id', Auth::id())
            ->where('id', $id)
            ->firstOrFail();

        if ($notificacion->leida_at === null) {
            $notificacion->leida_at = now();
            $notificacion->save();
        }

        return response()->json([
            'data'    => new NotificacionResource($notificacion->fresh()),
            'message' => 'Notificación marcada como leída.',
        ]);
    }
}