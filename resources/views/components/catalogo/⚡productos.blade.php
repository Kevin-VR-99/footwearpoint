<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\CategoriaProducto;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Services\Catalogo\GestionarProductoAction;
use App\Support\Tenant;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component {
    public ?string $errorNegocio = null;

    public $productos = [];
    public ?int $productoEditandoId = null;
    public bool $mostrandoFormularioProducto = false;
    public string $producto_modelo = '';
    public string $producto_nombre = '';
    public ?string $producto_descripcion = null;
    public ?int $producto_marca_id = null;
    public ?int $producto_linea_id = null;
    public ?int $producto_categoria_id = null;
    public bool $producto_activo = true;

    public $marcas = [];
    public $lineas = [];
    public $categorias = [];

    public function mount(): void
    {
        $this->cargarProductos();
        $this->cargarMarcas();
        $this->cargarLineas();
        $this->cargarCategorias();
    }

    private function cargarProductos(): void
    {
        $this->productos = Producto::with(['marca', 'linea', 'categoria'])
            ->latest()
            ->get();
    }

    private function cargarMarcas(): void
    {
        $this->marcas = Marca::all();
    }

    private function cargarLineas(): void
    {
        $this->lineas = Linea::latest()->get();
    }

    private function cargarCategorias(): void
    {
        $this->categorias = CategoriaProducto::all();
    }

    public function abrirFormularioCrearProducto(): void
    {
        $this->productoEditandoId = null;
        $this->producto_modelo = '';
        $this->producto_nombre = '';
        $this->producto_descripcion = null;
        $this->producto_marca_id = null;
        $this->producto_linea_id = null;
        $this->producto_categoria_id = null;
        $this->producto_activo = true;
        $this->errorNegocio = null;
        $this->mostrandoFormularioProducto = true;
    }

    public function abrirFormularioEditarProducto(int $id): void
    {
        $producto = Producto::findOrFail($id);
        $this->productoEditandoId = $producto->id;
        $this->producto_modelo = $producto->modelo;
        $this->producto_nombre = $producto->nombre;
        $this->producto_descripcion = $producto->descripcion;
        $this->producto_categoria_id = $producto->categoria_id;
        $this->producto_linea_id = $producto->linea_id;
        $this->producto_marca_id = $producto->marca_id;
        $this->producto_activo = (bool) $producto->activo;
        $this->errorNegocio = null;
        $this->mostrandoFormularioProducto = true;
    }

    public function cancelarFormularioProducto(): void
    {
        $this->mostrandoFormularioProducto = false;
        $this->productoEditandoId = null;
        $this->errorNegocio = null;
    }

    public function guardarProducto(): void
    {
        $this->errorNegocio = null;
        $esCreacion = !$this->productoEditandoId;
        $datos = $this->validate([
            'producto_modelo' => ['required', 'string', 'max:120'],
            'producto_nombre' => ['required', 'string', 'max:200'],
            'producto_descripcion' => ['nullable', 'string'],
            'producto_categoria_id' => ['required', 'integer', Rule::exists('categorias_producto', 'id')->where(fn($q) => $q->where('distribuidora_id', Tenant::id()))],
            'producto_linea_id' => ['required', 'integer', Rule::exists('lineas', 'id')->where(fn($q) => $q->where('distribuidora_id', Tenant::id()))],
            'producto_marca_id' => ['required', 'integer', Rule::exists('marcas', 'id')->where(fn($q) => $q->where('distribuidora_id', Tenant::id()))],
        ]);
        $accion = app(GestionarProductoAction::class);
        try {
            if ($esCreacion) {
                $accion->crear([
                    'marca_id' => $datos['producto_marca_id'],
                    'linea_id' => $datos['producto_linea_id'],
                    'categoria_id' => $datos['producto_categoria_id'],
                    'modelo' => $datos['producto_modelo'],
                    'nombre' => $datos['producto_nombre'],
                    'descripcion' => $datos['producto_descripcion'],
                ]);
            } else {
                $accion->actualizar(Producto::findOrFail($this->productoEditandoId), [
                    'marca_id' => $datos['producto_marca_id'],
                    'linea_id' => $datos['producto_linea_id'],
                    'categoria_id' => $datos['producto_categoria_id'],
                    'modelo' => $datos['producto_modelo'],
                    'nombre' => $datos['producto_nombre'],
                    'descripcion' => $datos['producto_descripcion'],
                    'activo' => $this->producto_activo,
                ]);
            }
        } catch (OperacionInvalidaException $e) {
            $this->errorNegocio = $e->getMessage();
            return;
        }
        $this->mostrandoFormularioProducto = false;
        $this->productoEditandoId = null;
        $this->cargarProductos();
        $this->dispatch('guardado', mensaje: 'Producto guardado correctamente.');
    }

};
?>

