<?php

use App\Models\ClienteDirecto;
use App\Services\Perfil\ActualizarPerfilAction;
use App\Support\MensajeError;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Teléfono — FootwearPoint')] class extends Component {
    public string $telefono = '';

    public string $errorMsg = '';

    public function mount(): void
    {
        if (! Auth::check() || ! ClienteDirecto::debeRecapturarTelefono(Auth::user())) {
            $this->redirect(route('login'), navigate: true);
        }
    }

    public function guardar(ActualizarPerfilAction $accion): void
    {
        $this->errorMsg = '';
        $this->validate([
            'telefono' => ['required', 'string', 'max:30'],
        ], [
            'telefono.required' => 'Escribe el teléfono.',
        ]);

        try {
            $usuario = Auth::user();
            $accion->ejecutar($usuario, $usuario->nombreVisible() ?? '', $this->telefono, true);
        } catch (ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo guardar el teléfono.';

            return;
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo guardar el teléfono.');

            return;
        }

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        session()->flash('aviso_acceso', 'Teléfono guardado. Entra desde la app FootwearPoint.');

        $this->redirect(route('login'), navigate: true);
    }
};
?>

<div class="mx-auto max-w-md px-4 py-10">
    <h1 class="text-xl font-semibold text-slate-900">Vuelve a escribir tu teléfono</h1>
    <p class="mt-2 text-sm text-slate-600">El que tenías estaba repetido en tu distribuidora. Hace falta uno de 10 dígitos.</p>
    @if ($errorMsg !== '')
        <p class="mt-3 text-sm text-fp-danger">{{ $errorMsg }}</p>
    @endif
    <form wire:submit="guardar" class="mt-4 space-y-3">
        <label class="block text-sm">Teléfono
            <input type="text" wire:model="telefono" class="mt-1 w-full rounded-lg text-sm" data-recapturar-telefono>
        </label>
        <button type="submit" class="fp-btn fp-btn-primario">Guardar</button>
    </form>
</div>
