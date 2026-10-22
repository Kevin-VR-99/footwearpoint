<?php

use App\Http\Controllers\Api\ReembolsoMercadoPagoController;
use Illuminate\Support\Facades\Route;

/*
| Reembolsos automáticos en Mercado Pago: solo el personal de la distribuidora.
| El permiso fino (ventas.cancelar o pagos.registrar) se revisa en el servicio.
*/
Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::get('reembolsos-mercado-pago/{id}', [ReembolsoMercadoPagoController::class, 'show'])->whereNumber('id');
        Route::post('reembolsos-mercado-pago/{id}/reintentar', [ReembolsoMercadoPagoController::class, 'reintentar'])
            ->whereNumber('id')->middleware('throttle:20,1');
    });
