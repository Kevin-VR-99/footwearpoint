<?php

use App\Http\Controllers\Api\DispositivoFcmController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Registro de dispositivos para notificaciones push (E16-03 / TG-136)
|--------------------------------------------------------------------------
|
| Solo auth:sanctum: cada usuario registra su propio celular. Los tokens de
| la API solo los tienen revendedor y cliente directo, porque el login le
| niega el acceso al personal interno (TG-93).
|
| Para quitar un dispositivo no hay ruta aparte: se manda fcm_token en
| POST /api/auth/logout, así se hace en la misma llamada que cierra la sesión.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/dispositivos-fcm', [DispositivoFcmController::class, 'store']);
});
