<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso por correo cuando FootwearPoint cobra la mensualidad del plan
 * o el pago inmediato de una línea extra.
 */
class PagoSuscripcionMail extends Mailable
{
    use Queueable, SerializesModels;

    public const MENSUALIDAD = 'mensualidad';

    public const LINEA_EXTRA = 'linea_extra';

    public function __construct(
        public string $nombreDistribuidora,
        public string $tipo,
        public string $monto,
        public string $folio,
        public ?string $pagadoHasta = null,
        public ?string $plan = null,
        public int $lineasExtra = 0,
        public ?string $nombreDestinatario = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $nombre = $this->nombreDistribuidora;

        return new Envelope(
            subject: $this->tipo === self::LINEA_EXTRA
                ? "Recibimos el pago de una línea extra de «{$nombre}»"
                : "Recibimos el pago de la mensualidad de «{$nombre}»",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.pago-suscripcion');
    }
}
