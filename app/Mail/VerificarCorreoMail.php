<?php

namespace App\Mail;

use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * TG-189 (A11 / E1-03) — Correo para confirmar que la cuenta es suya.
 *
 * Mismo arreglo que el de restablecer contraseña (TG-188): Laravel trae el
 * suyo, pero en inglés y firmado "Laravel". Aquí se le cambia el contenido a
 * la notificación, no la notificación.
 *
 * Como lo pide la notificación y no un Mail::to(...), el destinatario se pone
 * aquí mismo, en el sobre.
 *
 * TG-220: el texto depende de quién lo recibe. Al administrador de una
 * distribuidora que acaba de pedir su alta (queda pendiente) se le habla de su
 * solicitud; a los demás (clientes de la app), de su cuenta para hacer pedidos.
 */
class VerificarCorreoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Usuario $usuario,
        public string $urlVerificacion,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [new Address($this->usuario->email, $this->usuario->nombreVisible())],
            subject: 'Confirma tu correo de FootwearPoint',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verificar-correo',
            with: [
                'nombre'  => $this->usuario->nombreVisible(),
                'url'     => $this->urlVerificacion,
                'minutos' => (int) config('auth.verification.expire', 60),
                // null si no es administrador de una solicitud pendiente.
                'distribuidoraEnSolicitud' => $this->distribuidoraEnSolicitud(),
            ],
        );
    }

    /**
     * Nombre de la distribuidora pendiente que administra este usuario, o null.
     * Sin el filtro por distribuidora: el correo sale sin sesión ni tenant.
     */
    private function distribuidoraEnSolicitud(): ?string
    {
        $staff = DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $this->usuario->id)
            ->where('tipo', 'administrador')
            ->whereHas('distribuidora', fn ($q) => $q->withoutGlobalScopes()->where('estado', 'pendiente'))
            ->with(['distribuidora' => fn ($q) => $q->withoutGlobalScopes()])
            ->first();

        return $staff?->distribuidora?->nombre_comercial;
    }
}
