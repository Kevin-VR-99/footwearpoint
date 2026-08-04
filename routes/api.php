<?php

use Illuminate\Support\Facades\Route;

// Cada paquete agrega aquí sus rutas (o, mejor, las divide en archivos por
// módulo dentro de routes/api/ e incluye cada uno con require, como ya
// quedó indicado en las convenciones de Git del documento de tareas).

Route::get('/ping', function () {
    return response()->json(['status' => 'ok']);
});

require __DIR__.'/api/distribuidora.php';

// Paquete C - stock local, venta directa y ciclos de compra
require __DIR__.'/api/stock.php';
require __DIR__.'/api/ventas-directas.php';
