<?php

use Illuminate\Support\Facades\Password;
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

    public function sendResetLink()
    {
        $this->validate();
        $this->status = '';

        $status = Password::broker('users')->sendResetLink(
            ['email' => $this->email]
        );

        if ($status === Password::RESET_LINK_SENT) {
            $this->status = 'Te enviamos el enlace de recuperación. Revisa el log o tu correo.';
            return;
        }

        $this->addError('email', __($status));
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
                class="w-full rounded-lg border-slate-300 focus:border-blue-600 focus:ring-blue-600"
                placeholder="correo@ejemplo.com"
                autofocus
            >
            @error('email')
                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <button
            type="submit"
            class="w-full rounded-lg bg-[#111E38] text-white font-medium py-2.5 hover:bg-[#1E2F52] transition"
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