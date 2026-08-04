<?php

use App\Models\Usuario;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.guest')] #[Title('Iniciar sesión — FootwearPoint')] class extends Component
{
    public string $email = '';
    public string $password = '';
    public bool $remember = false;

    protected function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    protected function messages(): array
    {
        return [
            'email.required'    => 'El correo es obligatorio.',
            'email.email'       => 'El correo no es válido.',
            'password.required' => 'La contraseña es obligatoria.',
        ];
    }

    public function login()
    {
        $this->validate();

        $usuario = Usuario::where('email', $this->email)->first();

        if (!$usuario || !Hash::check($this->password, $usuario->password)) {
            $this->addError('email', 'Las credenciales son incorrectas.');
            return;
        }

        if ($usuario->estado !== 'activo') {
            $this->addError('email', 'Tu cuenta no está activa.');
            return;
        }

        Auth::login($usuario, $this->remember);
        session()->regenerate();

        $staff = \App\Models\DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $usuario->id)
            ->first();

        if ($staff) {
            setPermissionsTeamId($staff->distribuidora_id);
        } else {
            setPermissionsTeamId(0);
        }

        if ($usuario->hasRole('admin_general')) {
            return $this->redirect(route('admin.dashboard'), navigate: true);
        }

        return $this->redirect(route('dashboard'), navigate: true);
    }
};
?>

<div class="bg-white rounded-xl shadow-lg border border-slate-200 p-8">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-slate-900">FootwearPoint</h1>
        <p class="text-sm text-slate-500 mt-1">Inicia sesión en tu cuenta</p>
    </div>

    <form wire:submit="login" class="space-y-5">
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

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Contraseña</label>
            <input
                type="password"
                wire:model="password"
                class="w-full rounded-lg border-slate-300 focus:border-blue-600 focus:ring-blue-600"
                placeholder="••••••••"
            >
            @error('password')
                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" wire:model="remember" class="rounded border-slate-300 text-blue-600 focus:ring-blue-600">
                Recordarme
            </label>

            <a href="{{ route('password.request') }}" class="text-sm text-blue-700 hover:underline">
                ¿Olvidaste tu contraseña?
            </a>
        </div>

        <button
            type="submit"
            class="w-full rounded-lg bg-[#111E38] text-white font-medium py-2.5 hover:bg-[#1E2F52] transition"
            wire:loading.attr="disabled"
        >
            <span wire:loading.remove>Entrar</span>
            <span wire:loading>Entrando...</span>
        </button>
    </form>
</div>