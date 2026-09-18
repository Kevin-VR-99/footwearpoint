<?php

use App\Models\CategoriaProducto;
use App\Services\Catalogo\GestionarCategoriaProductoAction;
use Livewire\Component;

new class extends Component {
    public $categorias = [];
    public ?int $categoriaEditandoId = null;
    public bool $mostrandoFormularioCategoria = false;
    public string $categoria_nombre = '';
    public ?string $categoria_descripcion = null;
    public bool $categoria_activa = true;

    public function mount(): void
    {
        $this->cargarCategorias();
    }

    private function cargarCategorias(): void
    {
        $this->categorias = CategoriaProducto::all();
    }

    public function abrirFormularioCrearCategoria(): void
    {
        $this->categoriaEditandoId = null;
        $this->categoria_nombre = '';
        $this->categoria_descripcion = null;
        $this->categoria_activa = true;
        $this->mostrandoFormularioCategoria = true;
    }

    public function abrirFormularioEditarCategoria(int $id): void
    {
        $categoria = CategoriaProducto::findOrFail($id);
        $this->categoriaEditandoId = $categoria->id;
        $this->categoria_nombre = $categoria->nombre;
        $this->categoria_descripcion = $categoria->descripcion;
        $this->categoria_activa = (bool) $categoria->activa;
        $this->mostrandoFormularioCategoria = true;
    }

    public function cancelarFormularioCategoria(): void
    {
        $this->mostrandoFormularioCategoria = false;
        $this->categoriaEditandoId = null;
    }

    public function guardarCategoria(): void
    {
        $datos = $this->validate([
            'categoria_nombre' => ['required', 'string', 'max:120'],
            'categoria_descripcion' => ['nullable', 'string', 'max:300'],
        ]);
        $payload = [
            'nombre' => $datos['categoria_nombre'],
            'descripcion' => $datos['categoria_descripcion'],
            'activa' => $this->categoria_activa,
        ];
        $accion = app(GestionarCategoriaProductoAction::class);
        if ($this->categoriaEditandoId) {
            $accion->actualizar(CategoriaProducto::findOrFail($this->categoriaEditandoId), $payload);
        } else {
            $accion->crear($payload);
        }
        $this->mostrandoFormularioCategoria = false;
        $this->categoriaEditandoId = null;
        $this->cargarCategorias();
        $this->dispatch('guardado', mensaje: 'Categoría guardada correctamente.');
    }

};
?>

<div>
    @if (!$mostrandoFormularioCategoria)
        <div class="bg-white rounded-lg shadow-sm p-6 max-w-3xl">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-sm font-semibold text-slate-700">Categorías</h2>
                <button type="button" wire:click="abrirFormularioCrearCategoria"
                    class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">+ Nueva
                    categoría</button>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 border-b">
                        <th class="py-2">Nombre</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($categorias as $categoria)
                        <tr class="border-b last:border-0">
                            <td class="py-2">{{ $categoria->nombre }}</td>
                            <td class="py-2">{{ $categoria->activa ? 'Activa' : 'Inactiva' }}</td>
                            <td class="py-2 text-right">
                                <button type="button"
                                    wire:click="abrirFormularioEditarCategoria({{ $categoria->id }})"
                                    class="text-fp-primary text-xs font-medium">Editar</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <form wire:submit="guardarCategoria" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
            <h2 class="text-sm font-semibold text-slate-700">
                {{ $categoriaEditandoId ? 'Editar categoría' : 'Nueva categoría' }}</h2>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                <input type="text" wire:model="categoria_nombre" class="w-full rounded-md border-slate-300">
                @error('categoria_nombre')
                    <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span>
                @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Descripción</label>
                <textarea wire:model="categoria_descripcion" rows="2" class="w-full rounded-md border-slate-300"></textarea>
            </div>
            @if ($categoriaEditandoId)
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="categoria_activa" class="rounded border-slate-300">
                    Categoría activa
                </label>
            @endif
            <div class="flex gap-2">
                <button type="submit"
                    class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled">Guardar</button>
                <button type="button" wire:click="cancelarFormularioCategoria"
                    class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
            </div>
        </form>
    @endif
</div>
