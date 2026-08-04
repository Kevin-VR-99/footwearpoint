<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/reset-password/{token}', function (string $token) {
    return response()->json([
        'message' => 'Ruta de reset (solo para generar el enlace).',
        'token'   => $token,
    ]);
})->name('password.reset');