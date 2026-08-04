<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');

    Route::post('/aceptar-legales', [AuthController::class, 'aceptarLegales'])
        ->middleware('auth:sanctum');

    Route::post('/register-empleado', [AuthController::class, 'registerEmpleado'])
        ->middleware(['auth:sanctum', 'team', 'role:admin_distribuidora']);

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});
