<?php

use App\Http\Controllers\Api\ReporteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::get('/reportes/resumen', [ReporteController::class, 'resumen']);

        // TG-251: pedidos y montos por cliente mayorista (revendedor).
        Route::get('/reportes/pedidos-por-cliente-mayorista', [ReporteController::class, 'pedidosPorClienteMayorista']);

        // TG-252: montos pendientes por cliente y cliente mayorista, y vales vigentes.
        Route::get('/reportes/montos-pendientes', [ReporteController::class, 'montosPendientes']);
    });