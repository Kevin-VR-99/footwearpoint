<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Admin\DistribuidoraController;
use Illuminate\Support\Facades\Route;

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
});