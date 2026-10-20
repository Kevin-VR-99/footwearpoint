<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * El correo para restablecer la contraseña, pero en cola.
 *
 * Antes la notificación ResetPassword de Laravel mandaba el correo en la misma
 * petición: si Gmail tardaba o fallaba, quien pedía el enlace se quedaba
 * esperando o veía un error 500. Ahora se guarda en la cola "database" y lo
 * manda el servicio footwearpoint-worker (php artisan queue:work en Railway).
 *
 * El contenido sigue siendo RestablecerPasswordMail: el toMailUsing() de
 * AppServiceProvider vale también para esta clase porque hereda de ResetPassword.
 */
class RestablecerPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /** Reintentos por si el servidor de correo falla un momento. */
    public int $tries = 3;

    /** @var array<int, int> segundos entre reintentos */
    public array $backoff = [30, 120];
}
