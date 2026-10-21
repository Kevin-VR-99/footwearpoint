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
|
| El límite es amplio (600/min por IP) porque Mercado Pago avisa desde pocas
| IPs; el abuso lo frenan la firma obligatoria en producción y la
| deduplicación por data.id antes de consultar la API.
*/

Route::post('/webhooks/mercado-pago', WebhookMercadoPagoController::class)
    ->middleware('throttle:600,1')
    ->name('mercado-pago.webhook');

// Auditoría 21-oct-2026: reportes de la CSP (Report-Only) que manda el navegador.
Route::post('/csp-report', \App\Http\Controllers\Api\ReporteCspController::class)
    ->middleware('throttle:30,1')
    ->name('csp.reporte');
