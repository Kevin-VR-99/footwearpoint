<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso al cliente de un paso de su solicitud de cambio. */
class EstadoSolicitudCambioMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ?string $nombre, public string $titulo, public string $mensaje)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->titulo.' - FootwearPoint');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.estado-solicitud-cambio');
    }
}
