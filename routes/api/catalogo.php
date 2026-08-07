<?php

use App\Http\Controllers\Api\Catalogo\CampanaController;
use App\Http\Controllers\Api\Catalogo\CatalogoController;
use App\Http\Controllers\Api\Catalogo\CategoriaProductoController;
use App\Http\Controllers\Api\Catalogo\DisponibilidadVarianteCampanaController;
use App\Http\Controllers\Api\Catalogo\ImagenProductoCampanaController;
use App\Http\Controllers\Api\Catalogo\MarcaController;
use App\Http\Controllers\Api\Catalogo\ProductoCampanaController;
use App\Http\Controllers\Api\Catalogo\ProductoController;
use App\Http\Controllers\Api\Catalogo\VarianteController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Catalogo\LineaController;
/*
|--------------------------------------------------------------------------
| Paquete B — Catálogo (E4) — COMPLETO
|--------------------------------------------------------------------------
|
| Bloque 3a: marcas y categorías.
| Bloque 3b: campañas y productos.
| Bloque 3c: producto-campana (precios/publicación) e imágenes.
| Bloque 3d: variantes y disponibilidad-variante-campana.
| Bloque 3e: GET /api/catalogo consultable — último bloque del catálogo.
|
| Recordatorio: en routes/api.php debe existir
|   require __DIR__.'/api/catalogo.php';
*/

// --- Bloques 3a-3d: pantallas de administración del catálogo ---
// Solo admin_distribuidora: son pantallas de configuración, no de uso diario.
Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora'])->group(function () {
    Route::get('marcas', [MarcaController::class, 'index']);
    Route::post('marcas', [MarcaController::class, 'store']);
    Route::get('marcas/{marca}', [MarcaController::class, 'show']);
    Route::patch('marcas/{marca}', [MarcaController::class, 'update']);

    Route::get('categorias-producto', [CategoriaProductoController::class, 'index']);
    Route::post('categorias-producto', [CategoriaProductoController::class, 'store']);
    Route::get('categorias-producto/{categoria}', [CategoriaProductoController::class, 'show']);
    Route::patch('categorias-producto/{categoria}', [CategoriaProductoController::class, 'update']);

    Route::get('campanas', [CampanaController::class, 'index']);
    Route::post('campanas', [CampanaController::class, 'store']);
    Route::get('campanas/{campana}', [CampanaController::class, 'show']);
    Route::patch('campanas/{campana}', [CampanaController::class, 'update']);

    Route::get('productos', [ProductoController::class, 'index']);
    Route::post('productos', [ProductoController::class, 'store']);
    Route::get('productos/{producto}', [ProductoController::class, 'show']);
    Route::patch('productos/{producto}', [ProductoController::class, 'update']);

    Route::get('producto-campana', [ProductoCampanaController::class, 'index']);
    Route::post('producto-campana', [ProductoCampanaController::class, 'store']);
    Route::get('producto-campana/{productoCampana}', [ProductoCampanaController::class, 'show']);
    Route::patch('producto-campana/{productoCampana}', [ProductoCampanaController::class, 'update']);

    Route::get('producto-campana/{productoCampana}/imagenes', [ImagenProductoCampanaController::class, 'index']);
    Route::post('producto-campana/{productoCampana}/imagenes', [ImagenProductoCampanaController::class, 'store']);
    Route::patch('producto-campana/imagenes/{imagen}/principal', [ImagenProductoCampanaController::class, 'marcarPrincipal']);
    Route::delete('producto-campana/imagenes/{imagen}', [ImagenProductoCampanaController::class, 'destroy']);

    Route::get('variantes', [VarianteController::class, 'index']);
    Route::post('variantes', [VarianteController::class, 'store']);
    Route::get('variantes/{variante}', [VarianteController::class, 'show']);
    Route::patch('variantes/{variante}', [VarianteController::class, 'update']);

    Route::get('disponibilidad-variante-campana', [DisponibilidadVarianteCampanaController::class, 'index']);
    Route::post('disponibilidad-variante-campana', [DisponibilidadVarianteCampanaController::class, 'store']);
    Route::get('disponibilidad-variante-campana/{disponibilidadVarianteCampana}', [DisponibilidadVarianteCampanaController::class, 'show']);
    Route::patch('disponibilidad-variante-campana/{disponibilidadVarianteCampana}', [DisponibilidadVarianteCampanaController::class, 'update']);

    Route::get('lineas', [LineaController::class, 'index']);
    Route::post('lineas', [LineaController::class, 'store']);
    Route::get('lineas/{linea}', [LineaController::class, 'show']);
    Route::patch('lineas/{linea}', [LineaController::class, 'update']);
});

// --- Bloque 3e: catálogo consultable — uso diario, admin_distribuidora Y empleado ---
Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])->group(function () {
    Route::get('catalogo', [CatalogoController::class, 'index']);
});
