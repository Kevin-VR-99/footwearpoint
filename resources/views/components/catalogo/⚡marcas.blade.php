<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Marca;
use App\Services\Catalogo\GestionarMarcaAction;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public ?string $errorNegocio = null;

    public $marcas = [];
    public ?int $marcaEditandoId = null;
    public bool $mostrandoFormularioMarca = false;
    public string $marca_nombre = '';
    public ?string $marca_descripcion = null;
    public bool $marca_activa = true;
    public $marca_logotipo = null;
    public ?string $marca_logotipo_url_actual = null;

    public function mount(): void
    {
        $this->cargarMarcas();
    }

    private function cargarMarcas(): void
    {
        $this->marcas = Marca::all();
    }

    public function abrirFormularioCrearMarca(): void
    {
        $this->marcaEditandoId = null;
        $this->marca_nombre = '';
        $this->marca_descripcion = null;
        $this->marca_activa = true;
        $this->marca_logotipo = null;
        $this->marca_logotipo_url_actual = null;
        $this->errorNegocio = null;
        $this->mostrandoFormularioMarca = true;
    }

    public function abrirFormularioEditarMarca(int $id): void
    {
        $marca = Marca::findOrFail($id);
        $this->marcaEditandoId = $marca->id;
        $this->marca_nombre = $marca->nombre;
        $this->marca_descripcion = $marca->descripcion;
        $this->marca_activa = (bool) $marca->activa;
        $this->marca_logotipo = null;
        $this->marca_logotipo_url_actual = $marca->logotipo_url;
        $this->errorNegocio = null;
        $this->mostrandoFormularioMarca = true;
    }

    public function cancelarFormularioMarca(): void
    {
        $this->mostrandoFormularioMarca = false;
        $this->marcaEditandoId = null;
        $this->errorNegocio = null;
    }

    public function guardarMarca(): void
    {
        $this->errorNegocio = null;
        $datos = $this->validate([
            'marca_nombre' => ['required', 'string', 'max:120'],
            'marca_descripcion' => ['nullable', 'string'],
            'marca_logotipo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);
        $payload = [
            'nombre' => $datos['marca_nombre'],
            'descripcion' => $datos['marca_descripcion'],
            'activa' => $this->marca_activa,
        ];
        $accion = app(GestionarMarcaAction::class);
        try {
            if ($this->marcaEditandoId) {
                $accion->actualizar(Marca::findOrFail($this->marcaEditandoId), $payload, $this->marca_logotipo);
            } else {
                $accion->crear($payload, $this->marca_logotipo);
            }
        } catch (OperacionInvalidaException $e) {
            $this->errorNegocio = $e->getMessage();
            return;
        }
        $this->marca_logotipo = null;
        $this->mostrandoFormularioMarca = false;
        $this->marcaEditandoId = null;
        $this->cargarMarcas();
        $this->dispatch('guardado', mensaje: 'Marca guardada correctamente.');
    }

};
?>

<div>
    @if ($errorNegocio)
        <div class="mb-4 rounded-md bg-fp-badge-danger-bg text-fp-badge-danger-fg px-4 py-2 text-sm">
            {{ $errorNegocio }}</div>
    @endif
    @if (!$mostrandoFormularioMarca)
        <div class="bg-white rounded-lg shadow-sm p-6 max-w-3xl">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-sm font-semibold text-slate-700">Marcas</h2>
                <button type="button" wire:click="abrirFormularioCrearMarca"
                    class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">+ Nueva
                    marca</button>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 border-b">
                        <th class="py-2"></th>
                        <th class="py-2">Nombre</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($marcas as $marca)
                        <tr class="border-b last:border-0">
                            <td class="py-2">
                                @if ($marca->logotipo_url)
                                    <img src="{{ $marca->logotipo_url }}" class="h-8 w-8 object-cover rounded">
                                @endif
                            </td>
                            <td class="py-2">{{ $marca->nombre }}</td>
                            <td class="py-2">{{ $marca->activa ? 'Activa' : 'Inactiva' }}</td>
                            <td class="py-2 text-right">
                                <button type="button"
                                    wire:click="abrirFormularioEditarMarca({{ $marca->id }})"
                                    class="text-fp-primary text-xs font-medium">Editar</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <form wire:submit="guardarMarca" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
            <h2 class="text-sm font-semibold text-slate-700">
                {{ $marcaEditandoId ? 'Editar marca' : 'Nueva marca' }}</h2>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                <input type="text" wire:model="marca_nombre" class="w-full rounded-md border-slate-300">
                @error('marca_nombre')
                    <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span>
                @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Descripción</label>
                <textarea wire:model="marca_descripcion" rows="2" class="w-full rounded-md border-slate-300"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Logotipo</label>
                @if ($marca_logotipo)
                    <img src="{{ $marca_logotipo->temporaryUrl() }}" class="h-16 w-16 object-cover rounded mb-2">
                @elseif ($marca_logotipo_url_actual)
                    <img src="{{ $marca_logotipo_url_actual }}" class="h-16 w-16 object-cover rounded mb-2">
                @endif
                <input type="file" wire:model="marca_logotipo" accept="image/png,image/jpeg">
                @error('marca_logotipo')
                    <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span>
                @enderror
            </div>
            @if ($marcaEditandoId)
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="marca_activa" class="rounded border-slate-300">
                    Marca activa
                </label>
            @endif
            <div class="flex gap-2">
                <button type="submit"
                    class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled">Guardar</button>
                <button type="button" wire:click="cancelarFormularioMarca"
                    class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
            </div>
        </form>
    @endif
</div>
