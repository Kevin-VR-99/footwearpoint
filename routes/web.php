<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout')->middleware('auth');

Route::livewire('/login', 'auth.login')
    ->name('login')
    ->middleware('guest');

Route::get('/dashboard', function () {
    return view('dashboard-temp');
})->name('dashboard')->middleware('auth');

Route::livewire('/admin', 'admin.distribuidoras-index')
    ->name('admin.dashboard')
    ->middleware('auth');

Route::livewire('/admin/planes', 'admin.planes-index')
    ->name('admin.planes')
    ->middleware('auth');

Route::livewire('/forgot-password', 'auth.forgot-password')
    ->name('password.request')
    ->middleware('guest');

Route::livewire('/reset-password/{token}', 'auth.reset-password')
    ->name('password.reset')
    ->middleware('guest');

Route::livewire('/empleados/registrar', 'auth.register-empleado')
    ->name('empleados.registrar')
    ->middleware('auth');

Route::livewire('/legales', 'auth.aceptar-legales')
    ->name('legales.aceptar')
    ->middleware('auth');

Route::livewire('/vales', 'vales.index')
    ->name('vales.index')
    ->middleware('auth');

Route::livewire('/marketplace', 'marketplace.index')
    ->name('marketplace');

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
