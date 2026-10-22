<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| feature/funciones-completas — pantallas nuevas del panel
|--------------------------------------------------------------------------
| En su propio archivo para no tocar más de lo necesario routes/web.php.
| Mismas capas que el resto del panel: sesión, distribuidora y solo personal.
| Cada pantalla revisa además su permiso fino (PermisoModuloService).
*/

Route::middleware(['auth', 'tenant.team', 'solo.personal', 'role:admin_distribuidora|empleado'])->group(function () {
    Route::livewire('/ventas', 'ventas.index')->name('ventas.index');
});
