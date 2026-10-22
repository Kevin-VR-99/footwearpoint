<?php

use App\Http\Controllers\Api\SolicitudCambioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| TG-254 (R12, E12-01) — Solicitudes de cambio de producto
|--------------------------------------------------------------------------
| El cliente (directo o mayorista) pide el cambio de lo suyo desde la app;
| el personal puede pedirlo por él. Cada quien solo ve lo que le toca
| (PropietarioActual). Autorizar y emitir el vale es de otra tarea.
*/
Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado|revendedor|cliente_directo'])
    ->group(function () {
        Route::get('/solicitudes-cambio', [SolicitudCambioController::class, 'index']);
        Route::post('/solicitudes-cambio', [SolicitudCambioController::class, 'store']);
        Route::get('/solicitudes-cambio/{id}', [SolicitudCambioController::class, 'show'])->whereNumber('id');
    });

// Resolver la solicitud: solo el personal.
Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado', 'throttle:60,1'])
    ->post('/solicitudes-cambio/{id}/estado', [SolicitudCambioController::class, 'cambiarEstado'])->whereNumber('id');
