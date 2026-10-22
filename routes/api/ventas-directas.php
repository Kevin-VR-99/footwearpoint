<?php

use App\Http\Controllers\Api\VentaDirectaController;
use Illuminate\Support\Facades\Route;

/*
| Paquete C - Venta directa (E7)
| Recordatorio para quien mergee: agregar en routes/api.php
|   require __DIR__.'/api/ventas-directas.php';
*/

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::post('ventas-directas', [VentaDirectaController::class, 'store']);
        // Cancelar una venta de mostrador (permiso fino ventas.cancelar, se revisa en la acción).
        Route::post('ventas-directas/{id}/anular', [VentaDirectaController::class, 'anular'])->whereNumber('id');
    });