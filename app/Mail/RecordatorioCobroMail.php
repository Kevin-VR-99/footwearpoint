<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Recordatorio de cobro: saldo, detalle de lo que se debe y cómo contactar a la distribuidora. Va en cola. */
class RecordatorioCobroMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public array $datos)
    {
    }

    public function envelope(): Envelope
    {
        $casa = $this->datos['distribuidora'];

        return new Envelope(
            subject: 'Recordatorio de pago - '.$casa['nombre'],
            replyTo: filled($casa['email']) ? [new Address($casa['email'], $casa['nombre'])] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recordatorio-cobro', with: ['d' => $this->datos]);
    }
}
