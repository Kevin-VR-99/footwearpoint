<?php

use App\Http\Controllers\ArchivoCatalogoImportacionController;
use App\Http\Controllers\ComprobanteVentaController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas públicas
|--------------------------------------------------------------------------
*/

// TG-232 (G12/G13): con FOOTWEARPOINT_DOMINIO, la tienda de cada
// distribuidora vive en {subdominio}.{dominio}. Van ANTES que todo: '/' sin
// dominio también coincidiría con el subdominio. ResolverTiendaPorSubdominio
// encuentra la distribuidora (o responde el 404 de tienda) y pasa su slug a
// los mismos componentes de /tienda/{slug}.
if ($dominioTiendas = config('app.dominio_base')) {
    Route::domain('{subdominio}.'.$dominioTiendas)
        ->where(['subdominio' => '[^.]+'])
        ->middleware('tienda.subdominio')
        ->group(function () {
            Route::livewire('/', 'tienda.index')
                ->name('tienda.subdominio');

            Route::livewire('/productos/{productoCampana}', 'tienda.producto')
                ->whereNumber('productoCampana')
                ->name('tienda.subdominio.producto');
        });
}

Route::get('/', function () {
    return redirect()->route('login');
});

Route::livewire('/marketplace', 'marketplace.index')
    ->name('marketplace');

// TG-233 (G14): tienda pública de cada distribuidora (solo las activas). Con
// sesión o sin ella se ve igual: el catálogo y el precio de menudeo de ESA
// distribuidora (ver App\Services\Tienda\TiendaPublica).
// TG-232: con FOOTWEARPOINT_DOMINIO redirigen (301) al subdominio de la tienda.
Route::livewire('/tienda/{slug}', 'tienda.index')
    ->where('slug', '[a-z0-9-]+')
    ->middleware('tienda.redirigir')
    ->name('tienda');

Route::livewire('/tienda/{slug}/productos/{productoCampana}', 'tienda.producto')
    ->where('slug', '[a-z0-9-]+')
    ->whereNumber('productoCampana')
    ->middleware('tienda.redirigir')
    ->name('tienda.producto');

// TG-226 (G7): a donde regresa Mercado Pago después de pagar (back_urls).
// Pública. Si trae payment_id y external_reference confirma el pago con la
// API de Mercado Pago (nunca le cree a la URL); la app también puede
// confirmar con POST /api/pedidos/{id}/anticipo/mercado-pago/verificar.
Route::livewire('/mercado-pago/retorno', 'mercado-pago.retorno')
    ->name('mercado-pago.retorno');

/*
|--------------------------------------------------------------------------
| Invitados (guest)
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'auth.login')
        ->name('login');

    Route::livewire('/forgot-password', 'auth.forgot-password')
        ->name('password.request');

    Route::livewire('/reset-password/{token}', 'auth.reset-password')
        ->name('password.reset');
});

/*
|--------------------------------------------------------------------------
| Paquete B — config y catálogo (registran distribuidora.configuracion
| y distribuidora.catalogo). Deben ir ANTES de cualquier alias con el
| mismo nombre de ruta.
|--------------------------------------------------------------------------
*/

require __DIR__.'/web/distribuidora.php';
require __DIR__.'/web/catalogo.php';

