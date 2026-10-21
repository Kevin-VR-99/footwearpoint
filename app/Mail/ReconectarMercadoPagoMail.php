<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Pide a la distribuidora volver a conectar su cuenta de Mercado Pago. */
class ReconectarMercadoPagoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $titulo, public string $mensaje)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->titulo);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e($this->mensaje).'</p><p>— FootwearPoint</p>');
    }
}
