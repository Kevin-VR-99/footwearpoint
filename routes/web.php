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

Route::livewire('/forgot-password', 'auth.forgot-password')
    ->name('password.request')
    ->middleware('guest');

Route::livewire('/reset-password/{token}', 'auth.reset-password')
    ->name('password.reset')
    ->middleware('guest');
