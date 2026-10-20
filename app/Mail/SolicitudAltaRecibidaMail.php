<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * TG-220 (E2-08) — Acuse para el administrador de la distribuidora: su
 * solicitud de alta llegó y está en revisión.
 *
 * Sale del remitente configurado (MAIL_FROM_ADDRESS / MAIL_FROM_NAME).
 */
class SolicitudAltaRecibidaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreDistribuidora,
        public ?string $nombreDestinatario = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Recibimos tu solicitud de alta en FootwearPoint',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.solicitud-alta-recibida',
            with: [
                'nombreDistribuidora' => $this->nombreDistribuidora,
                'nombreDestinatario' => $this->nombreDestinatario,
            ],
        );
    }
}