/*
|--------------------------------------------------------------------------
| Autenticados — panel distribuidora
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant.team'])->group(function () {
    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});

/*
|--------------------------------------------------------------------------
| Panel de la distribuidora — solo personal (TG-184)
|--------------------------------------------------------------------------
| Antes estas rutas solo pedían sesión, así que un revendedor o un cliente
| con cuenta entraba al panel. Ahora pasan por 'solo.personal', que cierra la
| sesión y regresa al login con un aviso en español.
|
| /logout queda FUERA de este grupo a propósito: si no, quien pierde el
| acceso quedaría atrapado sin poder cerrar sesión.
*/
Route::middleware(['auth', 'tenant.team', 'solo.personal'])->group(function () {
    Route::livewire('/dashboard', 'dashboard.index')
        ->name('dashboard');

    Route::livewire('/legales', 'auth.aceptar-legales')
        ->name('legales.aceptar');

    // Solo admin_distribuidora
    Route::livewire('/empleados/registrar', 'auth.register-empleado')
        ->name('empleados.registrar')
        ->middleware(['tenant.team', 'role:admin_distribuidora']);

    // Paquete C
    Route::livewire('/stock', 'stock.index')
        ->name('stock.index');

    Route::livewire('/punto-venta', 'punto-venta.index')
        ->name('punto-venta.index');

    // E7-02 — comprobante de venta directa: ver/imprimir, PDF y correo.
    // Solo personal de la distribuidora; la venta de otra distribuidora da 404.
    Route::middleware(['tenant.team', 'role:admin_distribuidora|empleado'])
        ->prefix('ventas-directas/{id}/comprobante')
        ->whereNumber('id')
        ->group(function () {
            Route::get('/', [ComprobanteVentaController::class, 'show'])
                ->name('ventas-directas.comprobante');
            Route::get('/pdf', [ComprobanteVentaController::class, 'pdf'])
                ->name('ventas-directas.comprobante.pdf');
            Route::post('/enviar', [ComprobanteVentaController::class, 'enviar'])
                ->name('ventas-directas.comprobante.enviar');
        });

    Route::livewire('/ciclo', 'ciclo.index')
        ->name('ciclo.index');

    // Paquete D — orden: index → crear → {id}
    Route::livewire('/pedidos', 'pedidos.index')
        ->name('pedidos.index');

    Route::livewire('/pedidos/crear', 'pedidos.create')
        ->name('pedidos.create');

    Route::livewire('/pedidos/{id}', 'pedidos.show')
        ->name('pedidos.show');

    // Paquete E
    Route::livewire('/vales', 'vales.index')
        ->name('vales.index');

    Route::livewire('/notificaciones', 'notificaciones.index')
        ->name('notificaciones.index');

    Route::livewire('/reportes', 'reportes.index')
        ->name('reportes.index');

    // TG-160 — bitácora de auditoría (solo admin_distribuidora)
    Route::livewire('/auditoria', 'auditoria.index')
        ->name('auditoria.index')
        ->middleware('role:admin_distribuidora');

    /*
    |--------------------------------------------------------------------------
    | Alias del menú layouts.distribuidora
    | Usar route() para respetar /footwearpoint/public
    |
    | NO volver a registrar el nombre distribuidora.configuracion ni
    | distribuidora.catalogo: ya los define Paquete B arriba.
    |--------------------------------------------------------------------------
    */

    Route::get('/distribuidora', function () {
        return redirect()->route('dashboard');
    })->name('distribuidora.inicio');

    Route::get('/distribuidora/pedidos', function () {
        return redirect()->route('pedidos.index');
    })->name('distribuidora.pedidos');

    Route::get('/distribuidora/ciclos', function () {
        return redirect()->route('ciclo.index');
    })->name('distribuidora.ciclos');

    Route::get('/distribuidora/stock', function () {
        return redirect()->route('stock.index');
    })->name('distribuidora.stock');

    Route::get('/distribuidora/vales', function () {
        return redirect()->route('vales.index');
    })->name('distribuidora.vales');

    Route::get('/distribuidora/reportes', function () {
        return redirect()->route('reportes.index');
    })->name('distribuidora.reportes');

    // URL amigable de catálogo (sin name) → pantalla real de B
    Route::get('/distribuidora/catalogo', function () {
        return redirect()->route('distribuidora.catalogo');
    });
});

/*
|--------------------------------------------------------------------------
| Admin general (Paquete A)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant.team', 'role:admin_general'])->group(function () {
    Route::livewire('/admin', 'admin.distribuidoras-index')
        ->name('admin.dashboard');

    Route::livewire('/admin/planes', 'admin.planes-index')
        ->name('admin.planes');

    // TG-197 (G16): categorías generales del directorio público.
    Route::livewire('/admin/categorias-directorio', 'admin.categorias-directorio-index')
        ->name('admin.categorias-directorio');

    // TG-237 (A7): subir el catálogo de la fábrica para que lo lea la IA.
    Route::livewire('/admin/catalogos', 'admin.catalogos-importaciones')
        ->name('admin.catalogos');

    // El archivo vive en un disco privado: se sirve desde aquí para que pase
    // por la sesión y por el rol, no con una dirección pública.
    Route::get('/admin/catalogos/{importacion}/archivo', [ArchivoCatalogoImportacionController::class, 'show'])
        ->whereNumber('importacion')
        ->name('admin.catalogos.archivo');
});