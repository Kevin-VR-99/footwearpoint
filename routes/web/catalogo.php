<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paquete B — Rutas web del Catálogo
|--------------------------------------------------------------------------
|
| IMPORTANTE: routes/web.php ya tiene un placeholder con este mismo nombre
| de ruta (distribuidora.catalogo), puesto por Fase 0 como redirección
| temporal al dashboard. Hay que QUITAR esa línea de ahí antes de agregar
| este archivo — si se dejan las dos, va a pasar lo mismo que con
| distribuidora.configuracion (2 rutas con el mismo nombre, gana la que
| se cargue al final, de forma impredecible).
|
| Recordatorio: en routes/web.php debe existir
|   require __DIR__.'/web/catalogo.php';
*/

Route::middleware(['auth', 'tenant.team', 'role:admin_distribuidora'])->group(function () {
    Route::livewire('/catalogo', 'catalogo.index')
        ->name('distribuidora.catalogo');

    Route::livewire('/catalogo/productos/{producto}', 'catalogo.producto-detalle')
        ->name('catalogo.producto.detalle');
});
