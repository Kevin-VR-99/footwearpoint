<?php

use App\Http\Controllers\Api\AccesoController;
use Illuminate\Support\Facades\Route;

/*
| feature/funciones-completas — rutas nuevas de la API, en su propio archivo.
*/

// Bajas y reactivaciones de clientes mayoristas, clientes directos y empleados.
Route::prefix('distribuidora/accesos')
    ->middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora', 'throttle:60,1'])
    ->group(function () {
        Route::post('{tipo}/{id}/baja', [AccesoController::class, 'baja'])->whereIn('tipo', ['revendedor', 'cliente', 'empleado'])->whereNumber('id');
        Route::post('{tipo}/{id}/reactivar', [AccesoController::class, 'reactivar'])->whereIn('tipo', ['revendedor', 'cliente', 'empleado'])->whereNumber('id');
    });

// Comisiones y metas.
Route::middleware(['auth:sanctum', 'tenant.team', 'throttle:60,1'])->group(function () {
    Route::get('revendedor/comisiones', [\App\Http\Controllers\Api\ComisionController::class, 'mias'])->middleware('role:revendedor');
    Route::get('distribuidora/comisiones', [\App\Http\Controllers\Api\ComisionController::class, 'ranking'])->middleware('role:admin_distribuidora|empleado');
});
