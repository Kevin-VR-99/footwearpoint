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
