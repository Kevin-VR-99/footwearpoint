<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | TG-187 (A5) — Tope absoluto de la sesion de la app: minutos de vida de
    | un token desde que se creo, aunque se use todos los dias. Es lo que
    | evita que un token robado sirva para siempre. Por omision 90 dias.
    | Con 0 no caduca nunca (como estaba antes de TG-187).
    |
    | Lo revisa Sanctum por su cuenta y tambien ExpiracionDeSesion, que
    | ademas borra el token vencido.
    |
    */

    'expiration' => (int) env('SANCTUM_EXPIRACION_MINUTOS', 60 * 24 * 90),

    /*
    |--------------------------------------------------------------------------
    | Inactividad (TG-187)
    |--------------------------------------------------------------------------
    |
    | Minutos que puede pasar la app SIN hablar con el servidor antes de que
    | la sesion se venza. Cada peticion reinicia el reloj (Sanctum guarda
    | personal_access_tokens.last_used_at). Por omision 14 dias.
    | Con 0 no se revisa la inactividad.
    |
    | Para la demostracion se puede bajar a 1 o 2 minutos desde el .env.
    |
    */

    'inactividad_minutos' => (int) env('SANCTUM_INACTIVIDAD_MINUTOS', 60 * 24 * 14),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
