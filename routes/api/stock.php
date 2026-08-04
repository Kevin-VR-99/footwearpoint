<?php

use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

/*
| Paquete C - Stock local (E6)
| Mismo stack de middleware que routes/api/distribuidora.php del Paquete B.
| Recordatorio para quien mergee: agregar en routes/api.php
|   require __DIR__.'/api/stock.php';
*/

Route::prefix('stock')
    ->middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::get('/', [StockController::class, 'index']);
        Route::post('entradas', [StockController::class, 'storeEntrada']);
        Route::post('ajustes', [StockController::class, 'storeAjuste']);
    });