<?php

use App\Services\Auth\EnviarEnlaceRecuperacionAction;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.guest')] #[Title('Recuperar contraseña — FootwearPoint')] class extends Component
{
    public string $email = '';
    public string $status = '';

    /** Segundos que faltan para poder pedir otro enlace (0 = ya se puede). */
    public int $espera = 0;

    /** Lo que hay que esperar entre un enlace y otro; igual que el throttle del broker. */
    public const SEGUNDOS_ESPERA = 60;

    /** Correo de la última petición, para que al recargar siga el contador. */
    private const SESION_CORREO = 'recuperar_password.correo';

    public function mount(): void
    {
        $correo = (string) session(self::SESION_CORREO, '');

        if ($correo !== '' && ($faltan = RateLimiter::availableIn($this->claveEspera($correo))) > 0
            && RateLimiter::tooManyAttempts($this->claveEspera($correo), 1)) {
            $this->email = $correo;
            $this->espera = $faltan;
            $this->status = EnviarEnlaceRecuperacionAction::MENSAJE;
        }
    }

    /** Por correo + IP. Se usa exista o no la cuenta, así no revela nada (TG-141). */
    private function claveEspera(string $correo): string
    {
        return 'recuperar-password-espera|'.sha1(mb_strtolower(trim($correo))).'|'.request()->ip();
    }

    protected function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }

    protected function messages(): array
    {
        return [
            'email.required' => 'El correo es obligatorio.',
            'email.email'    => 'El correo no es válido.',
        ];
    }

    /**
     * Siempre el mismo aviso, exista o no el correo (TG-141): antes un correo
     * inexistente mostraba un error y así se podía averiguar quién tiene
     * cuenta. Misma regla y mismo mensaje que la API.
     */
    public function sendResetLink(EnviarEnlaceRecuperacionAction $accion)
    {
        $this->validate();

        if ($this->demasiadosIntentos('enlace')) {
            return;
        }

        $clave = $this->claveEspera($this->email);

        // Todavía no pasa el minuto: no se vuelve a mandar, solo se muestra
        // cuánto falta. Pasa igual exista o no la cuenta.
        if (RateLimiter::tooManyAttempts($clave, 1)) {
            $this->espera = RateLimiter::availableIn($clave);
            $this->status = EnviarEnlaceRecuperacionAction::MENSAJE;
            return;
        }

        $this->status = $accion->ejecutar($this->email);

        RateLimiter::hit($clave, self::SEGUNDOS_ESPERA);
        session([self::SESION_CORREO => $this->email]);
        $this->espera = self::SEGUNDOS_ESPERA;
    }
    /**
     * Tope por IP (5 por minuto), igual que en la API. Devuelve true si ya se
     * pasó; en ese caso deja el mensaje en el campo de correo.
     */
    private function demasiadosIntentos(string $accion): bool
    {
        $clave = 'recuperar-password-web|'.$accion.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($clave, 5)) {
            $this->addError('email', 'Demasiados intentos. Espera '.RateLimiter::availableIn($clave).' segundos y vuelve a intentarlo.');
            return true;
        }

        RateLimiter::hit($clave, 60);
        return false;
    }
};
?>

<div class="fp-acceso-tarjeta p-8">
    <div class="fp-acceso-logo mb-4 flex justify-center">
        <img src="{{ asset('brand/logo-full-160.png') }}" alt="Footwear Point" class="h-16 w-auto object-contain">
    </div>
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-slate-900">Recuperar contraseña</h1>
        <p class="text-sm text-slate-500 mt-1">Te enviaremos un enlace de restablecimiento</p>
    </div>

    @if ($status)
        <div class="fp-emerger mb-4 rounded-xl border border-fp-badge-success-fg/20 bg-fp-badge-success-bg p-3 text-sm text-fp-badge-success-fg" role="status">
            {{ $status }}
        </div>
    @endif

    <form wire:submit="sendResetLink" class="space-y-5">
        <div class="fp-campo">
            <label for="forgot-password-email" class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
            <input id="forgot-password-email"
                type="email"
                wire:model="email"
                class="w-full rounded-lg"
                placeholder="correo@ejemplo.com"
                autofocus
            >
            @error('email')
                <p class="text-sm text-fp-danger mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div
            x-data="{
                faltan: $wire.entangle('espera'),
                total: {{ $this::SEGUNDOS_ESPERA }},
                reloj: null,
                iniciar() {
                    clearInterval(this.reloj);
                    if (this.faltan > 0) {
                        this.reloj = setInterval(() => { if (this.faltan > 0) { this.faltan--; } else { clearInterval(this.reloj); } }, 1000);
                    }
                },
                texto() { return Math.floor(this.faltan / 60) + ':' + String(this.faltan % 60).padStart(2, '0'); },
            }"
            x-init="iniciar(); $watch('faltan', (v, antes) => { if (v > antes) iniciar(); })"
        >
            <button
                type="submit"
                class="fp-btn fp-btn-oscuro w-full py-2.5"
                wire:loading.attr="disabled"
                x-bind:disabled="faltan > 0"
                @disabled($espera > 0)
            >
                <span wire:loading.remove>
                    <span x-show="faltan > 0" @if ($espera <= 0) style="display:none" @endif class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        Reenviar en <span x-text="texto()">{{ intdiv($espera, 60) }}:{{ str_pad((string) ($espera % 60), 2, '0', STR_PAD_LEFT) }}</span>
                    </span>
                    <span x-show="faltan <= 0" @if ($espera > 0) style="display:none" @endif>{{ $status ? 'Reenviar enlace' : 'Enviar enlace' }}</span>
                </span>
                <span wire:loading>Enviando...</span>
            </button>
            <div x-show="faltan > 0" @if ($espera <= 0) style="display:none" @endif class="mt-2 h-1 w-full overflow-hidden rounded bg-slate-200" role="progressbar" aria-label="Tiempo para reenviar">
                <div class="h-full bg-fp-primary transition-all duration-1000 ease-linear" x-bind:style="'width:' + (faltan / total * 100) + '%'"></div>
            </div>
        </div>

        <div class="text-center">
            <a href="{{ route('login') }}" class="fp-enlace text-sm">
                Volver al login
            </a>
        </div>
    </form>
</div>