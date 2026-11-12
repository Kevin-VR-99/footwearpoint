<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Avisa al admin de la distribuidora que cambió la CLABE. No incluye el número completo. */
class CambioClabeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $mensaje)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Cambió la CLABE de tu distribuidora');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e($this->mensaje).'</p><p>— FootwearPoint</p>');
    }
}
