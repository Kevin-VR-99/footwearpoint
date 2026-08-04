<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::livewire('/login', 'auth.login')
    ->name('login')
    ->middleware('guest');

Route::get('/dashboard', function () {
    return 'Dashboard distribuidora (pendiente)';
})->name('dashboard')->middleware('auth');

Route::get('/admin', function () {
    return 'Dashboard admin general (pendiente)';
})->name('admin.dashboard')->middleware('auth');

Route::get('/forgot-password', function () {
    return 'Recuperar contraseña (pendiente)';
})->name('password.request');

// Necesaria para que Laravel genere el enlace del correo de reset
Route::get('/reset-password/{token}', function (string $token) {
    return response()->json([
        'message' => 'Ruta de reset (solo para generar el enlace).',
        'token'   => $token,
    ]);
})->name('password.reset');