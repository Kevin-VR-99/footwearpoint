<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notificacion\RegistrarDispositivoFcmRequest;
use App\Services\Notificacion\GestionarDispositivoFcmAction;
use Illuminate\Http\JsonResponse;

class DispositivoFcmController extends Controller
{
    /**
     * POST /api/dispositivos-fcm (E16-03 / TG-136).
     *
     * La app lo llama después de iniciar sesión, y otra vez cada que Firebase
     * le renueve el token. Para quitarlo, se manda fcm_token al cerrar sesión
     * (ver AuthController::logout).
     */
    public function store(RegistrarDispositivoFcmRequest $request, GestionarDispositivoFcmAction $accion): JsonResponse
    {
        $datos = $request->validated();

        $dispositivo = $accion->registrar($request->user(), $datos['token'], $datos['plataforma']);

        // El token no se regresa: la app ya lo tiene, y no hace falta que
        // viaje de vuelta.
        return response()->json([
            'data' => [
                'id'            => $dispositivo->id,
                'plataforma'    => $dispositivo->plataforma,
                'ultimo_uso_at' => $dispositivo->ultimo_uso_at?->toIso8601String(),
            ],
            'message' => 'Dispositivo registrado para notificaciones.',
        ], $dispositivo->wasRecentlyCreated ? 201 : 200);
    }
}
