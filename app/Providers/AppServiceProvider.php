<?php

namespace App\Providers;

use Anthropic\Client as Claude;
use App\Mail\RestablecerPasswordMail;
use App\Mail\VerificarCorreoMail;
use App\Models\Usuario;
use App\Services\Auth\ExpiracionDeSesion;
use App\Services\Ia\LectorDeCatalogos;
use App\Services\Ia\LectorDeCatalogosClaude;
use App\Services\Catalogo\PrecioEfectivo;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Http\Middleware\SoloPersonalPanel;
use App\Services\Notificacion\Push\EnviadorPushFirebase;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use App\Rules\PasswordSegura;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public const MENSAJE_DEMASIADOS_INTENTOS = 'Demasiados intentos. Espera un minuto y vuelve a intentarlo.';

    public function register(): void
    {
        // "php artisan serve" escribe cada petición en el log de Railway: usamos
        // la versión que oculta tokens de restablecimiento y firmas de enlaces.
        $this->app->extend(\Illuminate\Foundation\Console\ServeCommand::class, fn () => new \App\Console\Commands\ServeCommand);

        // Push real solo si hay credenciales de Firebase; si no, uno que no
        // manda nada y lo deja claro en el log (ver CredencialesFirebase).
        $this->app->bind(EnviadorPush::class, fn ($app) => \App\Support\CredencialesFirebase::configuradas()
            ? $app->make(EnviadorPushFirebase::class)
            : $app->make(\App\Services\Notificacion\Push\EnviadorPushNulo::class));

        // Uno solo por petición: guarda el descuento y los precios propios que
        // ya consultó, para no repetir consultas al armar el catálogo (TG-213).
        $this->app->scoped(PrecioEfectivo::class);

        // Mismo motivo: staff y sucursal principal se resolvían 3-4 veces por
        // pantalla (/stock, /punto-venta) porque cada app() daba una instancia nueva.
        $this->app->scoped(\App\Support\ContextoOperativo::class);

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

            // Sin esto la conexión no tiene límite de tiempo (Guzzle espera
            // para siempre) y un 429/529 solo se reintenta 2 veces con
            // esperas de segundos.
            return new Claude(apiKey: $llave, requestOptions: [
                'maxRetries'    => (int) config('ia.reintentos', 4),
                'maxRetryDelay' => 30.0,
                'transporter'   => new \GuzzleHttp\Client([
                    'timeout'         => (int) config('ia.segundos_por_peticion', 600),
                    'connect_timeout' => 30,
                ]),
            ]);
        });
    }

    public function boot(): void
    {
        // INC-002: política única de contraseñas para todo alta o cambio.
        // uncompromised() consulta HaveIBeenPwned; solo en producción para
        // que pruebas y desarrollo no dependan de la red (la lista offline
        // de PasswordSegura cubre las más comunes en todos los entornos).
        Password::defaults(function () {
            $regla = Password::min(8)->letters()->numbers()->rules([new PasswordSegura]);

            return $this->app->environment('production') ? $regla->uncompromised() : $regla;
        });

        // Caché de catálogos por distribuidora: se limpia al guardar o borrar.
        foreach ([\App\Models\Talla::class, \App\Models\Color::class, \App\Models\Marca::class, \App\Models\Linea::class,
            \App\Models\Campana::class, \App\Models\Temporada::class, \App\Models\DistribuidoraLinea::class, \App\Models\CicloCompra::class] as $modelo) {
            $modelo::observe(\App\Observers\InvalidarCatalogoCache::class);
        }

        // TG-220: las acciones de Livewire también respetan el cobro inicial.
        \Livewire\Livewire::addPersistentMiddleware([\App\Http\Middleware\CobroInicialPendiente::class]);

        $appUrl = rtrim((string) config('app.url'), '/');

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceRootUrl($appUrl);
            URL::forceScheme('https');
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
        // Tope por IP para pedir el enlace y para restablecer: 5 por minuto.
        // Es por IP y no por correo, así la respuesta no dice nada de quién
        // tiene cuenta (TG-141); solo frena a quien manda muchas seguidas.
        RateLimiter::for('recuperar-password', fn (Request $request) => Limit::perMinute(5)
            ->by('recuperar-password|'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->json([
                'message' => self::MENSAJE_DEMASIADOS_INTENTOS,
            ], 429, $headers)));

        ResetPassword::toMailUsing(
            fn (Usuario $usuario, string $token) => new RestablecerPasswordMail($usuario, $token)
        );

        // TG-189 (A11): igual que el anterior, el correo de verificación en
        // español y con el nombre de FootwearPoint. La dirección la arma
        // Laravel: viene firmada y con caducidad.
        VerifyEmail::toMailUsing(
            fn (Usuario $usuario, string $url) => new VerificarCorreoMail($usuario, $url)
        );
    }
}