<div>
    @if ($errorNegocio)
        <div class="mb-4 rounded-md bg-fp-badge-danger-bg text-fp-badge-danger-fg px-4 py-2 text-sm">
            {{ $errorNegocio }}</div>
    @endif
    @if (!$mostrandoFormularioProducto)
        <div class="bg-white rounded-lg shadow-sm p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-sm font-semibold text-slate-700">Productos</h2>
                <button type="button" wire:click="abrirFormularioCrearProducto"
                    class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">+ Agregar
                    Producto</button>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 border-b">
                        <th class="py-2">Código / Modelo</th>
                        <th class="py-2">Nombre</th>
                        <th class="py-2">Marca</th>
                        <th class="py-2">Línea</th>
                        <th class="py-2">Categoría</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($productos as $producto)
                        <tr class="border-b last:border-0">
                            <td class="py-2">{{ $producto->modelo }}</td>
                            <td class="py-2">{{ $producto->nombre }}</td>
                            <td class="py-2">{{ $producto->marca?->nombre ?? '—' }}</td>
                            <td class="py-2">{{ $producto->linea?->nombre ?? '—' }}</td>
                            <td class="py-2">{{ $producto->categoria?->nombre ?? '—' }}</td>
                            <td class="py-2">
                                <span
                                    class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $producto->activo ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                    {{ $producto->activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td class="py-2 text-right">
                                <button type="button"
                                    wire:click="abrirFormularioEditarProducto({{ $producto->id }})"
                                    class="text-fp-primary text-xs font-medium">Editar</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-slate-500">No hay productos.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <form wire:submit="guardarProducto" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
            <h2 class="text-sm font-semibold text-slate-700">
                {{ $productoEditandoId ? 'Editar producto' : 'Nuevo producto' }}</h2>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Modelo / código</label>
                    <input type="text" wire:model="producto_modelo" class="w-full rounded-md border-slate-300">
                    @error('producto_modelo')
                        <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                    <input type="text" wire:model="producto_nombre" class="w-full rounded-md border-slate-300">
                    @error('producto_nombre')
                        <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span>
                    @enderror
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Descripción</label>
                <textarea wire:model="producto_descripcion" rows="2" class="w-full rounded-md border-slate-300"></textarea>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Marca</label>
                    <select wire:model="producto_marca_id" class="w-full rounded-md border-slate-300">
                        <option value="">—</option>
                        @foreach ($marcas as $m)
                            <option value="{{ $m->id }}">{{ $m->nombre }}</option>
                        @endforeach
                    </select>
                    @error('producto_marca_id')
                        <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Línea</label>
                    <select wire:model="producto_linea_id" class="w-full rounded-md border-slate-300">
                        <option value="">—</option>
                        @foreach ($lineas as $l)
                            <option value="{{ $l->id }}">{{ $l->nombre }}</option>
                        @endforeach
                    </select>
                    @error('producto_linea_id')
                        <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Categoría</label>
                    <select wire:model="producto_categoria_id" class="w-full rounded-md border-slate-300">
                        <option value="">—</option>
                        @foreach ($categorias as $c)
                            <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                        @endforeach
                    </select>
                    @error('producto_categoria_id')
                        <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span>
                    @enderror
                </div>
            </div>
            @if ($productoEditandoId)
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="producto_activo" class="rounded border-slate-300">
                    Producto activo
                </label>
            @endif
            <div class="flex gap-2">
                <button type="submit"
                    class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled">Guardar
                    Producto</button>
                <button type="button" wire:click="cancelarFormularioProducto"
                    class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
            </div>
        </form>
    @endif
</div>
