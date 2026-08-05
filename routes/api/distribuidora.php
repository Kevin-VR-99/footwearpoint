<?php

use App\Http\Controllers\Api\Distribuidora\ClienteDirectoController;
use App\Http\Controllers\Api\Distribuidora\ConfiguracionCicloController;
use App\Http\Controllers\Api\Distribuidora\ConfiguracionDistribuidoraController;
use App\Http\Controllers\Api\Distribuidora\EmpleadoController;
use App\Http\Controllers\Api\Distribuidora\PerfilController;
use App\Http\Controllers\Api\Distribuidora\RevendedorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete B — Configuración de la Distribuidora (E3)
|--------------------------------------------------------------------------
|
| Bloque 1: perfil, config general, ciclos de compra (E3-01, E3-06, E3-05).
| Bloque 2: revendedores y clientes directos.
| Empleados (E3-03): acordado con Paquete A — el ALTA (con cuenta y
| contraseña) vive en POST /api/auth/register-empleado (Paquete A). Aquí
| solo se lista y se activa/desactiva. NO agregar aquí un POST de creación.
|
| Recordatorio: en routes/api.php debe existir
|   require __DIR__.'/api/distribuidora.php';
*/

Route::prefix('distribuidora')
    ->middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora'])
    ->group(function () {
        // --- Bloque 1 ---
        Route::get('perfil', [PerfilController::class, 'show']);
        Route::patch('perfil', [PerfilController::class, 'update']);

        Route::get('config', [ConfiguracionDistribuidoraController::class, 'show']);
        Route::patch('config', [ConfiguracionDistribuidoraController::class, 'update']);

        Route::get('ciclos-config', [ConfiguracionCicloController::class, 'index']);
        Route::post('ciclos-config', [ConfiguracionCicloController::class, 'store']);
        Route::get('ciclos-config/{ciclo}', [ConfiguracionCicloController::class, 'show']);
        Route::patch('ciclos-config/{ciclo}', [ConfiguracionCicloController::class, 'update']);
        Route::delete('ciclos-config/{ciclo}', [ConfiguracionCicloController::class, 'destroy']);

        // --- Bloque 2 ---
        Route::get('revendedores', [RevendedorController::class, 'index']);
        Route::post('revendedores', [RevendedorController::class, 'store']);
        Route::get('revendedores/{revendedor}', [RevendedorController::class, 'show']);
        Route::patch('revendedores/{revendedor}', [RevendedorController::class, 'update']);

        Route::get('clientes-directos', [ClienteDirectoController::class, 'index']);
        Route::post('clientes-directos', [ClienteDirectoController::class, 'store']);
        Route::get('clientes-directos/{cliente}', [ClienteDirectoController::class, 'show']);
        Route::patch('clientes-directos/{cliente}', [ClienteDirectoController::class, 'update']);

        // --- Empleados (E3-03) ---
        // Alta con cuenta/contraseña: ver POST /api/auth/register-empleado (Paquete A).
        Route::get('empleados', [EmpleadoController::class, 'index']);
        Route::get('empleados/{empleado}', [EmpleadoController::class, 'show']);
        Route::patch('empleados/{empleado}', [EmpleadoController::class, 'update']);
    });
