<?php

use App\Http\Controllers\Api\Distribuidora\ConfiguracionCicloController;
use App\Http\Controllers\Api\Distribuidora\ConfiguracionDistribuidoraController;
use App\Http\Controllers\Api\Distribuidora\PerfilController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete B — Bloque 1: Configuración de la Distribuidora (E3-01, E3-05, E3-06)
|--------------------------------------------------------------------------
|
| Convención de la sección 1.9: este archivo se agrega a routes/api.php con
| require, NO se pegan estas rutas directamente ahí, para evitar que dos
| personas choquen en el mismo archivo compartido.
|
| Recordatorio para quien mergee esto: agregar en routes/api.php
|   require __DIR__.'/api/distribuidora.php';
|
| Asume que 'role:admin_distribuidora' y el middleware que fija
| setPermissionsTeamId() (spatie/laravel-permission con teams, sección 1.8)
| ya existen desde Fase 0.
*/

Route::prefix('distribuidora')
    ->middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora'])
    ->group(function () {
        Route::get('perfil', [PerfilController::class, 'show']);
        Route::patch('perfil', [PerfilController::class, 'update']);

        Route::get('config', [ConfiguracionDistribuidoraController::class, 'show']);
        Route::patch('config', [ConfiguracionDistribuidoraController::class, 'update']);

        Route::get('ciclos-config', [ConfiguracionCicloController::class, 'index']);
        Route::post('ciclos-config', [ConfiguracionCicloController::class, 'store']);
        Route::get('ciclos-config/{ciclo}', [ConfiguracionCicloController::class, 'show']);
        Route::patch('ciclos-config/{ciclo}', [ConfiguracionCicloController::class, 'update']);
        Route::delete('ciclos-config/{ciclo}', [ConfiguracionCicloController::class, 'destroy']);
    });
