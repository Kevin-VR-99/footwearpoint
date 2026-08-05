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

<<<<<<< HEAD
Route::livewire('/notificaciones', 'notificaciones.index')
    ->name('notificaciones.index')
    ->middleware('auth');

// Paquete C - stock local, punto de venta y ciclos de compra
Route::livewire('/stock', 'stock.index')
    ->name('stock.index')
    ->middleware('auth');

Route::livewire('/punto-venta', 'punto-venta.index')
    ->name('punto-venta.index')
    ->middleware('auth');

Route::livewire('/ciclo', 'ciclo.index')
    ->name('ciclo.index')
    ->middleware('auth');
=======
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
| Autenticados
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    Route::get('/dashboard', function () {
        return view('dashboard-temp');
    })->name('dashboard');

    // Registro de empleado: solo admin_distribuidora (el componente también valida)
    Route::livewire('/empleados/registrar', 'auth.register-empleado')
    ->name('empleados.registrar')
    ->middleware(['team', 'role:admin_distribuidora']);

    Route::livewire('/legales', 'auth.aceptar-legales')
        ->name('legales.aceptar');

    // Panel distribuidora (Paquete E)
    Route::livewire('/vales', 'vales.index')
        ->name('vales.index');

    Route::livewire('/notificaciones', 'notificaciones.index')
        ->name('notificaciones.index');
});

/*
|--------------------------------------------------------------------------
| Admin general (Paquete A) — E17-01
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'team', 'role:admin_general'])->group(function () {
    Route::livewire('/admin', 'admin.distribuidoras-index')
        ->name('admin.dashboard');

    Route::livewire('/admin/planes', 'admin.planes-index')
        ->name('admin.planes');
});
>>>>>>> b8f09480e0a80b7bb2d6e7e2142b3a416760bde5
