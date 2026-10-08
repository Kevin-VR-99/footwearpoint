<?php

use App\Exceptions\RespuestaErrorApi;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'tenant.team' => \App\Http\Middleware\SetTenantTeam::class,
            // TG-184: el panel web es solo para el personal.
            'solo.personal' => \App\Http\Middleware\SoloPersonalPanel::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // TG-224 (G3): el usuario nunca ve el error técnico; el detalle solo
        // va al log (Laravel reporta antes de armar la respuesta).

        // Todo /api responde JSON, aunque el cliente no mande
        // "Accept: application/json".
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        // Laravel convierte estas excepciones en 403/404 con mensajes en
        // inglés que incluso nombran el modelo ("No query results for model
        // [App\Models\Pedido]"). Se cambian por mensajes en español, que son
        // los que ven tanto la API como las páginas de error.
        $exceptions->map(
            ModelNotFoundException::class,
            fn (ModelNotFoundException $e) => new NotFoundHttpException('No se encontró lo que buscas.', $e)
        );
        $exceptions->map(
            UnauthorizedException::class,
            fn (UnauthorizedException $e) => new AccessDeniedHttpException(RespuestaErrorApi::SIN_PERMISO, $e)
        );
        $exceptions->map(
            AuthorizationException::class,
            fn (AuthorizationException $e) => $e->hasStatus() && $e->status() !== 403
                ? new HttpException($e->status(), '', $e)
                : new AccessDeniedHttpException(RespuestaErrorApi::SIN_PERMISO, $e)
        );

        // Respuesta JSON en español para /api; un 500 nunca muestra detalles,
        // ni con APP_DEBUG=true (regla del equipo).
        $exceptions->render(fn (Throwable $e, Request $request) => RespuestaErrorApi::render($e, $request));
    })->create();