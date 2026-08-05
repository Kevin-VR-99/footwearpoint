<?php

use App\Http\Controllers\Api\CicloCompraController;
use Illuminate\Support\Facades\Route;

/*
| Paquete C - Ciclos de compra (E10)
| Mismo stack de middleware que routes/api/stock.php y distribuidora.php.
| Recordatorio para quien mergee: agregar en routes/api.php
|   require __DIR__.'/api/ciclos.php';
*/

Route::prefix('ciclos')
    ->middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        // Va ANTES de {id} para que 'vigente' no se lea como un identificador.
        Route::get('vigente', [CicloCompraController::class, 'vigente']);

        Route::get('{id}', [CicloCompraController::class, 'show'])->whereNumber('id');

        Route::post('{id}/cerrar', [CicloCompraController::class, 'cerrar'])->whereNumber('id');
        Route::post('{id}/solicitar-fabrica', [CicloCompraController::class, 'solicitarFabrica'])->whereNumber('id');
        Route::post('{id}/marcar-transito', [CicloCompraController::class, 'marcarTransito'])->whereNumber('id');
        Route::post('{id}/marcar-recibido', [CicloCompraController::class, 'marcarRecibido'])->whereNumber('id');
        Route::post('{id}/finalizar', [CicloCompraController::class, 'finalizar'])->whereNumber('id');
    });