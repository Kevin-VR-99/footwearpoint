<?php

use App\Http\Controllers\Api\PedidoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete D — Pedidos
|--------------------------------------------------------------------------
|
| Desde Sprint 3 (TG-134) también entran revendedor y cliente directo, que
| arman y envían su propio pedido desde la app móvil.
|
| Cada quien ve y toca SOLO sus pedidos — de eso se encarga
| App\Support\PropietarioActual dentro del controlador, no el middleware.
| El empleado conserva el acceso a todos los de su distribuidora, porque
| sigue capturando pedidos en el mostrador.
|
| Al crear un pedido, el dueño se toma del usuario autenticado y se ignora
| lo que venga en la petición (ver CrearPedidoBorradorAction).
|
| E8-02 / TG-31: registrar anticipo o saldo es solo personal de mostrador
| (admin_distribuidora | empleado). No va en el grupo móvil.
*/

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado|revendedor|cliente_directo'])
    ->group(function () {
        Route::get('/pedidos', [PedidoController::class, 'index']);
        Route::get('/pedidos/{id}', [PedidoController::class, 'show']);
        Route::post('/pedidos', [PedidoController::class, 'store']);
        Route::post('/pedidos/{id}/lineas', [PedidoController::class, 'agregarLinea']);
        Route::post('/pedidos/{id}/enviar', [PedidoController::class, 'enviar']);
        Route::delete('/pedidos/{pedidoId}/lineas/{lineaId}', [PedidoController::class, 'quitarLinea']);
    });

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::post('/pedidos/{id}/pagos', [PedidoController::class, 'registrarPago']);
    });