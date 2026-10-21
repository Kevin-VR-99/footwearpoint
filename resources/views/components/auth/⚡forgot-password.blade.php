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

        $this->status = $accion->ejecutar($this->email);
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

<div class="bg-white rounded-xl shadow-lg border border-slate-200 p-8">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-slate-900">Recuperar contraseña</h1>
        <p class="text-sm text-slate-500 mt-1">Te enviaremos un enlace de restablecimiento</p>
    </div>

    @if ($status)
        <div class="mb-4 rounded-lg bg-green-50 text-green-700 text-sm p-3">
            {{ $status }}
        </div>
    @endif

    <form wire:submit="sendResetLink" class="space-y-5">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
            <input
                type="email"
                wire:model="email"
                class="w-full rounded-lg focus:border-blue-600 focus:ring-blue-600"
                placeholder="correo@ejemplo.com"
                autofocus
            >
            @error('email')
                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <button
            type="submit"
            class="fp-btn fp-btn-oscuro w-full py-2.5"
            wire:loading.attr="disabled"
        >
            <span wire:loading.remove>Enviar enlace</span>
            <span wire:loading>Enviando...</span>
        </button>

        <div class="text-center">
            <a href="{{ route('login') }}" class="text-sm text-blue-700 hover:underline">
                Volver al login
            </a>
        </div>
    </form>
</div>