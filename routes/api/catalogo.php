<?php

use App\Http\Controllers\Api\Catalogo\CampanaController;
use App\Http\Controllers\Api\Catalogo\CatalogoController;
use App\Http\Controllers\Api\Catalogo\CategoriaProductoController;
use App\Http\Controllers\Api\Catalogo\DisponibilidadVarianteCampanaController;
use App\Http\Controllers\Api\Catalogo\ImagenProductoCampanaController;
use App\Http\Controllers\Api\Catalogo\LineaController;
use App\Http\Controllers\Api\Catalogo\MarcaController;
use App\Http\Controllers\Api\Catalogo\ProductoCampanaController;
use App\Http\Controllers\Api\Catalogo\ProductoController;
use App\Http\Controllers\Api\Catalogo\VarianteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete B — Catálogo (E4)
|--------------------------------------------------------------------------
|
| Desde el Sprint 4 (TG-213) el catálogo es UNO SOLO para todo el sistema:
|
|   - lo ESCRIBE solo el admin general (líneas, marcas, categorías,
|     temporadas, productos, variantes, precios de catálogo, disponibilidad e
|     imágenes). No depende de ninguna distribuidora;
|   - lo LEEN el admin y el empleado de cada distribuidora, de solo lectura:
|     lo suyo (qué líneas venden, su precio de mayoreo y qué ocultan) vive en
|     sus propias pantallas;
|   - GET /api/catalogo es el de uso diario, el que consume la app, y ahí cada
|     distribuidora ve solo lo que vende, con sus precios.
*/

// --- Escritura: solo el admin general ---
Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_general'])->group(function () {
    Route::post('marcas', [MarcaController::class, 'store']);
    Route::patch('marcas/{marca}', [MarcaController::class, 'update']);

    Route::post('categorias-producto', [CategoriaProductoController::class, 'store']);
    Route::patch('categorias-producto/{categoria}', [CategoriaProductoController::class, 'update']);

    Route::post('lineas', [LineaController::class, 'store']);
    Route::patch('lineas/{linea}', [LineaController::class, 'update']);

    Route::post('campanas', [CampanaController::class, 'store']);
    Route::patch('campanas/{campana}', [CampanaController::class, 'update']);

    Route::post('productos', [ProductoController::class, 'store']);
    Route::patch('productos/{producto}', [ProductoController::class, 'update']);

    Route::post('producto-campana', [ProductoCampanaController::class, 'store']);
    Route::patch('producto-campana/{productoCampana}', [ProductoCampanaController::class, 'update']);

    Route::post('producto-campana/{productoCampana}/imagenes', [ImagenProductoCampanaController::class, 'store']);
    Route::patch('producto-campana/imagenes/{imagen}/principal', [ImagenProductoCampanaController::class, 'marcarPrincipal']);
    Route::delete('producto-campana/imagenes/{imagen}', [ImagenProductoCampanaController::class, 'destroy']);

    Route::post('variantes', [VarianteController::class, 'store']);
    Route::patch('variantes/{variante}', [VarianteController::class, 'update']);

    Route::post('disponibilidad-variante-campana', [DisponibilidadVarianteCampanaController::class, 'store']);
    Route::patch('disponibilidad-variante-campana/{disponibilidadVarianteCampana}', [DisponibilidadVarianteCampanaController::class, 'update']);
});

// --- Lectura: el personal de la distribuidora, y también el admin general ---
Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_general|admin_distribuidora|empleado'])->group(function () {
    Route::get('marcas', [MarcaController::class, 'index']);
    Route::get('marcas/{marca}', [MarcaController::class, 'show']);

    Route::get('categorias-producto', [CategoriaProductoController::class, 'index']);
    Route::get('categorias-producto/{categoria}', [CategoriaProductoController::class, 'show']);

    Route::get('lineas', [LineaController::class, 'index']);
    Route::get('lineas/{linea}', [LineaController::class, 'show']);

    Route::get('campanas', [CampanaController::class, 'index']);
    Route::get('campanas/{campana}', [CampanaController::class, 'show']);

    Route::get('productos', [ProductoController::class, 'index']);
    Route::get('productos/{producto}', [ProductoController::class, 'show']);

    Route::get('producto-campana', [ProductoCampanaController::class, 'index']);
    Route::get('producto-campana/{productoCampana}', [ProductoCampanaController::class, 'show']);
    Route::get('producto-campana/{productoCampana}/imagenes', [ImagenProductoCampanaController::class, 'index']);

    Route::get('variantes', [VarianteController::class, 'index']);
    Route::get('variantes/{variante}', [VarianteController::class, 'show']);

    Route::get('disponibilidad-variante-campana', [DisponibilidadVarianteCampanaController::class, 'index']);
    Route::get('disponibilidad-variante-campana/{disponibilidadVarianteCampana}', [DisponibilidadVarianteCampanaController::class, 'show']);
});

// --- Catálogo consultable: el uso diario, también desde la app ---
// Cada distribuidora ve solo los productos de las líneas que vende, sin los
// que ocultó, y con sus propios precios (CatalogoVisible y PrecioEfectivo).
// El precio mayorista NO se le manda al cliente directo — ver CatalogoResource.
Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado|revendedor|cliente_directo'])->group(function () {
    Route::get('catalogo', [CatalogoController::class, 'index']);
});
