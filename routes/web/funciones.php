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
    // El historial vive dentro de Venta local. La dirección vieja abre esa pestaña.
    Route::get('/ventas', function () {
        $query = request()->query();
        $query['pestana'] = 'historial';

        return redirect()->route('punto-venta.index', $query);
    })->name('ventas.index');

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

    // El conteo vive dentro de Stock local. La dirección vieja abre esa pestaña
    // (permiso fino inventario.conteo; aplicar pide además stock.ajustar).
    Route::get('/inventario/conteos', function () {
        $query = request()->query();
        $query['pestana'] = 'conteo';

        return redirect()->route('stock.index', $query);
    })->middleware(\App\Http\Middleware\ExigirPermisoModulo::class.':'.\App\Services\Auth\PermisoModuloService::INVENTARIO_CONTEO)
        ->name('conteos.index');

    // Comisiones (permiso fino comisiones.ver; editar es solo de la administración).
    Route::livewire('/comisiones', 'comisiones.index')
        ->middleware(\App\Http\Middleware\ExigirPermisoModulo::class.':'.\App\Services\Auth\PermisoModuloService::COMISIONES_VER)
        ->name('comisiones.index');

    Route::get('/caja/{id}/pdf', [\App\Http\Controllers\CorteCajaController::class, 'pdf'])->whereNumber('id')->name('caja.pdf');
});
