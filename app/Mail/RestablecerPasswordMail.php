<?php

namespace App\Mail;

use App\Models\Usuario;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * TG-188 (A6) — Correo del enlace para restablecer la contrasena.
 *
 * Hasta ahora este correo era el que trae Laravel de fabrica: llegaba en
 * ingles ("Reset Password Notification... Regards, Laravel"), porque el
 * proyecto nunca escribio el suyo. Las pruebas no lo habian cachado porque
 * usan Notification::fake(), que comprueba que el correo SALE pero no lee lo
 * que dice.
 *
 * Lo manda la notificacion ResetPassword de Laravel, a la que
 * AppServiceProvider le dice que use este correo. Asi sirve para las dos
 * puertas sin tocarlas: el "olvide mi contrasena" de la web y de la app
 * (TG-141) y el boton "Enviar enlace" del panel (TG-192).
 *
 * Como lo pide la notificacion y no un Mail::to(...), el destinatario se pone
 * aqui mismo, en el sobre.
 */
class RestablecerPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Usuario $usuario,
        public string $token,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [new Address($this->usuario->email, $this->usuario->nombreVisible())],
            subject: 'Restablece tu contraseña de FootwearPoint',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.restablecer-password',
            with: [
                'nombre'  => $this->usuario->nombreVisible(),
                'minutos' => (int) config('auth.passwords.users.expire', 60),
                // El mismo enlace que armaba Laravel: /reset-password/{token}
                // con el correo en la direccion, para que la pagina lo llene.
                'urlEnlace' => route('password.reset', [
                    'token' => $this->token,
                    'email' => $this->usuario->email,
                ]),
            ],
        );
    }
}
