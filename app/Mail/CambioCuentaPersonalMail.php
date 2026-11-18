<?php

namespace App\Mail;

use App\Models\Usuario;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** TG-337: aviso de que un administrador cambió algo de la cuenta. Nunca lleva contraseñas. */
class CambioCuentaPersonalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Usuario $usuario,
        public string $mensaje,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Hubo un cambio en tu cuenta de FootwearPoint');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cambio-cuenta-personal',
            with: [
                'nombre' => $this->usuario->nombre,
                'mensaje' => $this->mensaje,
            ],
        );
    }
}
