<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Services\Catalogo\GestionarCampanaAction;
use App\Services\Catalogo\GestionarCategoriaProductoAction;
use App\Services\Catalogo\GestionarLineaAction;
use App\Services\Catalogo\GestionarMarcaAction;
use App\Services\Catalogo\GestionarProductoAction;
use App\Support\Tenant;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.panel')] class extends Component {
    use WithFileUploads;

    public string $pestanaActiva = 'productos';
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

    // --- Líneas ---
    public $lineas = [];
    public ?int $lineaEditandoId = null;
    public bool $mostrandoFormularioLinea = false;
    public ?int $linea_campana_id = null;
    public string $linea_nombre = '';
    public ?string $linea_descripcion = null;
    public bool $linea_activa = true;
    public array $linea_marca_ids = [];

    // --- Categorías ---
    public $categorias = [];
    public ?int $categoriaEditandoId = null;
    public bool $mostrandoFormularioCategoria = false;
    public string $categoria_nombre = '';
    public ?string $categoria_descripcion = null;
    public bool $categoria_activa = true;

    // --- Campañas ---
    public $campanas = [];
    public ?int $campanaEditandoId = null;
    public bool $mostrandoFormularioCampana = false;
    public ?int $campana_marca_id = null;
    public string $campana_nombre = '';
    public ?string $campana_fecha_inicio = null;
    public ?string $campana_fecha_fin = null;

    private const CAMPANA_ESTADOS = [
        'borrador' => ['Borrador', 'neutral'],
        'en_importacion' => ['En importación', 'info'],
        'en_revision' => ['En revisión', 'info'],
        'activa' => ['Activa', 'success'],
        'finalizada' => ['Finalizada', 'neutral'],
        'archivada' => ['Archivada', 'neutral'],
    ];
    private const ORDEN_ESTADOS_CAMPANA = ['borrador', 'en_importacion', 'en_revision', 'activa', 'finalizada', 'archivada'];
    public array $campanaEstadosNombres = [];
    public array $ordenEstadosCampana = [];

    // --- Productos ---
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

    public function mount(): void
    {
        $this->campanaEstadosNombres = self::CAMPANA_ESTADOS;
        $this->ordenEstadosCampana = self::ORDEN_ESTADOS_CAMPANA;

        $this->cargarMarcas();
        $this->cargarLineas();
        $this->cargarCategorias();
        $this->cargarCampanas();
        $this->cargarProductos();
    }

    private function cargarLineas(): void
    {
        $this->lineas = Linea::with(['campana', 'marcas'])->latest()->get();
    }

    private function cargarCampanas(): void
    {
        $this->campanas = Campana::with('marca')->latest()->get();
    }

    private function cargarProductos(): void
    {
        $this->productos = Producto::with(['marca', 'linea', 'categoria'])->latest()->get();
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

    // ============ LÍNEAS ============

    public function abrirFormularioCrearLinea(): void
    {
        $this->errorNegocio = null;
        $this->lineaEditandoId = null;
        $this->linea_campana_id = null;
        $this->linea_nombre = '';
        $this->linea_descripcion = null;
        $this->linea_activa = true;
        $this->linea_marca_ids = [];
        $this->mostrandoFormularioLinea = true;
    }

    public function abrirFormularioEditarLinea(int $id): void
    {
        $this->errorNegocio = null;
        $linea = Linea::with('marcas')->findOrFail($id);
        $this->lineaEditandoId = $linea->id;
        $this->linea_campana_id = $linea->campana_id;
        $this->linea_nombre = $linea->nombre;
        $this->linea_descripcion = $linea->descripcion;
        $this->linea_activa = (bool) $linea->activa;
        $this->linea_marca_ids = $linea->marcas->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->mostrandoFormularioLinea = true;
    }

    public function cancelarFormularioLinea(): void
    {
        $this->mostrandoFormularioLinea = false;
        $this->lineaEditandoId = null;
        $this->errorNegocio = null;
    }

    public function guardarLinea(): void
    {
        $this->errorNegocio = null;
        $esCreacion = ! $this->lineaEditandoId;

        $reglas = [
            'linea_nombre' => ['required', 'string', 'max:150'],
            'linea_descripcion' => ['nullable', 'string'],
            'linea_marca_ids' => ['sometimes', 'array'],
            'linea_marca_ids.*' => [
                'integer',
                Rule::exists('marcas', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ],
        ];

        if ($esCreacion) {
            $reglas['linea_campana_id'] = [
                'required', 'integer',
                Rule::exists('campanas', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ];
        }

        $datos = $this->validate($reglas);
        $accion = app(GestionarLineaAction::class);
        $marcaIds = array_map('intval', $this->linea_marca_ids ?? []);

        try {
            if ($esCreacion) {
                $accion->crear([
                    'campana_id' => $datos['linea_campana_id'],
                    'nombre' => $datos['linea_nombre'],
                    'descripcion' => $datos['linea_descripcion'] ?? null,
                ], $marcaIds);
            } else {
                $accion->actualizar(Linea::findOrFail($this->lineaEditandoId), [
                    'nombre' => $datos['linea_nombre'],
                    'descripcion' => $datos['linea_descripcion'] ?? null,
                    'activa' => $this->linea_activa,
                ], $marcaIds);
            }
        } catch (OperacionInvalidaException $e) {
            $this->errorNegocio = $e->getMessage();
            return;
        }

        $this->mostrandoFormularioLinea = false;
        $this->lineaEditandoId = null;
        $this->cargarLineas();
        $this->dispatch('guardado', mensaje: 'Línea guardada correctamente.');
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

    // ============ CAMPAÑAS ============

    public function abrirFormularioCrearCampana(): void
    {
        $this->campanaEditandoId = null;
        $this->campana_marca_id = null;
        $this->campana_nombre = '';
        $this->campana_fecha_inicio = null;
        $this->campana_fecha_fin = null;
        $this->errorNegocio = null;
        $this->mostrandoFormularioCampana = true;
    }

    public function cancelarFormularioCampana(): void
    {
        $this->mostrandoFormularioCampana = false;
        $this->campanaEditandoId = null;
        $this->errorNegocio = null;
    }

    public function guardarCampana(): void
    {
        $this->errorNegocio = null;

        $datos = $this->validate([
            'campana_marca_id' => [
                'required', 'integer',
                Rule::exists('marcas', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ],
            'campana_nombre' => ['required', 'string', 'max:150'],
            'campana_fecha_inicio' => ['nullable', 'date'],
            'campana_fecha_fin' => ['nullable', 'date', 'after_or_equal:campana_fecha_inicio'],
        ]);

        app(GestionarCampanaAction::class)->crear([
            'marca_id' => $datos['campana_marca_id'],
            'nombre' => $datos['campana_nombre'],
            'fecha_inicio' => $datos['campana_fecha_inicio'],
            'fecha_fin' => $datos['campana_fecha_fin'],
        ]);

        $this->mostrandoFormularioCampana = false;
        $this->cargarCampanas();
        $this->dispatch('guardado', mensaje: 'Campaña creada correctamente.');
    }

    public function avanzarEstadoCampana(int $id): void
    {
        $this->errorNegocio = null;
        $campana = Campana::findOrFail($id);
        $indiceActual = array_search($campana->estado, self::ORDEN_ESTADOS_CAMPANA, true);
        $siguiente = self::ORDEN_ESTADOS_CAMPANA[$indiceActual + 1] ?? null;

        if (! $siguiente) {
            return;
        }

        try {
            app(GestionarCampanaAction::class)->actualizar($campana, ['estado' => $siguiente]);
        } catch (OperacionInvalidaException $e) {
            $this->errorNegocio = $e->getMessage();
            return;
        }

        $this->cargarCampanas();
        $this->dispatch('guardado', mensaje: "Campaña avanzada a '{$siguiente}'.");
    }

    // ============ PRODUCTOS ============

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
        $esCreacion = ! $this->productoEditandoId;

        $reglas = [
            'producto_modelo' => ['required', 'string', 'max:120'],
            'producto_nombre' => ['required', 'string', 'max:200'],
            'producto_descripcion' => ['nullable', 'string'],
            'producto_categoria_id' => [
                'required', 'integer',
                Rule::exists('categorias_producto', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ],
            'producto_linea_id' => [
                'required', 'integer',
                Rule::exists('lineas', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ],
            'producto_marca_id' => [
                'required', 'integer',
                Rule::exists('marcas', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ],
        ];

        $datos = $this->validate($reglas);
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
    <h1 class="text-xl font-semibold text-slate-800 mb-4">Catálogo</h1>

    <div x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible" x-transition
        class="mb-4 rounded-md bg-fp-badge-success-bg text-fp-badge-success-fg px-4 py-2 text-sm" style="display: none;">
        <span x-text="mensaje"></span>
    </div>

    {{-- Pestañas --}}
    <div class="border-b border-slate-200 mb-6 flex gap-6 flex-wrap">
        <button type="button" wire:click="$set('pestanaActiva', 'productos')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'productos' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Productos
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'lineas')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'lineas' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Líneas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'marcas')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'marcas' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Marcas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'campanas')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'campanas' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Campañas
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'categorias')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'categorias' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Categorías
        </button>
    </div>

    {{-- Productos --}}
    <div x-show="$wire.pestanaActiva === 'productos'">
        @if ($errorNegocio && $pestanaActiva === 'productos')
            <div class="mb-4 rounded-md bg-fp-badge-danger-bg text-fp-badge-danger-fg px-4 py-2 text-sm">{{ $errorNegocio }}</div>
        @endif

        @if (! $mostrandoFormularioProducto)
            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-semibold text-slate-700">Productos</h2>
                    <button type="button" wire:click="abrirFormularioCrearProducto"
                        class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">
                        + Agregar Producto
                    </button>
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
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $producto->activo ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                        {{ $producto->activo ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="py-2 text-right space-x-3">
                                    <a href="{{ route('catalogo.producto.detalle', $producto) }}" class="text-fp-primary text-xs font-medium">Variantes</a>
                                    <button type="button" wire:click="abrirFormularioEditarProducto({{ $producto->id }})" class="text-fp-primary text-xs font-medium">Editar</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-8 text-center text-slate-500">No hay productos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <form wire:submit="guardarProducto" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
                <h2 class="text-sm font-semibold text-slate-700">
                    {{ $productoEditandoId ? 'Editar producto' : 'Agregar Nuevo Producto' }}
                </h2>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nombre del Producto</label>
                    <input type="text" wire:model="producto_nombre" class="w-full rounded-md border-slate-300" placeholder="Ej. Air Max Running Pro 2024">
                    @error('producto_nombre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Código / Modelo</label>
                    <input type="text" wire:model="producto_modelo" class="w-full rounded-md border-slate-300" placeholder="Ej. AM-RUN-2024-BLK">
                    @error('producto_modelo') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Descripción</label>
                    <textarea wire:model="producto_descripcion" rows="2" class="w-full rounded-md border-slate-300"></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Línea</label>
                        <select wire:model="producto_linea_id" class="w-full rounded-md border-slate-300">
                            <option value="">Seleccionar línea</option>
                            @foreach ($lineas as $linea)
                                @if ($linea->activa)
                                    <option value="{{ $linea->id }}">{{ $linea->nombre }}</option>
                                @endif
                            @endforeach
                        </select>
                        @error('producto_linea_id') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Marca</label>
                        <select wire:model="producto_marca_id" class="w-full rounded-md border-slate-300">
                            <option value="">Seleccionar marca</option>
                            @foreach ($marcas as $marca)
                                @if ($marca->activa)
                                    <option value="{{ $marca->id }}">{{ $marca->nombre }}</option>
                                @endif
                            @endforeach
                        </select>
                        @error('producto_marca_id') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Categoría</label>
                        <select wire:model="producto_categoria_id" class="w-full rounded-md border-slate-300">
                            <option value="">Seleccionar categoría</option>
                            @foreach ($categorias as $categoria)
                                @if ($categoria->activa)
                                    <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                                @endif
                            @endforeach
                        </select>
                        @error('producto_categoria_id') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                </div>

                @if ($productoEditandoId)
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="producto_activo" class="rounded border-slate-300">
                        Producto activo
                    </label>
                @endif

                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium">Guardar Producto</button>
                    <button type="button" wire:click="cancelarFormularioProducto" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
                </div>
            </form>
        @endif
    </div>

    {{-- Líneas --}}
    <div x-show="$wire.pestanaActiva === 'lineas'">
        @if ($errorNegocio && $pestanaActiva === 'lineas')
            <div class="mb-4 rounded-md bg-fp-badge-danger-bg text-fp-badge-danger-fg px-4 py-2 text-sm">{{ $errorNegocio }}</div>
        @endif

        @if (! $mostrandoFormularioLinea)
            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-semibold text-slate-700">Líneas comerciales</h2>
                    <button type="button" wire:click="abrirFormularioCrearLinea"
                        class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">
                        + Nueva línea
                    </button>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b">
                            <th class="py-2">Nombre</th>
                            <th class="py-2">Campaña</th>
                            <th class="py-2">Marcas</th>
                            <th class="py-2">Estado</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lineas as $linea)
                            <tr class="border-b last:border-0">
                                <td class="py-2 font-medium">{{ $linea->nombre }}</td>
                                <td class="py-2">{{ $linea->campana?->nombre ?? '—' }}</td>
                                <td class="py-2">{{ $linea->marcas->pluck('nombre')->join(', ') ?: '—' }}</td>
                                <td class="py-2">{{ $linea->activa ? 'Activa' : 'Inactiva' }}</td>
                                <td class="py-2 text-right">
                                    <button type="button" wire:click="abrirFormularioEditarLinea({{ $linea->id }})"
                                        class="text-fp-primary text-xs font-medium">Editar</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-8 text-center text-slate-500">No hay líneas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <form wire:submit="guardarLinea" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
                <h2 class="text-sm font-semibold text-slate-700">
                    {{ $lineaEditandoId ? 'Editar línea' : 'Nueva línea' }}
                </h2>

                @if (! $lineaEditandoId)
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Campaña</label>
                        <select wire:model="linea_campana_id" class="w-full rounded-md border-slate-300">
                            <option value="">Seleccionar campaña</option>
                            @foreach ($campanas as $campana)
                                <option value="{{ $campana->id }}">{{ $campana->nombre }}</option>
                            @endforeach
                        </select>
                        @error('linea_campana_id') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                    <input type="text" wire:model="linea_nombre" class="w-full rounded-md border-slate-300">
                    @error('linea_nombre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Descripción</label>
                    <textarea wire:model="linea_descripcion" rows="2" class="w-full rounded-md border-slate-300"></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Marcas asociadas</label>
                    <div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto border rounded-md p-3">
                        @foreach ($marcas as $marca)
                            @if ($marca->activa)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model="linea_marca_ids" value="{{ $marca->id }}" class="rounded border-slate-300">
                                    {{ $marca->nombre }}
                                </label>
                            @endif
                        @endforeach
                    </div>
                </div>

                @if ($lineaEditandoId)
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="linea_activa" class="rounded border-slate-300">
                        Línea activa (cuenta para el cupo del plan)
                    </label>
                @endif

                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium">Guardar línea</button>
                    <button type="button" wire:click="cancelarFormularioLinea" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
                </div>
            </form>
        @endif
    </div>

    {{-- Marcas --}}
    <div x-show="$wire.pestanaActiva === 'marcas'">
        @if ($errorNegocio && $pestanaActiva === 'marcas')
            <div class="mb-4 rounded-md bg-fp-badge-danger-bg text-fp-badge-danger-fg px-4 py-2 text-sm">{{ $errorNegocio }}</div>
        @endif

        @if (! $mostrandoFormularioMarca)
            <div class="bg-white rounded-lg shadow-sm p-6 max-w-3xl">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-semibold text-slate-700">Marcas</h2>
                    <button type="button" wire:click="abrirFormularioCrearMarca"
                        class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">+ Nueva marca</button>
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
                                    <button type="button" wire:click="abrirFormularioEditarMarca({{ $marca->id }})"
                                        class="text-fp-primary text-xs font-medium">Editar</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <form wire:submit="guardarMarca" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
                <h2 class="text-sm font-semibold text-slate-700">{{ $marcaEditandoId ? 'Editar marca' : 'Nueva marca' }}</h2>
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
                    @error('marca_logotipo') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>
                @if ($marcaEditandoId)
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="marca_activa" class="rounded border-slate-300">
                        Marca activa
                    </label>
                @endif
                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium">Guardar</button>
                    <button type="button" wire:click="cancelarFormularioMarca" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
                </div>
            </form>
        @endif
    </div>

    {{-- Campañas --}}
    <div x-show="$wire.pestanaActiva === 'campanas'">
        @if ($errorNegocio && $pestanaActiva === 'campanas')
            <div class="mb-4 rounded-md bg-fp-badge-danger-bg text-fp-badge-danger-fg px-4 py-2 text-sm">{{ $errorNegocio }}</div>
        @endif

        @if (! $mostrandoFormularioCampana)
            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-semibold text-slate-700">Campañas</h2>
                    <button type="button" wire:click="abrirFormularioCrearCampana"
                        class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">+ Nueva campaña</button>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b">
                            <th class="py-2">Nombre</th>
                            <th class="py-2">Marca (legado)</th>
                            <th class="py-2">Vigencia</th>
                            <th class="py-2">Estado</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campanas as $campana)
                            @php
                                [$textoEstado, $varianteEstado] = $campanaEstadosNombres[$campana->estado] ?? [$campana->estado, 'neutral'];
                                $indiceActual = array_search($campana->estado, $ordenEstadosCampana, true);
                                $siguienteEstado = $ordenEstadosCampana[$indiceActual + 1] ?? null;
                            @endphp
                            <tr class="border-b last:border-0">
                                <td class="py-2">{{ $campana->nombre }}</td>
                                <td class="py-2">{{ $campana->marca->nombre ?? '—' }}</td>
                                <td class="py-2">
                                    {{ $campana->fecha_inicio?->format('d/m/Y') ?? '—' }} –
                                    {{ $campana->fecha_fin?->format('d/m/Y') ?? '—' }}
                                </td>
                                <td class="py-2">
                                    <x-ui.insignia-estado :texto="$textoEstado" :variante="$varianteEstado" />
                                </td>
                                <td class="py-2 text-right">
                                    @if ($siguienteEstado)
                                        <button type="button" wire:click="avanzarEstadoCampana({{ $campana->id }})"
                                            class="text-fp-primary text-xs font-medium">
                                            Avanzar a "{{ $campanaEstadosNombres[$siguienteEstado][0] }}"
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <form wire:submit="guardarCampana" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
                <h2 class="text-sm font-semibold text-slate-700">Nueva campaña</h2>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Marca (referencia legada)</label>
                    <select wire:model="campana_marca_id" class="w-full rounded-md border-slate-300">
                        <option value="">Seleccionar marca</option>
                        @foreach ($marcas as $marca)
                            @if ($marca->activa)
                                <option value="{{ $marca->id }}">{{ $marca->nombre }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('campana_marca_id') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nombre de la campaña</label>
                    <input type="text" wire:model="campana_nombre" class="w-full rounded-md border-slate-300">
                    @error('campana_nombre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Fecha de inicio</label>
                        <input type="date" wire:model="campana_fecha_inicio" class="w-full rounded-md border-slate-300">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Fecha de fin</label>
                        <input type="date" wire:model="campana_fecha_fin" class="w-full rounded-md border-slate-300">
                        @error('campana_fecha_fin') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium">Guardar</button>
                    <button type="button" wire:click="cancelarFormularioCampana" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
                </div>
            </form>
        @endif
    </div>

    {{-- Categorías --}}
    <div x-show="$wire.pestanaActiva === 'categorias'">
        @if (! $mostrandoFormularioCategoria)
            <div class="bg-white rounded-lg shadow-sm p-6 max-w-3xl">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-semibold text-slate-700">Categorías</h2>
                    <button type="button" wire:click="abrirFormularioCrearCategoria"
                        class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">+ Nueva categoría</button>
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
                                    <button type="button" wire:click="abrirFormularioEditarCategoria({{ $categoria->id }})"
                                        class="text-fp-primary text-xs font-medium">Editar</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <form wire:submit="guardarCategoria" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
                <h2 class="text-sm font-semibold text-slate-700">{{ $categoriaEditandoId ? 'Editar categoría' : 'Nueva categoría' }}</h2>
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
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="categoria_activa" class="rounded border-slate-300">
                        Categoría activa
                    </label>
                @endif
                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium">Guardar</button>
                    <button type="button" wire:click="cancelarFormularioCategoria" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">Cancelar</button>
                </div>
            </form>
        @endif
    </div>
</div>