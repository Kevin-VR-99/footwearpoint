<?php

namespace App\Mail;

use App\Services\Distribuidora\NotificarCambioEstadoDistribuidoraAction as Notificar;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * TG-196 (G5) — Aviso a la distribuidora de que cambió su estado: aprobada,
 * rechazada (con su motivo), suspendida o reactivada.
 *
 * Sale del remitente configurado (MAIL_FROM_ADDRESS / MAIL_FROM_NAME).
 */
class CambioEstadoDistribuidoraMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nombreDistribuidora,
        public string $evento,
        public ?string $nombreDestinatario = null,
        public ?string $motivo = null,
        /**
         * TG-220: en la aprobación, los planes con su precio
         * (nombre, precio_base_mensual, lineas_incluidas, precio_linea_extra, destacado).
         *
         * @var list<array{nombre: string, precio_base_mensual: float, lineas_incluidas: int, precio_linea_extra: float, destacado: bool}>
         */
        public array $planes = [],
    ) {
    }

    public function envelope(): Envelope
    {
        $nombre = $this->nombreDistribuidora;

        return new Envelope(
            subject: match ($this->evento) {
                Notificar::APROBADA   => "Tu distribuidora «{$nombre}» fue aprobada en FootwearPoint",
                Notificar::RECHAZADA  => "La solicitud de «{$nombre}» en FootwearPoint fue rechazada",
                Notificar::SUSPENDIDA => "«{$nombre}» fue suspendida en FootwearPoint",
                Notificar::REACTIVADA => "«{$nombre}» fue reactivada en FootwearPoint",
                default               => "Cambió el estado de «{$nombre}» en FootwearPoint",
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cambio-estado-distribuidora',
            with: [
                'nombreDistribuidora' => $this->nombreDistribuidora,
                'evento'              => $this->evento,
                'nombreDestinatario'  => $this->nombreDestinatario,
                'motivo'              => $this->motivo,
                'planes'              => $this->planes,
                'urlLogin'            => route('login'),
            ],
        );
    }
}
