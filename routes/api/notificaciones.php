<?php

use App\Http\Controllers\Api\NotificacionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete E — Notificaciones internas (E16) — solo bandeja
|--------------------------------------------------------------------------
| No inserta notificaciones al cambiar pedidos.estado (enganche con D).
*/

// Desde Sprint 3 (TG-134) también entran revendedor y cliente directo: es la
// bandeja donde ven los avisos de su pedido desde la app.
// El controlador ya filtra por usuario_id, así que cada quien ve solo las
// suyas, nunca las de otro usuario de la misma distribuidora.
Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado|revendedor|cliente_directo'])
    ->group(function () {
        Route::get('/notificaciones', [NotificacionController::class, 'index']);
        Route::post('/notificaciones/{id}/marcar-leida', [NotificacionController::class, 'marcarLeida']);
        // TG-281 (E19-11): quitar un aviso de la bandeja deslizandolo en la
        // app. Es la bandeja de UNA persona (notificaciones.usuario_id), asi
        // que borrar no le quita el aviso a nadie mas.
        Route::delete('/notificaciones/{id}', [NotificacionController::class, 'destroy'])
            ->whereNumber('id');
    });