<?php

use App\Http\Controllers\Api\NotificacionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete E — Notificaciones internas (E16) — solo bandeja
|--------------------------------------------------------------------------
| No inserta notificaciones al cambiar pedidos.estado (enganche con D).
*/

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::get('/notificaciones', [NotificacionController::class, 'index']);
        Route::post('/notificaciones/{id}/marcar-leida', [NotificacionController::class, 'marcarLeida']);
    });