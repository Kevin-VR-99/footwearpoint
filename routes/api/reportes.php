<?php

use App\Http\Controllers\Api\ReporteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::get('/reportes/resumen', [ReporteController::class, 'resumen']);
    });