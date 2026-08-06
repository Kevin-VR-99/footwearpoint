<?php

use App\Http\Controllers\Api\ValeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete E — Vales versión simple (E12) — solo emisión y listado
|--------------------------------------------------------------------------
| NO incluye POST /api/vales/{folio}/aplicar (depende de C/D).
*/

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::get('/vales', [ValeController::class, 'index']);
        Route::post('/vales', [ValeController::class, 'store']);
        Route::post('/vales/{id}/aplicar', [ValeController::class, 'aplicar']);
    });