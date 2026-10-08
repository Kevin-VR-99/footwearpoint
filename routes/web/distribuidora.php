<?php

use App\Http\Controllers\Distribuidora\MercadoPagoOAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete B — Rutas web de Configuración de la Distribuidora
|--------------------------------------------------------------------------
|
| Nombre de ruta 'distribuidora.configuracion' porque el layout compartido
| (resources/views/layouts/distribuidora.blade.php) ya lo referencia así
| en el sidebar — no se inventó el nombre, se leyó del layout existente.
|
| Middleware: 'tenant.team' — ya unificado (antes existían 'team' y
| 'tenant.team' por separado; el equipo los unió en uno solo).
|
| Recordatorio: en routes/web.php debe existir
|   require __DIR__.'/web/distribuidora.php';
*/

Route::middleware(['auth', 'tenant.team', 'role:admin_distribuidora'])->group(function () {
    Route::livewire('/distribuidora/configuracion', 'distribuidora.configuracion')
        ->name('distribuidora.configuracion');
});

/*
| TG-225 (G6) — Conectar la cuenta de Mercado Pago de la distribuidora (OAuth).
|
| 'conectar' manda a la pantalla oficial de Mercado Pago y Mercado Pago
| regresa a '/mercado-pago/callback' (debe ser idéntica a MP_REDIRECT_URI y a
| la URL registrada en la aplicación de Mercado Pago Developers).
|
| Llevan 'solo.personal' para que una distribuidora suspendida vea el aviso
| claro en vez de un 403 genérico (el resto de este grupo queda igual).
*/
Route::middleware(['auth', 'tenant.team', 'solo.personal', 'role:admin_distribuidora'])->group(function () {
    Route::get('/distribuidora/mercado-pago/conectar', [MercadoPagoOAuthController::class, 'conectar'])
        ->name('mercado-pago.conectar');
    Route::get('/mercado-pago/callback', [MercadoPagoOAuthController::class, 'callback'])
        ->name('mercado-pago.callback');
});
