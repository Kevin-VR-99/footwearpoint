<?php

namespace App\Providers;

use Anthropic\Client as Claude;
use App\Mail\RestablecerPasswordMail;
use App\Models\Usuario;
use App\Services\Auth\ExpiracionDeSesion;
use App\Services\Ia\LectorDeCatalogos;
use App\Services\Ia\LectorDeCatalogosClaude;
use App\Services\Catalogo\PrecioEfectivo;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Http\Middleware\SoloPersonalPanel;
use App\Services\Notificacion\Push\EnviadorPushFirebase;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EnviadorPush::class, EnviadorPushFirebase::class);

        // Uno solo por petición: guarda el descuento y los precios propios que
        // ya consultó, para no repetir consultas al armar el catálogo (TG-213).
        $this->app->scoped(PrecioEfectivo::class);

        // TG-238 (A8): quien lee los catálogos. En las pruebas se cambia por
        // un lector falso, así que nunca gastan dinero ni piden la llave.
        $this->app->bind(LectorDeCatalogos::class, LectorDeCatalogosClaude::class);

        $this->app->bind(Claude::class, function () {
            $llave = (string) config('ia.llave');

            if ($llave === '') {
                throw new RuntimeException(
                    'Falta ANTHROPIC_API_KEY. En local va en tu .env; en Railway, en las variables del servicio.'
                );
            }

            return new Claude(apiKey: $llave);
        });
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

        // TG-188 (A6): el correo del enlace llegaba en inglés y firmado
        // "Laravel", porque era la plantilla de fábrica. Se le cambia solo el
        // contenido y la notificación sigue siendo la de Laravel, así valen
        // igual las tres puertas que lo mandan: la web, la app (TG-141) y el
        // botón del panel (TG-192).
        ResetPassword::toMailUsing(
            fn (Usuario $usuario, string $token) => new RestablecerPasswordMail($usuario, $token)
        );
    }
}