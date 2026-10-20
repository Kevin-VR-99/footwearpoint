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

    /**
     * TG-281 (E19-11) — Quitar un aviso de la bandeja.
     *
     * Se borra de verdad, no se esconde: cada renglón de notificaciones es de
     * UNA persona (usuario_id), así que no se le quita el aviso a nadie más,
     * y una bandeja no es una bitácora. Lo que pasó de verdad —el pedido, el
     * pago, el vale— sigue en su tabla y en la auditoría.
     *
     * El filtro por usuario_id es lo que impide borrar la notificación de
     * otra persona: si no es suya, responde 404 y no dice si existe.
     */
    public function destroy(int $id): JsonResponse
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $notificacion = Notificacion::query()
            ->where('usuario_id', Auth::id())
            ->where('id', $id)
            ->firstOrFail();

        $notificacion->delete();

        return response()->json(['message' => 'Aviso borrado.']);
    }
}