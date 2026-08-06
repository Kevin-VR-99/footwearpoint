<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete B — Rutas web de Configuración de la Distribuidora
|--------------------------------------------------------------------------
|
| Nombre de ruta 'distribuidora.configuracion' porque el layout compartido
| (resources/views/layouts/distribuidora.blade.php) ya lo referencia así
| en el sidebar — no se inventó el nombre, se leyó del layout existente.
|
| Middleware: 'tenant.team' — ya unificado (antes existían 'team' y
| 'tenant.team' por separado; el equipo los unió en uno solo).
|
| Recordatorio: en routes/web.php debe existir
|   require __DIR__.'/web/distribuidora.php';
*/

Route::middleware(['auth', 'tenant.team', 'role:admin_distribuidora'])->group(function () {
    Route::livewire('/configuracion', 'distribuidora.configuracion')
        ->name('distribuidora.configuracion');
});
