{{--
    Enlaces del menú de las pantallas de feature/funciones-completas. Cada uno
    sale solo si la ruta existe y la persona tiene el permiso fino
    (PermisoModuloService): el servidor lo vuelve a revisar en la pantalla.
--}}
@php
    $permisosMenu = app(\App\Services\Auth\PermisoModuloService::class);
    $usuarioMenu = auth()->user();
    $enlacesFunciones = [
        ['caja.index', 'Caja y cortes', \App\Services\Auth\PermisoModuloService::CAJA_OPERAR, 'caja.*'],
        ['clientes.historial', 'Historial de clientes', \App\Services\Auth\PermisoModuloService::CLIENTES_VER, 'clientes.historial*'],
        ['comisiones.index', 'Comisiones', \App\Services\Auth\PermisoModuloService::COMISIONES_VER, 'comisiones.*'],
    ];
@endphp
@foreach ($enlacesFunciones as [$ruta, $texto, $permiso, $patron])
    @if (\Illuminate\Support\Facades\Route::has($ruta) && ($permiso === null || $permisosMenu->puede($usuarioMenu, $permiso)))
        <a href="{{ route($ruta) }}" wire:navigate
            class="fp-nav-item block rounded-lg px-3 py-2 text-sm transition {{ request()->routeIs($patron) ? 'bg-white/15 font-medium text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">{{ $texto }}</a>
    @endif
@endforeach
