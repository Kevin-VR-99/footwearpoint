<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Admin\DistribuidoraController;
use App\Http\Controllers\Api\Admin\PlanSuscripcionController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json(['status' => 'ok']);
});

require __DIR__.'/api/distribuidora.php';

// Paquete C - stock local, venta directa y ciclos de compra
require __DIR__.'/api/stock.php';
require __DIR__.'/api/ventas-directas.php';
require __DIR__.'/api/ciclos.php';

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

    Route::post('/register-empleado', [AuthController::class, 'registerEmpleado'])
        ->middleware(['auth:sanctum', 'team', 'role:admin_distribuidora']);

    Route::post('/aceptar-legales', [AuthController::class, 'aceptarLegales'])
        ->middleware('auth:sanctum');

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::prefix('admin')->middleware(['auth:sanctum', 'team', 'role:admin_general'])->group(function () {
    Route::get('/distribuidoras', [DistribuidoraController::class, 'index']);
    Route::post('/distribuidoras/{id}/aprobar', [DistribuidoraController::class, 'aprobar']);
    Route::post('/distribuidoras/{id}/suspender', [DistribuidoraController::class, 'suspender']);
    Route::post('/distribuidoras/{id}/reactivar', [DistribuidoraController::class, 'reactivar']);

    Route::get('/planes-suscripcion', [PlanSuscripcionController::class, 'index']);
    Route::get('/planes-suscripcion/{id}', [PlanSuscripcionController::class, 'show']);
    Route::post('/planes-suscripcion', [PlanSuscripcionController::class, 'store']);
    Route::put('/planes-suscripcion/{id}', [PlanSuscripcionController::class, 'update']);
    Route::delete('/planes-suscripcion/{id}', [PlanSuscripcionController::class, 'destroy']);

    Route::post('/distribuidoras/{id}/suscripcion', [DistribuidoraController::class, 'asignarSuscripcion']);
    Route::patch('/marketplace/config', [DistribuidoraController::class, 'marketplaceConfig']);
});

