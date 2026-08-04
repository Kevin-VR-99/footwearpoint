<?php

use Illuminate\Support\Facades\Route;

// Cada paquete agrega aquí sus rutas (o, mejor, las divide en archivos por
// módulo dentro de routes/api/ e incluye cada uno con require, como ya
// quedó indicado en las convenciones de Git del documento de tareas).

Route::get('/ping', function () {
    return response()->json(['status' => 'ok']);
});

require __DIR__.'/api/distribuidora.php';