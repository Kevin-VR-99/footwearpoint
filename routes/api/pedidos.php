<?php

use App\Http\Controllers\Api\PagoMercadoPagoController;
use App\Http\Controllers\Api\PedidoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete D — Pedidos
|--------------------------------------------------------------------------
|
| Desde Sprint 3 (TG-134) también entran revendedor y cliente directo, que
| arman y envían su propio pedido desde la app móvil.
|
| Cada quien ve y toca SOLO sus pedidos — de eso se encarga
| App\Support\PropietarioActual dentro del controlador, no el middleware.
| El empleado conserva el acceso a todos los de su distribuidora, porque
| sigue capturando pedidos en el mostrador.
|
| Al crear un pedido, el dueño se toma del usuario autenticado y se ignora
| lo que venga en la petición (ver CrearPedidoBorradorAction).
|
| E8-02 / TG-31: registrar anticipo o saldo es solo personal de mostrador
| (admin_distribuidora | empleado). No va en el grupo móvil.
*/

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado|revendedor|cliente_directo'])
    ->group(function () {
        Route::get('/pedidos', [PedidoController::class, 'index']);
        Route::get('/pedidos/{id}', [PedidoController::class, 'show']);
        Route::post('/pedidos', [PedidoController::class, 'store']);
        Route::post('/pedidos/{id}/lineas', [PedidoController::class, 'agregarLinea']);
        // TG-189 (A11): enviar el pedido es el momento en que la persona se
        // compromete, asi que ahi se pide el correo confirmado. Armar el
        // borrador no, para no toparla con un muro antes de tiempo.
        Route::post('/pedidos/{id}/enviar', [PedidoController::class, 'enviar'])
            ->middleware('correo.verificado');

        // TG-241 (A12): la persona dice que prefiere cuando la fabrica no
        // surtio algo de su pedido. No mueve dinero: solo deja anotada su
        // decision. El efectivo lo entrega el personal en el mostrador.
        Route::post('/pedidos/{id}/detalle/{detalleId}/resolucion', [PedidoController::class, 'resolverNoSurtido'])
            ->whereNumber(['id', 'detalleId']);
        Route::delete('/pedidos/{pedidoId}/lineas/{lineaId}', [PedidoController::class, 'quitarLinea']);
    });

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::post('/pedidos/{id}/pagos', [PedidoController::class, 'registrarPago']);
    });


// TG-189 (A11): los pagos tambien piden el correo confirmado, pero SOLO donde
// se inicia uno. Los 'verificar' se quedan abiertos a proposito: le preguntan
// a Mercado Pago si un pago YA se hizo, y bloquearlos podria perder la
// confirmacion de dinero que la persona ya pago.
/*
| TG-226 (G7) / TG-227 (G8) — El cliente directo paga su anticipo o su saldo
| con Mercado Pago (Checkout Pro) desde la app, sobre SUS pedidos.
| 'verificar' le pregunta a Mercado Pago si ya se pagó cuando el cliente
| regresa a la app.
| Con límite de 10 por minuto: cada llamada sale a Mercado Pago.
*/
Route::middleware(['auth:sanctum', 'tenant.team', 'role:cliente_directo', 'throttle:10,1'])
    ->group(function () {
        Route::post('/pedidos/{id}/anticipo/mercado-pago', [PagoMercadoPagoController::class, 'crearAnticipo'])
            ->middleware('correo.verificado')
            ->whereNumber('id');
        Route::post('/pedidos/{id}/anticipo/mercado-pago/verificar', [PagoMercadoPagoController::class, 'verificar'])
            ->whereNumber('id');

        // TG-227 (G8): el saldo completo, cuando el pedido ya llegó a la distribuidora.
        Route::post('/pedidos/{id}/saldo/mercado-pago', [PagoMercadoPagoController::class, 'crearSaldo'])
            ->middleware('correo.verificado')
            ->whereNumber('id');
        Route::post('/pedidos/{id}/saldo/mercado-pago/verificar', [PagoMercadoPagoController::class, 'verificarSaldo'])
            ->whereNumber('id');
    });

/*
| TG-229 (G10) — El cliente mayorista (revendedor en el código) paga con
| Mercado Pago todo lo que falta de SU pedido, desde que lo envía hasta que
| está listo para entrega. Mismo límite que el cliente directo.
*/
Route::middleware(['auth:sanctum', 'tenant.team', 'role:revendedor', 'throttle:10,1'])
    ->group(function () {
        Route::post('/pedidos/{id}/total/mercado-pago', [PagoMercadoPagoController::class, 'crearMayorista'])
            ->middleware('correo.verificado')
            ->whereNumber('id');
        Route::post('/pedidos/{id}/total/mercado-pago/verificar', [PagoMercadoPagoController::class, 'verificarMayorista'])
            ->whereNumber('id');
    });
