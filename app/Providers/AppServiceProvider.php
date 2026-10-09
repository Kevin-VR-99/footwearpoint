<?php

namespace App\Providers;

use App\Services\Auth\ExpiracionDeSesion;
use App\Services\Catalogo\PrecioEfectivo;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Http\Middleware\SoloPersonalPanel;
use App\Services\Notificacion\Push\EnviadorPushFirebase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EnviadorPush::class, EnviadorPushFirebase::class);

        // Uno solo por petición: guarda el descuento y los precios propios que
        // ya consultó, para no repetir consultas al armar el catálogo (TG-213).
        $this->app->scoped(PrecioEfectivo::class);
    }

    public function boot(): void
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceRootUrl($appUrl);
            URL::forceScheme('https');
        }

        if (! $this->app->runningInConsole()) {
            header('Content-Security-Policy: upgrade-insecure-requests');
        }

        // TG-196 (G5): las acciones de Livewire (botones, formularios) no pasan
        // por las rutas del panel. Así 'solo.personal' se vuelve a revisar en
        // cada acción y una sesión abierta se cierra en cuanto la distribuidora
        // se suspende o rechaza. Livewire solo lo aplica si la página donde
        // está el componente tenía ese middleware: el login y el panel del
        // admin general no lo tienen, así que no les cambia nada.
        Livewire::addPersistentMiddleware([SoloPersonalPanel::class]);

        // TG-187 (A5): la sesión de la app caduca por inactividad y tiene un
        // tope de vida. Se engancha aquí, en el guard de Sanctum, para que
        // valga en TODAS las rutas de la API de una sola vez y sin depender
        // del orden de los middlewares (ver ExpiracionDeSesion).
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $valido) => $this->app
                ->make(ExpiracionDeSesion::class)
                ->sigueValido($token, $valido)
        );
    }
}