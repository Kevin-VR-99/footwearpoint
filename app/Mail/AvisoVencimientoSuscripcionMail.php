<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso de que la mensualidad está por vencer, en gracia o suspendida. */
class AvisoVencimientoSuscripcionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreDistribuidora,
        public string $tipo,
        public string $texto,
        public ?string $nombreDestinatario = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $nombre = $this->nombreDistribuidora;

        $asunto = match ($this->tipo) {
            'por_vencer_3' => "La mensualidad de «{$nombre}» vence en 3 días",
            'por_vencer_1' => "La mensualidad de «{$nombre}» vence mañana",
            'vence_hoy' => "La mensualidad de «{$nombre}» vence hoy",
            'gracia' => "La mensualidad de «{$nombre}» está en periodo de gracia",
            'suspendida' => "«{$nombre}» fue suspendida por falta de pago",
            default => "La mensualidad de «{$nombre}» sigue sin pagarse",
        };

        return new Envelope(subject: $asunto);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.aviso-vencimiento-suscripcion');
    }
}
