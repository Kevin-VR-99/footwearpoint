<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\CategoriaProducto;
use App\Models\Marca;
use App\Services\Catalogo\GestionarCategoriaProductoAction;
use App\Services\Catalogo\GestionarMarcaAction;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.panel')] class extends Component {
    use WithFileUploads;

    public string $pestanaActiva = 'productos'; // se deja en 'productos' ya
    // desde ahora, aunque esa pestaña se construya en el siguiente paso —
    // así el orden visual de las pestañas queda fijo desde el principio.

    // --- Mensaje de error de negocio (ej. límite de marcas) ---
    // OperacionInvalidaException está pensada para la API (responde JSON
    // crudo), no para Livewire — por eso se atrapa aquí y se muestra como
    // un aviso normal en pantalla, en vez de dejar que rompa la respuesta.
    public ?string $errorNegocio = null;

    // --- Marcas ---
    public $marcas = [];
    public ?int $marcaEditandoId = null;
    public bool $mostrandoFormularioMarca = false;
    public string $marca_nombre = '';
    public ?string $marca_descripcion = null;
    public bool $marca_activa = true;
    public $marca_logotipo = null;
    public ?string $marca_logotipo_url_actual = null;

    // --- Categorías ---
    public $categorias = [];
    public ?int $categoriaEditandoId = null;
    public bool $mostrandoFormularioCategoria = false;
    public string $categoria_nombre = '';
    public ?string $categoria_descripcion = null;
    public bool $categoria_activa = true;

    public function mount(): void
    {
        $this->cargarMarcas();
        $this->cargarCategorias();
    }

    private function cargarMarcas(): void
    {
        $this->marcas = Marca::all();
    }

    private function cargarCategorias(): void
    {
        $this->categorias = CategoriaProducto::all();
    }

    // ============ MARCAS ============

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

    /**
     * Mismas reglas que GuardarMarcaRequest (Bloque 3a). El límite de
     * líneas del plan (OperacionInvalidaException) se atrapa aquí — ver
     * nota al inicio del archivo.
     */
    public function guardarMarca(): void
    {
        $this->errorNegocio = null;

        $datos = $this->validate([
            'marca_nombre'      => ['required', 'string', 'max:120'],
            'marca_descripcion' => ['nullable', 'string'],
            'marca_logotipo'    => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $payload = [
            'nombre'      => $datos['marca_nombre'],
            'descripcion' => $datos['marca_descripcion'],
            'activa'      => $this->marca_activa,
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

    // ============ CATEGORÍAS ============

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
            'categoria_nombre'      => ['required', 'string', 'max:120'],
            'categoria_descripcion' => ['nullable', 'string', 'max:300'],
        ]);

        $payload = [
            'nombre'      => $datos['categoria_nombre'],
            'descripcion' => $datos['categoria_descripcion'],
            'activa'      => $this->categoria_activa,
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
    <h1 class="text-xl font-semibold text-slate-800 mb-4">Catálogo</h1>

    {{-- Aviso de guardado exitoso --}}
    <div
        x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible"
        x-transition
        class="mb-4 rounded-md bg-fp-badge-success-bg text-fp-badge-success-fg px-4 py-2 text-sm"
        style="display: none;"
    >
        <span x-text="mensaje"></span>
    </div>

    {{-- Pestañas --}}
    <div class="border-b border-slate-200 mb-6 flex gap-6">
        <button type="button" wire:click="$set('pestanaActiva', 'productos')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'productos' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Productos
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'campanas')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'campanas' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Campañas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'marcas')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'marcas' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Marcas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'categorias')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'categorias' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Categorías
        </button>
    </div>

    {{-- Pestañas Productos y Campañas: siguiente paso, todavía no construidas --}}
    <div x-show="$wire.pestanaActiva === 'productos'" class="text-sm text-fp-text-muted">
        (Siguiente paso — todavía no construida.)
    </div>
    <div x-show="$wire.pestanaActiva === 'campanas'" class="text-sm text-fp-text-muted">
        (Siguiente paso — todavía no construida.)
    </div>

    {{-- Pestaña: Marcas --}}
    <div x-show="$wire.pestanaActiva === 'marcas'">
        @if ($errorNegocio)
            <div class="mb-4 rounded-md bg-fp-badge-danger-bg text-fp-badge-danger-fg px-4 py-2 text-sm">
                {{ $errorNegocio }}
            </div>
        @endif

        @if (! $mostrandoFormularioMarca)
            <div class="bg-white rounded-lg shadow-sm p-6 max-w-3xl">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-semibold text-slate-700">Marcas</h2>
                    <button type="button" wire:click="abrirFormularioCrearMarca" class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">
                        + Nueva marca
                    </button>
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
                                <td class="py-2">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $marca->activa ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                        {{ $marca->activa ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <button type="button" wire:click="abrirFormularioEditarMarca({{ $marca->id }})" class="text-fp-primary text-xs font-medium">
                                        Editar
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <form wire:submit="guardarMarca" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
                <h2 class="text-sm font-semibold text-slate-700">
                    {{ $marcaEditandoId ? 'Editar marca' : 'Nueva marca' }}
                </h2>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                    <input type="text" wire:model="marca_nombre" class="w-full rounded-md border-slate-300">
                    @error('marca_nombre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
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
                    <p class="text-xs text-fp-text-muted mt-1">PNG o JPG, hasta 2MB.</p>
                    @error('marca_logotipo') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>

                @if ($marcaEditandoId)
                    <div>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="marca_activa" class="rounded border-slate-300">
                            Marca activa
                        </label>
                        <p class="text-xs text-fp-text-muted mt-1">
                            Reactivar una marca vuelve a consumir un cupo del límite de líneas de tu plan.
                        </p>
                    </div>
                @endif

                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled" wire:target="guardarMarca">
                        Guardar
                    </button>
                    <button type="button" wire:click="cancelarFormularioMarca" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">
                        Cancelar
                    </button>
                </div>
            </form>
        @endif
    </div>

    {{-- Pestaña: Categorías --}}
    <div x-show="$wire.pestanaActiva === 'categorias'">
        @if (! $mostrandoFormularioCategoria)
            <div class="bg-white rounded-lg shadow-sm p-6 max-w-3xl">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-semibold text-slate-700">Categorías</h2>
                    <button type="button" wire:click="abrirFormularioCrearCategoria" class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">
                        + Nueva categoría
                    </button>
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
                                <td class="py-2">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $categoria->activa ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                        {{ $categoria->activa ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <button type="button" wire:click="abrirFormularioEditarCategoria({{ $categoria->id }})" class="text-fp-primary text-xs font-medium">
                                        Editar
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <form wire:submit="guardarCategoria" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
                <h2 class="text-sm font-semibold text-slate-700">
                    {{ $categoriaEditandoId ? 'Editar categoría' : 'Nueva categoría' }}
                </h2>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                    <input type="text" wire:model="categoria_nombre" class="w-full rounded-md border-slate-300">
                    @error('categoria_nombre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Descripción</label>
                    <textarea wire:model="categoria_descripcion" rows="2" class="w-full rounded-md border-slate-300"></textarea>
                </div>

                @if ($categoriaEditandoId)
                    <div>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="categoria_activa" class="rounded border-slate-300">
                            Categoría activa
                        </label>
                    </div>
                @endif

                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled" wire:target="guardarCategoria">
                        Guardar
                    </button>
                    <button type="button" wire:click="cancelarFormularioCategoria" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">
                        Cancelar
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
