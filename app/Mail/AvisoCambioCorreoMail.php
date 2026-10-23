<?php

namespace App\Mail;

use App\Models\Usuario;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso al correo anterior de que se pidió cambiarlo. */
class AvisoCambioCorreoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Usuario $usuario,
        public string $correoNuevo,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Se pidió cambiar el correo de tu cuenta de FootwearPoint');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.aviso-cambio-correo',
            with: [
                'nombre' => $this->usuario->nombreVisible(),
                'nuevo'  => $this->correoNuevo,
            ],
        );
    }
}
