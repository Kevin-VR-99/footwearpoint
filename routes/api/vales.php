<?php

use App\Http\Controllers\Api\ValeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete E — Vales (emisión, listado y aplicar)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::get('/vales', [ValeController::class, 'index']);
        Route::post('/vales', [ValeController::class, 'store']);
        Route::post('/vales/{id}/aplicar', [ValeController::class, 'aplicar']);
    });