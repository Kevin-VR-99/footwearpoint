<?php

use App\Services\Auth\RestablecerPasswordAction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.guest')] #[Title('Restablecer contraseña — FootwearPoint')] class extends Component
{
    #[Url]
    public string $token = '';

    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    protected function rules(): array
    {
        return [
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    protected function messages(): array
    {
        return [
            'email.required'     => 'El correo es obligatorio.',
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

        if ($accion->ejecutar($this->email, $this->password, $this->password_confirmation, $this->token)) {
            session()->flash('status', 'Contraseña restablecida. Ya puedes iniciar sesión.');
            return $this->redirect(route('login'), navigate: true);
        }

        // Mismo mensaje exista o no el correo, para no revelar cuentas.
        $this->addError('email', RestablecerPasswordAction::MENSAJE_ENLACE_INVALIDO);
    }
};
?>

<div class="bg-white rounded-xl shadow-lg border border-slate-200 p-8">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-slate-900">Nueva contraseña</h1>
        <p class="text-sm text-slate-500 mt-1">Define tu nueva contraseña de acceso</p>
    </div>

    <form wire:submit="resetPassword" class="space-y-5">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
            <input
                type="email"
                wire:model="email"
                class="w-full rounded-lg border-slate-300 focus:border-blue-600 focus:ring-blue-600"
                placeholder="correo@ejemplo.com"
            >
            @error('email')
                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Nueva contraseña</label>
            <input
                type="password"
                wire:model="password"
                class="w-full rounded-lg border-slate-300 focus:border-blue-600 focus:ring-blue-600"
                placeholder="Mínimo 8 caracteres"
            >
            @error('password')
                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Confirmar contraseña</label>
            <input
                type="password"
                wire:model="password_confirmation"
                class="w-full rounded-lg border-slate-300 focus:border-blue-600 focus:ring-blue-600"
                placeholder="Repite la contraseña"
            >
        </div>

        <button
            type="submit"
            class="w-full rounded-lg bg-[#111E38] text-white font-medium py-2.5 hover:bg-[#1E2F52] transition"
            wire:loading.attr="disabled"
        >
            <span wire:loading.remove>Guardar contraseña</span>
            <span wire:loading>Guardando...</span>
        </button>

        <div class="text-center">
            <a href="{{ route('login') }}" class="text-sm text-blue-700 hover:underline">
                Volver al login
            </a>
        </div>
    </form>
</div>