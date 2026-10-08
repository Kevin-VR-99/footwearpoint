<?php

namespace App\Mail;

use App\Http\Controllers\ComprobanteVentaController;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Comprobante de venta directa por correo, con el PDF adjunto (E7-02 / TG-115).
 *
 * Recibe los datos ya armados por DatosComprobanteVentaAction, así que el PDF
 * adjunto es idéntico al que se descarga desde la página.
 */
class ComprobanteVentaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $comprobante)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Comprobante de venta ' . $this->comprobante['folio']
                . ' - ' . $this->comprobante['distribuidora']['nombre_comercial'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.comprobante-venta',
            with: ['c' => $this->comprobante],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => ComprobanteVentaController::generarPdf($this->comprobante)->output(),
                ComprobanteVentaController::nombreArchivo($this->comprobante),
            )->withMime('application/pdf'),
        ];
    }
}
