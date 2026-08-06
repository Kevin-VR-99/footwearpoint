<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Admin\DistribuidoraController;
use App\Http\Controllers\Api\Admin\PlanSuscripcionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health
|--------------------------------------------------------------------------
*/

Route::get('/ping', function () {
    return response()->json(['status' => 'ok']);
});

/*
|--------------------------------------------------------------------------
| Auth (Paquete A)
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');

    Route::post('/register-empleado', [AuthController::class, 'registerEmpleado'])
        ->middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora']);

    Route::post('/aceptar-legales', [AuthController::class, 'aceptarLegales'])
        ->middleware('auth:sanctum');

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

/*
|--------------------------------------------------------------------------
| Admin general (Paquete A)
|--------------------------------------------------------------------------
*/

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'tenant.team', 'role:admin_general'])
    ->group(function () {
        Route::get('/distribuidoras', [DistribuidoraController::class, 'index']);
        Route::post('/distribuidoras/{id}/aprobar', [DistribuidoraController::class, 'aprobar']);
        Route::post('/distribuidoras/{id}/suspender', [DistribuidoraController::class, 'suspender']);
        Route::post('/distribuidoras/{id}/reactivar', [DistribuidoraController::class, 'reactivar']);
        Route::post('/distribuidoras/{id}/suscripcion', [DistribuidoraController::class, 'asignarSuscripcion']);

        Route::get('/planes-suscripcion', [PlanSuscripcionController::class, 'index']);
        Route::get('/planes-suscripcion/{id}', [PlanSuscripcionController::class, 'show']);
        Route::post('/planes-suscripcion', [PlanSuscripcionController::class, 'store']);
        Route::put('/planes-suscripcion/{id}', [PlanSuscripcionController::class, 'update']);
        Route::delete('/planes-suscripcion/{id}', [PlanSuscripcionController::class, 'destroy']);

        Route::patch('/marketplace/config', [DistribuidoraController::class, 'marketplaceConfig']);
    });

/*
|--------------------------------------------------------------------------
| Módulos por archivo (un require por paquete)
|--------------------------------------------------------------------------
*/

// Paquete B — perfil, config, clientes, revendedores, empleados
require __DIR__.'/api/distribuidora.php';

// Paquete B — catálogo
require __DIR__.'/api/catalogo.php';

// Paquete C — stock, ventas directas, ciclos
require __DIR__.'/api/stock.php';
require __DIR__.'/api/ventas-directas.php';
require __DIR__.'/api/ciclos.php';

// Paquete D — pedidos
require __DIR__.'/api/pedidos.php';

// Paquete E — marketplace, vales, notificaciones
require __DIR__.'/api/marketplace.php';
require __DIR__.'/api/vales.php';
require __DIR__.'/api/notificaciones.php';

require __DIR__.'/api/reportes.php';