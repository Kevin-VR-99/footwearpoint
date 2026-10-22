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

    // Corte de caja (permiso fino caja.operar, se revisa en la pantalla y en el PDF).
    Route::livewire('/caja', 'caja.index')->name('caja.index');
    // Historial 360 de clientes e importación (permisos finos clientes.ver / clientes.importar).
    Route::middleware(\App\Http\Middleware\ExigirPermisoModulo::class.':'.\App\Services\Auth\PermisoModuloService::CLIENTES_VER)->group(function () {
        Route::livewire('/clientes/historial', 'clientes.historial')->name('clientes.historial');
        Route::livewire('/clientes/historial/{tipo}/{id}', 'clientes.historial')->whereIn('tipo', ['cliente_directo', 'revendedor'])->whereNumber('id')->name('clientes.historial.show');
    });
    Route::livewire('/clientes/importar', 'clientes.importar')
        ->middleware(\App\Http\Middleware\ExigirPermisoModulo::class.':'.\App\Services\Auth\PermisoModuloService::CLIENTES_IMPORTAR)
        ->name('clientes.importar');

    Route::get('/caja/{id}/pdf', [\App\Http\Controllers\CorteCajaController::class, 'pdf'])->whereNumber('id')->name('caja.pdf');
});
