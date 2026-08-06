<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas públicas
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return redirect()->route('login');
});

Route::livewire('/marketplace', 'marketplace.index')
    ->name('marketplace');

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
| Autenticados — panel distribuidora
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

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

    /*
    |--------------------------------------------------------------------------
    | Alias del menú layouts.distribuidora (Kevin / B)
    | Usar route() para respetar /footwearpoint/public
    |--------------------------------------------------------------------------
    */
    Route::get('/distribuidora', function () {
        return redirect()->route('dashboard');
    })->name('distribuidora.inicio');

    Route::get('/distribuidora/catalogo', function () {
        return redirect()->route('dashboard');
    })->name('distribuidora.catalogo');

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

    Route::get('/distribuidora/configuracion', function () {
        return redirect()->route('dashboard');
    })->name('distribuidora.configuracion');
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
});
