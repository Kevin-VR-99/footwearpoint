<?php

use App\Http\Controllers\Api\WebhookMercadoPagoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Avisos (webhooks) de servicios externos — TG-228 (G9)
|--------------------------------------------------------------------------
|
| Mercado Pago avisa aquí cuando se crea o cambia un pago. Es pública (no
| hay sesión), sin CSRF (las rutas /api no lo llevan) y con límite por IP.
| El nombre "mercado-pago.webhook" es el que busca
| CrearPagoPedidoMercadoPagoAction para mandar notification_url en cada
| preferencia.
*/

Route::post('/webhooks/mercado-pago', WebhookMercadoPagoController::class)
    ->middleware('throttle:120,1')
    ->name('mercado-pago.webhook');
