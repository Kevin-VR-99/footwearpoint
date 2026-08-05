<?php

use App\Http\Controllers\Api\Catalogo\CampanaController;
use App\Http\Controllers\Api\Catalogo\CategoriaProductoController;
use App\Http\Controllers\Api\Catalogo\MarcaController;
use App\Http\Controllers\Api\Catalogo\ProductoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete B — Catálogo (E4)
|--------------------------------------------------------------------------
|
| Bloque 3a: marcas y categorías.
| Bloque 3b: campañas y productos.
| (Bloques 3c-3e: producto-campana, imágenes, variantes, disponibilidad,
| catálogo consultable — se agregan a este mismo archivo más adelante.)
|
| Recordatorio: en routes/api.php debe existir
|   require __DIR__.'/api/catalogo.php';
*/

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora'])->group(function () {
    // --- Bloque 3a ---
    Route::get('marcas', [MarcaController::class, 'index']);
    Route::post('marcas', [MarcaController::class, 'store']);
    Route::get('marcas/{marca}', [MarcaController::class, 'show']);
    Route::patch('marcas/{marca}', [MarcaController::class, 'update']);

    Route::get('categorias-producto', [CategoriaProductoController::class, 'index']);
    Route::post('categorias-producto', [CategoriaProductoController::class, 'store']);
    Route::get('categorias-producto/{categoria}', [CategoriaProductoController::class, 'show']);
    Route::patch('categorias-producto/{categoria}', [CategoriaProductoController::class, 'update']);

    // --- Bloque 3b ---
    Route::get('campanas', [CampanaController::class, 'index']);
    Route::post('campanas', [CampanaController::class, 'store']);
    Route::get('campanas/{campana}', [CampanaController::class, 'show']);
    Route::patch('campanas/{campana}', [CampanaController::class, 'update']);

    Route::get('productos', [ProductoController::class, 'index']);
    Route::post('productos', [ProductoController::class, 'store']);
    Route::get('productos/{producto}', [ProductoController::class, 'show']);
    Route::patch('productos/{producto}', [ProductoController::class, 'update']);
});
