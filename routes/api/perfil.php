<?php

use App\Http\Controllers\Api\PerfilUsuarioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Perfil del usuario que inició sesión (E1-05 / TG-110)
|--------------------------------------------------------------------------
|
| Ojo: NO es api/distribuidora/perfil, que es el perfil de la distribuidora
| y solo lo usa su admin. Aquí cada quien ve y edita SUS propios datos.
|
| Solo auth:sanctum, igual que auth/me: sin tenant.team ni role:..., porque
| tiene que servirle a cualquier rol, incluido un revendedor con la afiliación
| suspendida (distribuidora null), que sigue siendo dueño de su cuenta.
*/

Route::prefix('perfil')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [PerfilUsuarioController::class, 'show']);
    Route::patch('/', [PerfilUsuarioController::class, 'update']);
    Route::post('/password', [PerfilUsuarioController::class, 'cambiarPassword']);
});
