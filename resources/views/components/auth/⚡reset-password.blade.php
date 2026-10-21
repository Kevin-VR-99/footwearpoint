<?php

use App\Services\Auth\RestablecerPasswordAction;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.guest')] #[Title('Restablecer contraseña — FootwearPoint')] class extends Component
{
    #[Url]
    public string $token = '';

    // TG-188 (A6): el enlace del correo trae el correo en la direccion
    // (?email=...). Sin #[Url] la persona tenia que escribirlo otra vez.
    #[Url]
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    protected function rules(): array
    {
        return [
            'token'    => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }

    protected function messages(): array
    {
        return [
            'token.max' => 'El enlace para restablecer la contraseña no es válido.',
            'password.max' => 'La contraseña no puede tener más de 255 caracteres.',
            'email.required'     => 'El correo es obligatorio.',
            'email.email'        => 'El correo no es válido.',
            'password.required'  => 'La contraseña es obligatoria.',
            'password.min'       => 'Mínimo 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }

    /**
     * Misma acción que la API: además de cambiar la contraseña, revoca todos
     * los tokens de la cuenta, así la app pide iniciar sesión otra vez (TG-142).
     */
    public function resetPassword(RestablecerPasswordAction $accion)
    {
        $this->validate();

        if ($this->demasiadosIntentos('restablecer')) {
            return;
        }

        if ($accion->ejecutar($this->email, $this->password, $this->password_confirmation, $this->token)) {
            session()->flash('status', 'Contraseña restablecida. Ya puedes iniciar sesión.');
            return $this->redirect(route('login'), navigate: true);
        }

        // Mismo mensaje exista o no el correo, para no revelar cuentas.
        $this->addError('email', RestablecerPasswordAction::MENSAJE_ENLACE_INVALIDO);
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
        <h1 class="text-2xl font-bold text-slate-900">Nueva contraseña</h1>
        <p class="text-sm text-slate-500 mt-1">Define tu nueva contraseña de acceso</p>
    </div>

    <form wire:submit="resetPassword" class="space-y-5">
        <div class="fp-campo">
            <label for="reset-password-email" class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
            <input id="reset-password-email"
                type="email"
                wire:model="email"
                class="w-full rounded-lg"
                placeholder="correo@ejemplo.com"
            >
            @error('email')
                <p class="text-sm text-fp-danger mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="fp-campo">
            <label for="reset-password-password" class="block text-sm font-medium text-slate-700 mb-1">Nueva contraseña</label>
            <input id="reset-password-password"
                type="password"
                wire:model="password"
                class="w-full rounded-lg"
                placeholder="Mínimo 8 caracteres"
            >
            @error('password')
                <p class="text-sm text-fp-danger mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="fp-campo">
            <label for="reset-password-password-confirmation" class="block text-sm font-medium text-slate-700 mb-1">Confirmar contraseña</label>
            <input id="reset-password-password-confirmation"
                type="password"
                wire:model="password_confirmation"
                class="w-full rounded-lg"
                placeholder="Repite la contraseña"
            >
        </div>

        <button
            type="submit"
            class="fp-btn fp-btn-oscuro w-full py-2.5"
            wire:loading.attr="disabled"
        >
            <span wire:loading.remove>Guardar contraseña</span>
            <span wire:loading>Guardando...</span>
        </button>

        <div class="text-center">
            <a href="{{ route('login') }}" class="fp-enlace text-sm">
                Volver al login
            </a>
        </div>
    </form>
</div>