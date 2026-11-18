<?php

use App\Services\Perfil\CambiarPasswordAction;
use App\Support\PersonalPlataforma;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| TG-336 (K38) - Cambio obligatorio de la contraseña temporal en el panel.
*/
new #[Layout('layouts.guest')] #[Title('Cambiar contraseña — FootwearPoint')] class extends Component
{
    public string $password_actual = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount()
    {
        if (! Auth::user()?->debe_cambiar_password) {
            return $this->irAlPanel();
        }
    }

    public function guardar(CambiarPasswordAction $accion)
    {
        $this->validate([
            'password_actual' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::defaults(), 'max:255', 'confirmed', 'different:password_actual'],
        ], [
            'password_actual.required' => 'Escribe tu contraseña temporal.',
            'password_actual.max' => 'La contraseña no puede tener más de 255 caracteres.',
            'password.required' => 'Escribe tu contraseña nueva.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.max' => 'La contraseña no puede tener más de 255 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.different' => 'Elige una contraseña distinta a la temporal.',
        ]);

        $accion->ejecutar(Auth::user(), $this->password_actual, $this->password, null);

        session()->regenerate();
        $this->reset('password_actual', 'password', 'password_confirmation');

        return $this->irAlPanel();
    }

    private function irAlPanel()
    {
        $usuario = Auth::user();

        if ($usuario === null) {
            return $this->redirect(route('login'), navigate: true);
        }

        $destino = PersonalPlataforma::esAdminGeneral($usuario) ? 'admin.dashboard' : 'dashboard';

        return $this->redirect(route($destino), navigate: true);
    }
};
?>

<div class="fp-acceso-tarjeta p-8">
    <div class="mb-8 text-center">
        <h1 class="text-2xl font-bold text-slate-900">Cambia tu contraseña</h1>
        <p class="mt-1 text-sm text-slate-600">Entraste con una contraseña temporal. Elige una tuya para continuar.</p>
    </div>

    <form wire:submit="guardar" class="space-y-5">
        <x-ui.campo etiqueta="Contraseña temporal" para="cambiar-actual" error="password_actual" requerido>
            <x-ui.entrada tipo="password" id="cambiar-actual" wire:model="password_actual" autocomplete="current-password" required />
        </x-ui.campo>

        <x-ui.campo etiqueta="Contraseña nueva" para="cambiar-nueva" error="password" ayuda="Mínimo 8 caracteres, con letras y números." requerido>
            <x-ui.entrada tipo="password" id="cambiar-nueva" wire:model="password" autocomplete="new-password" required />
        </x-ui.campo>

        <x-ui.campo etiqueta="Confirmar contraseña" para="cambiar-confirmacion" requerido>
            <x-ui.entrada tipo="password" id="cambiar-confirmacion" wire:model="password_confirmation" autocomplete="new-password" required />
        </x-ui.campo>

        <button type="submit" class="fp-btn fp-btn-oscuro w-full py-2.5" wire:loading.attr="disabled" wire:target="guardar">
            <span wire:loading.remove wire:target="guardar">Guardar contraseña</span>
            <span wire:loading wire:target="guardar">Guardando…</span>
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="fp-enlace text-sm">Cerrar sesión</button>
    </form>
</div>
