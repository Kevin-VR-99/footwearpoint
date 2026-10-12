<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * TG-220 (E2-08) — Aviso al admin general de que llegó una solicitud de alta
 * desde el formulario público.
 *
 * Sale del remitente configurado (MAIL_FROM_ADDRESS / MAIL_FROM_NAME).
 */
class SolicitudAltaDistribuidoraMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreDistribuidora,
        public string $nombreAdministrador,
        public string $emailAdministrador,
        public ?string $planSolicitado = null,
        public ?string $nombreDestinatario = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Nueva solicitud de alta: «{$this->nombreDistribuidora}»",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.solicitud-alta-distribuidora',
            with: [
                'nombreDistribuidora' => $this->nombreDistribuidora,
                'nombreAdministrador' => $this->nombreAdministrador,
                'emailAdministrador' => $this->emailAdministrador,
                'planSolicitado' => $this->planSolicitado,
                'nombreDestinatario' => $this->nombreDestinatario,
                // TG-220: cae directo en el filtro de pendientes.
                'urlPanel' => route('admin.dashboard', ['estado' => 'pendiente']),
            ],
        );
    }
}
