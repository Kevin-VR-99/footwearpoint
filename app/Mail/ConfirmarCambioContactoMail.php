<?php

namespace App\Mail;

use App\Models\CambioContactoPendiente;
use App\Models\Usuario;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Enlace para confirmar un correo o teléfono nuevo (CambioContactoAction). */
class ConfirmarCambioContactoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Usuario $usuario,
        public CambioContactoPendiente $cambio,
        public string $url,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->cambio->tipo === 'email'
                ? 'Confirma tu correo nuevo de FootwearPoint'
                : 'Confirma tu teléfono nuevo de FootwearPoint',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.confirmar-cambio-contacto',
            with: [
                'nombre'  => $this->usuario->nombreVisible(),
                'tipo'    => $this->cambio->tipo,
                'valor'   => $this->cambio->valor_nuevo,
                'url'     => $this->url,
                'minutos' => \App\Services\Perfil\CambioContactoAction::MINUTOS,
            ],
        );
    }
}
