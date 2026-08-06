<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Marca;
use App\Models\Producto;
use App\Services\Catalogo\GestionarCampanaAction;
use App\Services\Catalogo\GestionarCategoriaProductoAction;
use App\Services\Catalogo\GestionarMarcaAction;
use App\Services\Catalogo\GestionarProductoAction;
use App\Support\Tenant;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::distribuidora')] class extends Component {
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

    // --- Campañas ---
    public $campanas = [];
    public ?int $campanaEditandoId = null;
    public bool $mostrandoFormularioCampana = false;
    public ?int $campana_marca_id = null;
    public string $campana_nombre = '';
    public ?string $campana_fecha_inicio = null;
    public ?string $campana_fecha_fin = null;

    // Traducción local de estado -> [texto, variante] para el componente
    // compartido <x-ui.insignia-estado>. El componente compartido YA NO
    // hace esta traducción automática (lo cambiaron mientras
    // trabajábamos), así que cada quien la resuelve por su cuenta ahora.
    private const CAMPANA_ESTADOS = [
        'borrador'        => ['Borrador', 'neutral'],
        'en_importacion'  => ['En importación', 'info'],
        'en_revision'     => ['En revisión', 'info'],
        'activa'          => ['Activa', 'success'],
        'finalizada'      => ['Finalizada', 'neutral'],
        'archivada'       => ['Archivada', 'neutral'],
    ];
    private const ORDEN_ESTADOS_CAMPANA = [
        'borrador', 'en_importacion', 'en_revision', 'activa', 'finalizada', 'archivada',
    ];
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
    public ?int $producto_categoria_id = null;
    public bool $producto_activo = true;

    public function mount(): void
    {
        $this->campanaEstadosNombres = self::CAMPANA_ESTADOS;
        $this->ordenEstadosCampana = self::ORDEN_ESTADOS_CAMPANA;

        $this->cargarMarcas();
        $this->cargarCategorias();
        $this->cargarCampanas();
        $this->cargarProductos();
    }

    private function cargarCampanas(): void
    {
        $this->campanas = Campana::with('marca')->latest()->get();
    }

    private function cargarProductos(): void
    {
        $this->productos = Producto::with(['marca', 'categoria'])->latest()->get();
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

    /**
     * Solo CREA campañas aquí (no edición de datos básicos por ahora —
     * el foco de esta pantalla es crear + avanzar estado). Mismas reglas
     * que GuardarCampanaRequest (Bloque 3b), incluida la validación de
     * marca_id acotada al tenant.
     */
    public function guardarCampana(): void
    {
        $this->errorNegocio = null;

        $datos = $this->validate([
            'campana_marca_id'     => [
                'required', 'integer',
                Rule::exists('marcas', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ],
            'campana_nombre'       => ['required', 'string', 'max:150'],
            'campana_fecha_inicio' => ['nullable', 'date'],
            'campana_fecha_fin'    => ['nullable', 'date', 'after_or_equal:campana_fecha_inicio'],
        ]);

        $payload = [
            'marca_id'     => $datos['campana_marca_id'],
            'nombre'       => $datos['campana_nombre'],
            'fecha_inicio' => $datos['campana_fecha_inicio'],
            'fecha_fin'    => $datos['campana_fecha_fin'],
        ];

        app(GestionarCampanaAction::class)->crear($payload);

        $this->mostrandoFormularioCampana = false;
        $this->cargarCampanas();

        $this->dispatch('guardado', mensaje: 'Campaña creada correctamente.');
    }

    /**
     * Avanza la campaña al SIGUIENTE estado de la secuencia (un solo
     * paso). La Action ya valida esto — aquí solo se atrapa el error de
     * negocio por si acaso (ej. dos personas cambiando el estado a la vez).
     */
    public function avanzarEstadoCampana(int $id): void
    {
        $this->errorNegocio = null;
        $campana = Campana::findOrFail($id);
        $indiceActual = array_search($campana->estado, self::ORDEN_ESTADOS_CAMPANA, true);
        $siguiente = self::ORDEN_ESTADOS_CAMPANA[$indiceActual + 1] ?? null;

        if (! $siguiente) {
            return; // ya está en el último estado, no hay botón visible de todas formas
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
        $this->producto_categoria_id = null;
        $this->producto_activo = true;
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
        $this->producto_activo = (bool) $producto->activo;
        // marca_id NO se carga para edición: el Form Request tampoco la
        // acepta ahí (no se puede cambiar de marca después de creado).
        $this->mostrandoFormularioProducto = true;
    }

    public function cancelarFormularioProducto(): void
    {
        $this->mostrandoFormularioProducto = false;
        $this->productoEditandoId = null;
    }

    /**
     * Mismas reglas que GuardarProductoRequest (Bloque 3b).
     */
    public function guardarProducto(): void
    {
        $esCreacion = ! $this->productoEditandoId;

        $reglas = [
            'producto_modelo'       => ['required', 'string', 'max:120'],
            'producto_nombre'       => ['required', 'string', 'max:200'],
            'producto_descripcion'  => ['nullable', 'string'],
            'producto_categoria_id' => [
                'required', 'integer',
                Rule::exists('categorias_producto', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ],
        ];

        if ($esCreacion) {
            $reglas['producto_marca_id'] = [
                'required', 'integer',
                Rule::exists('marcas', 'id')->where(fn ($q) => $q->where('distribuidora_id', Tenant::id())),
            ];
        }

        $datos = $this->validate($reglas);

        $accion = app(GestionarProductoAction::class);

        if ($esCreacion) {
            $accion->crear([
                'marca_id'     => $datos['producto_marca_id'],
                'categoria_id' => $datos['producto_categoria_id'],
                'modelo'       => $datos['producto_modelo'],
                'nombre'       => $datos['producto_nombre'],
                'descripcion'  => $datos['producto_descripcion'],
            ]);
        } else {
            $accion->actualizar(Producto::findOrFail($this->productoEditandoId), [
                'categoria_id' => $datos['producto_categoria_id'],
                'modelo'       => $datos['producto_modelo'],
                'nombre'       => $datos['producto_nombre'],
                'descripcion'  => $datos['producto_descripcion'],
                'activo'       => $this->producto_activo,
            ]);
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

    {{-- Pestaña: Productos --}}
    <div x-show="$wire.pestanaActiva === 'productos'">
        @if (! $mostrandoFormularioProducto)
            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-semibold text-slate-700">Productos</h2>
                    <button type="button" wire:click="abrirFormularioCrearProducto" class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">
                        + Agregar Producto
                    </button>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b">
                            <th class="py-2">Código / Modelo</th>
                            <th class="py-2">Nombre</th>
                            <th class="py-2">Marca</th>
                            <th class="py-2">Categoría</th>
                            <th class="py-2">Estado</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($productos as $producto)
                            <tr class="border-b last:border-0">
                                <td class="py-2">{{ $producto->modelo }}</td>
                                <td class="py-2">{{ $producto->nombre }}</td>
                                <td class="py-2">{{ $producto->marca->nombre ?? '—' }}</td>
                                <td class="py-2">{{ $producto->categoria->nombre ?? '—' }}</td>
                                <td class="py-2">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $producto->activo ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                        {{ $producto->activo ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <button type="button" wire:click="abrirFormularioEditarProducto({{ $producto->id }})" class="text-fp-primary text-xs font-medium">
                                        Editar
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="text-xs text-fp-text-muted mt-4">
                    Los precios, variantes e imágenes de cada producto se gestionan dentro de sus publicaciones por campaña (siguiente paso).
                </p>
            </div>
        @else
            <form wire:submit="guardarProducto" class="bg-white rounded-lg shadow-sm p-6 space-y-4 max-w-2xl">
                <h2 class="text-sm font-semibold text-slate-700">
                    {{ $productoEditandoId ? 'Editar producto' : 'Agregar Nuevo Producto' }}
                </h2>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nombre del Producto</label>
                    <input type="text" wire:model="producto_nombre" placeholder="Ej. Air Max Running Pro 2024" class="w-full rounded-md border-slate-300">
                    @error('producto_nombre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Código / Modelo</label>
                    <input type="text" wire:model="producto_modelo" placeholder="Ej. AM-RUN-2024-BLK" class="w-full rounded-md border-slate-300">
                    @error('producto_modelo') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Descripción</label>
                    <textarea wire:model="producto_descripcion" rows="2" class="w-full rounded-md border-slate-300"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    @if (! $productoEditandoId)
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
                    @endif
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
                    <div>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="producto_activo" class="rounded border-slate-300">
                            Producto activo
                        </label>
                    </div>
                @endif

                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled" wire:target="guardarProducto">
                        Guardar Producto
                    </button>
                    <button type="button" wire:click="cancelarFormularioProducto" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">
                        Cancelar
                    </button>
                </div>
            </form>
        @endif
    </div>

    {{-- Pestaña: Campañas --}}
    <div x-show="$wire.pestanaActiva === 'campanas'">
        @if ($errorNegocio)
            <div class="mb-4 rounded-md bg-fp-badge-danger-bg text-fp-badge-danger-fg px-4 py-2 text-sm">
                {{ $errorNegocio }}
            </div>
        @endif

        @if (! $mostrandoFormularioCampana)
            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-sm font-semibold text-slate-700">Campañas</h2>
                    <button type="button" wire:click="abrirFormularioCrearCampana" class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">
                        + Nueva campaña
                    </button>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b">
                            <th class="py-2">Nombre</th>
                            <th class="py-2">Marca</th>
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
                                    {{ $campana->fecha_inicio?->format('d/m/Y') ?? '—' }} – {{ $campana->fecha_fin?->format('d/m/Y') ?? '—' }}
                                </td>
                                <td class="py-2">
                                    <x-ui.insignia-estado :texto="$textoEstado" :variante="$varianteEstado" />
                                </td>
                                <td class="py-2 text-right">
                                    @if ($siguienteEstado)
                                        <button type="button" wire:click="avanzarEstadoCampana({{ $campana->id }})" class="text-fp-primary text-xs font-medium">
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
                    <label class="block text-sm font-medium text-slate-700 mb-1">Marca</label>
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
                    <input type="text" wire:model="campana_nombre" placeholder="Ej. Otoño Invierno 2026" class="w-full rounded-md border-slate-300">
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

                <p class="text-xs text-fp-text-muted">
                    La campaña siempre inicia en estado "Borrador" — el estado se avanza después, desde la lista.
                </p>

                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled" wire:target="guardarCampana">
                        Guardar
                    </button>
                    <button type="button" wire:click="cancelarFormularioCampana" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">
                        Cancelar
                    </button>
                </div>
            </form>
        @endif
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
