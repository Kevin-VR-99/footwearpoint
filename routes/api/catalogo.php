<?php

use App\Http\Controllers\Api\Catalogo\CategoriaProductoController;
use App\Http\Controllers\Api\Catalogo\MarcaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete B — Catálogo (E4)
|--------------------------------------------------------------------------
|
| El documento de tareas nombra estas rutas directamente bajo /api/ (no bajo
| /api/distribuidora/), por eso viven en su propio archivo — igual que
| routes/api/distribuidora.php, se agrega con require desde routes/api.php.
|
| Bloque 3a: marcas y categorías.
| (Bloques 3b-3e: campañas, productos, producto-campana, variantes,
| catálogo consultable — se agregan a este mismo archivo más adelante.)
|
| Recordatorio: en routes/api.php debe existir
|   require __DIR__.'/api/catalogo.php';
*/

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora'])->group(function () {
    Route::get('marcas', [MarcaController::class, 'index']);
    Route::post('marcas', [MarcaController::class, 'store']);
    Route::get('marcas/{marca}', [MarcaController::class, 'show']);
    Route::patch('marcas/{marca}', [MarcaController::class, 'update']);

    Route::get('categorias-producto', [CategoriaProductoController::class, 'index']);
    Route::post('categorias-producto', [CategoriaProductoController::class, 'store']);
    Route::get('categorias-producto/{categoria}', [CategoriaProductoController::class, 'show']);
    Route::patch('categorias-producto/{categoria}', [CategoriaProductoController::class, 'update']);
});
