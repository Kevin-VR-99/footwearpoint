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

    public string $busqueda = '';

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

    public function updatedBusqueda(): void
    {
        $this->cargarProductos();
    }

    private function cargarProductos(): void
    {
        $query = Producto::with(['marca', 'linea', 'categoria']);

        $termino = trim($this->busqueda);
        if ($termino !== '') {
            $like = '%' . mb_strtolower($termino) . '%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(modelo) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(nombre) LIKE ?', [$like]);
            });
        }

        $this->productos = $query->latest()->get();
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
        <div class="mb-4 rounded-xl border border-fp-badge-danger-fg/15 bg-fp-badge-danger-bg px-4 py-3 text-sm text-fp-badge-danger-fg">
            {{ $errorNegocio }}
        </div>
    @endif

    @if (!$mostrandoFormularioProducto)
        <div class="rounded-2xl border border-slate-200/80 bg-white shadow-sm">
            <div class="flex flex-col gap-4 border-b border-slate-100 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold tracking-tight text-slate-900">Productos</h2>
                    <p class="mt-0.5 text-sm text-fp-text-muted">Catálogo de modelos de tu distribuidora.</p>
                </div>
                <button type="button" wire:click="abrirFormularioCrearProducto"
                    class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-fp-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-fp-primary/25 transition hover:bg-fp-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary focus-visible:ring-offset-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nuevo producto
                </button>
            </div>

            <div class="px-6 py-4">
                <label class="relative block">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" />
                        </svg>
                    </span>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="busqueda"
                        placeholder="Buscar por código o nombre…"
                        class="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-800 placeholder:text-slate-400 focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20"
                    >
                </label>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-y border-slate-100 bg-fp-page/80 text-left text-[11px] font-semibold uppercase tracking-wide text-fp-text-muted">
                            <th class="px-6 py-3">Código</th>
                            <th class="px-6 py-3">Nombre</th>
                            <th class="px-6 py-3">Marca</th>
                            <th class="px-6 py-3">Línea</th>
                            <th class="px-6 py-3">Categoría</th>
                            <th class="px-6 py-3">Estado</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($productos as $producto)
                            <tr class="transition-colors hover:bg-fp-page/70">
                                <td class="px-6 py-3.5 font-medium text-fp-primary">{{ $producto->modelo }}</td>
                                <td class="px-6 py-3.5 text-slate-800">{{ $producto->nombre }}</td>
                                <td class="px-6 py-3.5 text-slate-600">{{ $producto->marca?->nombre ?? '—' }}</td>
                                <td class="px-6 py-3.5 text-slate-600">{{ $producto->linea?->nombre ?? '—' }}</td>
                                <td class="px-6 py-3.5 text-slate-600">{{ $producto->categoria?->nombre ?? '—' }}</td>
                                <td class="px-6 py-3.5">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $producto->activo ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                        {{ $producto->activo ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    <button type="button"
                                        wire:click="abrirFormularioEditarProducto({{ $producto->id }})"
                                        class="text-sm font-medium text-fp-primary hover:underline">Editar</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-14 text-center">
                                    @if (trim($busqueda) !== '')
                                        <p class="text-sm font-medium text-slate-700">Sin coincidencias</p>
                                        <p class="mt-1 text-sm text-fp-text-muted">No encontramos productos para «{{ $busqueda }}». Prueba con otro código o nombre.</p>
                                    @else
                                        <p class="text-sm font-medium text-fp-sidebar">Aún no hay productos</p>
                                        <p class="mt-1 text-sm text-fp-text-muted">Crea el primero para empezar tu catálogo.</p>
                                        <button type="button" wire:click="abrirFormularioCrearProducto"
                                            class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-fp-primary px-3 py-2 text-xs font-semibold text-white hover:bg-fp-accent">
                                            Nuevo producto
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <form wire:submit="guardarProducto" class="mx-auto max-w-2xl space-y-6 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8">
            <div>
                <h2 class="text-base font-semibold tracking-tight text-slate-900">
                    {{ $productoEditandoId ? 'Editar producto' : 'Nuevo producto' }}
                </h2>
                <p class="mt-0.5 text-sm text-fp-text-muted">
                    {{ $productoEditandoId ? 'Actualiza los datos del modelo.' : 'Completa los datos del nuevo modelo.' }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Código</label>
                    <input type="text" wire:model="producto_modelo"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20">
                    @error('producto_modelo')
                        <span class="mt-1 block text-xs text-fp-badge-danger-fg">{{ $message }}</span>
                    @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Nombre</label>
                    <input type="text" wire:model="producto_nombre"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20">
                    @error('producto_nombre')
                        <span class="mt-1 block text-xs text-fp-badge-danger-fg">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-700">Descripción</label>
                <textarea wire:model="producto_descripcion" rows="3"
                    class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20"></textarea>
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Marca</label>
                    <select wire:model="producto_marca_id"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20">
                        <option value="">—</option>
                        @foreach ($marcas as $m)
                            <option value="{{ $m->id }}">{{ $m->nombre }}</option>
                        @endforeach
                    </select>
                    @error('producto_marca_id')
                        <span class="mt-1 block text-xs text-fp-badge-danger-fg">{{ $message }}</span>
                    @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Línea</label>
                    <select wire:model="producto_linea_id"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20">
                        <option value="">—</option>
                        @foreach ($lineas as $l)
                            <option value="{{ $l->id }}">{{ $l->nombre }}</option>
                        @endforeach
                    </select>
                    @error('producto_linea_id')
                        <span class="mt-1 block text-xs text-fp-badge-danger-fg">{{ $message }}</span>
                    @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">Categoría</label>
                    <select wire:model="producto_categoria_id"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:border-fp-primary focus:outline-none focus:ring-2 focus:ring-fp-primary/20">
                        <option value="">—</option>
                        @foreach ($categorias as $c)
                            <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                        @endforeach
                    </select>
                    @error('producto_categoria_id')
                        <span class="mt-1 block text-xs text-fp-badge-danger-fg">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            @if ($productoEditandoId)
                <label class="flex items-center gap-2.5 text-sm text-slate-700">
                    <input type="checkbox" wire:model="producto_activo"
                        class="rounded border-slate-300 text-fp-primary focus:ring-fp-primary/30">
                    Producto activo
                </label>
            @endif

            <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-5">
                <button type="submit"
                    class="inline-flex items-center justify-center rounded-xl bg-fp-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-fp-primary/25 transition hover:bg-fp-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary focus-visible:ring-offset-2 disabled:opacity-60"
                    wire:loading.attr="disabled">
                    Guardar producto
                </button>
                <button type="button" wire:click="cancelarFormularioProducto"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">
                    Cancelar
                </button>
            </div>
        </form>
    @endif
</div>
