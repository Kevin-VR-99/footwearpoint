<?php

use App\Http\Controllers\Api\ValeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete E — Vales (emisión, listado y aplicar)
|--------------------------------------------------------------------------
|
| El grupo está partido en dos a propósito (TG-134):
|
| - EMITIR un vale es entregar saldo, y eso solo lo hace la distribuidora.
|   Si un cliente pudiera emitir, se regalaría saldo a sí mismo.
| - CONSULTAR y APLICAR sí los hace el dueño del vale desde la app. Cada
|   quien ve y aplica solo los suyos: ver App\Support\PropietarioActual.
*/

// --- Solo la distribuidora ---
Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::post('/vales', [ValeController::class, 'store']);
    });

// --- También revendedor y cliente directo, desde la app ---
Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado|revendedor|cliente_directo'])
    ->group(function () {
        Route::get('/vales', [ValeController::class, 'index']);
        Route::post('/vales/{id}/aplicar', [ValeController::class, 'aplicar']);
    });
