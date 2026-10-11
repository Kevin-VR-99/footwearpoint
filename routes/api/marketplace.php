<?php

use App\Http\Controllers\Api\MarketplaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete E — Marketplace público (E15-01)
|--------------------------------------------------------------------------
|
| Endpoint público, SIN autenticación.
| Solo distribuidoras con estado='activa' y marketplace_visible=true.
*/

Route::get('/marketplace', [MarketplaceController::class, 'index'])
    ->name('marketplace.index');

// TG-197 (G16): categorías del directorio para filtrar (solo activas y con
// al menos una distribuidora visible).
Route::get('/marketplace/categorias', [MarketplaceController::class, 'categorias'])
    ->name('marketplace.categorias');