<?php

use App\Http\Controllers\Api\PedidoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete D — Pedidos (fase segura)
|--------------------------------------------------------------------------
| Commit 1: solo listado y detalle.
| Crear / líneas / enviar llegan en commits siguientes.
*/

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::get('/pedidos', [PedidoController::class, 'index']);
        Route::get('/pedidos/{id}', [PedidoController::class, 'show']);
        Route::post('/pedidos', [PedidoController::class, 'store']);
        Route::post('/pedidos/{id}/lineas', [PedidoController::class, 'agregarLinea']);
        Route::post('/pedidos/{id}/enviar', [PedidoController::class, 'enviar']);
    });