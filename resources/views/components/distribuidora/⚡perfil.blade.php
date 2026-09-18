<?php

use App\Models\Distribuidora;
use App\Services\Distribuidora\ActualizarPerfilDistribuidoraAction;
use App\Support\Tenant;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public string $nombre_comercial = '';
    public ?string $descripcion_publica = null;
    public ?string $direccion_publica = null;
    public ?string $telefono_publico = null;
    public ?string $email_publico = null;
    public ?string $horario_publico = null;
    public $logotipo = null;
    public ?string $logotipo_url_actual = null;

    public function mount(): void
    {
        $distribuidora = Distribuidora::findOrFail(Tenant::id());
        $this->nombre_comercial = $distribuidora->nombre_comercial;
        $this->descripcion_publica = $distribuidora->descripcion_publica;
        $this->direccion_publica = $distribuidora->direccion_publica;
        $this->telefono_publico = $distribuidora->telefono_publico;
        $this->email_publico = $distribuidora->email_publico;
        $this->horario_publico = $distribuidora->horario_publico;
        $this->logotipo_url_actual = $distribuidora->logotipo_url;
    }

    public function guardarPerfil(): void
    {
        $datos = $this->validate([
            'nombre_comercial' => ['required', 'string', 'max:150'],
            'descripcion_publica' => ['nullable', 'string'],
            'direccion_publica' => ['nullable', 'string', 'max:300'],
            'telefono_publico' => ['nullable', 'string', 'max:30'],
            'email_publico' => ['nullable', 'email', 'max:190'],
            'horario_publico' => ['nullable', 'string', 'max:300'],
            'logotipo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $distribuidora = Distribuidora::findOrFail(Tenant::id());
        app(ActualizarPerfilDistribuidoraAction::class)->ejecutar(
            $distribuidora,
            collect($datos)->except('logotipo')->all(),
            $this->logotipo
        );

        $this->logotipo = null;
        $this->mount();
        $this->dispatch('guardado', mensaje: 'Perfil actualizado correctamente.');
    }

};
?>

<div>
    <form wire:submit="guardarPerfil" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Nombre Comercial</label>
            <input type="text" wire:model="nombre_comercial" class="w-full rounded-md border-slate-300">
            @error('nombre_comercial') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Descripción</label>
            <textarea wire:model="descripcion_publica" rows="3" class="w-full rounded-md border-slate-300"></textarea>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Dirección</label>
            <input type="text" wire:model="direccion_publica" class="w-full rounded-md border-slate-300">
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                <input type="text" wire:model="telefono_publico" placeholder="+52 55 1234 5678" class="w-full rounded-md border-slate-300">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                <input type="email" wire:model="email_publico" class="w-full rounded-md border-slate-300">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Horario</label>
            <input type="text" wire:model="horario_publico" placeholder="Lunes a sábado, 9:00 a 19:00" class="w-full rounded-md border-slate-300">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Logotipo</label>
            @if ($logotipo)
                <img src="{{ $logotipo->temporaryUrl() }}" class="h-16 w-16 object-cover rounded mb-2">
            @elseif ($logotipo_url_actual)
                <img src="{{ $logotipo_url_actual }}" class="h-16 w-16 object-cover rounded mb-2">
            @endif
            <input type="file" wire:model="logotipo" accept="image/png,image/jpeg">
            <p class="text-xs text-fp-text-muted mt-1">PNG o JPG, hasta 2MB.</p>
        </div>
        <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled">Guardar Cambios</button>
    </form>
</div>
