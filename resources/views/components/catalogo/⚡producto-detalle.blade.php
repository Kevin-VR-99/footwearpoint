<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Campana;
use App\Models\Color;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\ProductoImagen;
use App\Models\Talla;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Variante;
use App\Services\Catalogo\GestionarDisponibilidadVarianteCampanaAction;
use App\Services\Catalogo\GestionarImagenProductoCampanaAction;
use App\Services\Catalogo\GestionarProductoCampanaAction;
use App\Services\Catalogo\GestionarVarianteAction;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.panel')] class extends Component {
    use WithFileUploads;

    public int $productoId;
    public $producto;
    public $variantes = [];
    public $publicaciones = [];
    public $tallas = [];
    public $colores = [];

    // Selección múltiple para crear variantes en lote (matriz talla x color,
    // igual que los checkboxes del mockup "Agregar Nuevo Producto" — aquí
    // vive aparte porque una variante pertenece al producto, no a una sola
    // publicación de campaña).
    public array $tallasSeleccionadas = [];
    public array $coloresSeleccionados = [];

    public ?string $errorNegocio = null;

    // --- Nueva publicación (producto_campana) ---
    public $campanasDeLaMarca = [];
    public bool $mostrandoFormularioPublicacion = false;
    public ?int $publicacion_campana_id = null;
    public string $publicacion_codigo_catalogo = '';
    public float $publicacion_precio_mayorista = 0;
    public float $publicacion_precio_minorista_sugerido = 0;

    // --- Gestión de una publicación existente (precios, imágenes, disponibilidad) ---
    public ?int $publicacionGestionandoId = null;
    public float $gestion_precio_mayorista = 0;
    public float $gestion_precio_minorista_sugerido = 0;
    public bool $gestion_publicado = false;
    public $imagenesPublicacion = [];
    public $nuevaImagen = null;
    public array $disponibilidadPorVariante = []; // variante_id => estado|null

    public function mount(Producto $producto): void
    {
        $this->productoId = $producto->id;
        $this->cargarProducto();
        $this->cargarVariantes();
        $this->cargarPublicaciones();

        $this->tallas = Talla::orderBy('sistema')->orderByRaw('CAST(valor AS DECIMAL(5,2))')->get();
        $this->colores = Color::orderBy('nombre')->get();

        // Solo campañas de la MISMA marca del producto — coincide con la
        // validación cruzada que ya existe en el backend (Bloque 3c):
        // producto y campaña deben ser de la misma marca.
        $this->campanasDeLaMarca = Campana::where('marca_id', $this->producto->marca_id)->get();
    }

    private function cargarProducto(): void
    {
        $this->producto = Producto::with(['marca', 'categoria'])->findOrFail($this->productoId);
    }

    private function cargarVariantes(): void
    {
        $this->variantes = Variante::with(['talla', 'color'])
            ->where('producto_id', $this->productoId)
            ->get();
    }

    private function cargarPublicaciones(): void
    {
        $this->publicaciones = ProductoCampana::with('campana')
            ->where('producto_id', $this->productoId)
            ->get();
    }

    /**
     * Crea una variante por cada combinación talla x color marcada que
     * TODAVÍA no exista para este producto — las que ya existen se
     * saltan en silencio (no es un error, solo significa que ya estaba
     * creada de antes).
     */
    public function crearVariantesSeleccionadas(): void
    {
        if ($this->tallasSeleccionadas === [] || $this->coloresSeleccionados === []) {
            $this->addError('seleccion', 'Selecciona al menos una talla y un color.');
            return;
        }

        $accion = app(GestionarVarianteAction::class);
        $creadas = 0;

        foreach ($this->tallasSeleccionadas as $tallaId) {
            foreach ($this->coloresSeleccionados as $colorId) {
                $yaExiste = Variante::where('producto_id', $this->productoId)
                    ->where('talla_id', $tallaId)
                    ->where('color_id', $colorId)
                    ->exists();

                if (! $yaExiste) {
                    $accion->crear([
                        'producto_id' => $this->productoId,
                        'talla_id'    => $tallaId,
                        'color_id'    => $colorId,
                    ]);
                    $creadas++;
                }
            }
        }

        $this->tallasSeleccionadas = [];
        $this->coloresSeleccionados = [];
        $this->cargarVariantes();

        $mensaje = $creadas > 0
            ? "Se agregaron {$creadas} variante(s) nueva(s)."
            : 'Esas combinaciones ya existían, no se agregó nada nuevo.';

        $this->dispatch('guardado', mensaje: $mensaje);
    }

    public function toggleActivaVariante(int $id): void
    {
        $variante = Variante::findOrFail($id);
        $variante->activa = ! $variante->activa;
        $variante->save();

        $this->cargarVariantes();
    }

    // ============ PUBLICACIONES (producto_campana) ============

    public function abrirFormularioNuevaPublicacion(): void
    {
        $this->publicacion_campana_id = null;
        $this->publicacion_codigo_catalogo = '';
        $this->publicacion_precio_mayorista = 0;
        $this->publicacion_precio_minorista_sugerido = 0;
        $this->errorNegocio = null;
        $this->mostrandoFormularioPublicacion = true;
    }

    public function cancelarFormularioPublicacion(): void
    {
        $this->mostrandoFormularioPublicacion = false;
        $this->errorNegocio = null;
    }

    public function guardarPublicacion(): void
    {
        $this->errorNegocio = null;

        $datos = $this->validate([
            'publicacion_campana_id'                => ['required', 'integer'],
            'publicacion_codigo_catalogo'            => ['required', 'string', 'max:120'],
            'publicacion_precio_mayorista'           => ['required', 'numeric', 'min:0'],
            'publicacion_precio_minorista_sugerido'  => ['required', 'numeric', 'min:0'],
        ]);

        try {
            app(GestionarProductoCampanaAction::class)->crear([
                'producto_id'               => $this->productoId,
                'campana_id'                => $datos['publicacion_campana_id'],
                'codigo_catalogo'           => $datos['publicacion_codigo_catalogo'],
                'precio_mayorista'          => $datos['publicacion_precio_mayorista'],
                'precio_minorista_sugerido' => $datos['publicacion_precio_minorista_sugerido'],
            ]);
        } catch (OperacionInvalidaException $e) {
            $this->errorNegocio = $e->getMessage();
            return;
        }

        $this->mostrandoFormularioPublicacion = false;
        $this->cargarPublicaciones();

        $this->dispatch('guardado', mensaje: 'Publicación creada correctamente.');
    }

    /**
     * Abre el panel de gestión de UNA publicación: precios, imágenes y
     * disponibilidad por variante.
     */
    public function gestionarPublicacion(int $id): void
    {
        $publicacion = ProductoCampana::findOrFail($id);

        $this->publicacionGestionandoId = $publicacion->id;
        $this->gestion_precio_mayorista = (float) $publicacion->precio_mayorista;
        $this->gestion_precio_minorista_sugerido = (float) $publicacion->precio_minorista_sugerido;
        $this->gestion_publicado = (bool) $publicacion->publicado;
        $this->nuevaImagen = null;

        $this->cargarImagenesPublicacion();
        $this->cargarDisponibilidad();
    }

    public function cerrarGestionPublicacion(): void
    {
        $this->publicacionGestionandoId = null;
        $this->imagenesPublicacion = [];
        $this->disponibilidadPorVariante = [];
    }

    private function cargarImagenesPublicacion(): void
    {
        $this->imagenesPublicacion = ProductoImagen::where('producto_campana_id', $this->publicacionGestionandoId)
            ->orderBy('orden')
            ->get();
    }

    private function cargarDisponibilidad(): void
    {
        $existentes = DisponibilidadVarianteCampana::where('producto_campana_id', $this->publicacionGestionandoId)
            ->pluck('estado', 'variante_id');

        $this->disponibilidadPorVariante = [];
        foreach ($this->variantes as $variante) {
            $this->disponibilidadPorVariante[$variante->id] = $existentes[$variante->id] ?? null;
        }
    }

    public function actualizarPreciosPublicacion(): void
    {
        $datos = $this->validate([
            'gestion_precio_mayorista'          => ['required', 'numeric', 'min:0'],
            'gestion_precio_minorista_sugerido' => ['required', 'numeric', 'min:0'],
        ]);

        $publicacion = ProductoCampana::findOrFail($this->publicacionGestionandoId);

        app(GestionarProductoCampanaAction::class)->actualizar($publicacion, [
            'precio_mayorista'          => $datos['gestion_precio_mayorista'],
            'precio_minorista_sugerido' => $datos['gestion_precio_minorista_sugerido'],
            'publicado'                 => $this->gestion_publicado,
        ]);

        $this->cargarPublicaciones();
        $this->dispatch('guardado', mensaje: 'Precios actualizados correctamente.');
    }

    public function subirImagenPublicacion(): void
    {
        $this->validate(['nuevaImagen' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120']]);

        $publicacion = ProductoCampana::findOrFail($this->publicacionGestionandoId);

        app(GestionarImagenProductoCampanaAction::class)->agregar($publicacion, $this->nuevaImagen);

        $this->nuevaImagen = null;
        $this->cargarImagenesPublicacion();
        $this->dispatch('guardado', mensaje: 'Imagen agregada correctamente.');
    }

    public function marcarImagenPrincipal(int $imagenId): void
    {
        app(GestionarImagenProductoCampanaAction::class)->marcarPrincipal(ProductoImagen::findOrFail($imagenId));
        $this->cargarImagenesPublicacion();
    }

    public function eliminarImagen(int $imagenId): void
    {
        app(GestionarImagenProductoCampanaAction::class)->eliminar(ProductoImagen::findOrFail($imagenId));
        $this->cargarImagenesPublicacion();
    }

    /**
     * Se llama al cambiar el <select> de disponibilidad de una variante —
     * guarda al instante (crea si no existía, actualiza si ya existía).
     */
    public function actualizarDisponibilidad(int $varianteId, string $estado): void
    {
        $publicacion = ProductoCampana::findOrFail($this->publicacionGestionandoId);

        $existente = DisponibilidadVarianteCampana::where('producto_campana_id', $publicacion->id)
            ->where('variante_id', $varianteId)
            ->first();

        $accion = app(GestionarDisponibilidadVarianteCampanaAction::class);

        if ($existente) {
            $accion->actualizar($existente, ['estado' => $estado]);
        } else {
            $accion->crear([
                'producto_campana_id' => $publicacion->id,
                'variante_id'         => $varianteId,
                'estado'              => $estado,
            ]);
        }

        $this->disponibilidadPorVariante[$varianteId] = $estado;
        $this->dispatch('guardado', mensaje: 'Disponibilidad actualizada.');
    }
};
?>

<div>
    <a href="{{ route('distribuidora.catalogo') }}" class="text-sm text-fp-primary mb-4 inline-block">← Volver al catálogo</a>

    <h1 class="text-xl font-semibold text-slate-800 mb-1">{{ $producto->nombre }}</h1>
    <p class="text-sm text-fp-text-muted mb-6">
        {{ $producto->modelo }} · {{ $producto->marca->nombre ?? '—' }} · {{ $producto->categoria->nombre ?? '—' }}
    </p>

    {{-- Aviso de guardado --}}
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

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Variantes existentes --}}
        <div class="bg-white rounded-lg shadow-sm p-6">
            <h2 class="text-sm font-semibold text-slate-700 mb-4">Variantes</h2>
            @if (count($variantes) === 0)
                <p class="text-sm text-fp-text-muted">Todavía no hay variantes — agrega una combinación de talla y color a la derecha.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b">
                            <th class="py-2">SKU</th>
                            <th class="py-2">Talla</th>
                            <th class="py-2">Color</th>
                            <th class="py-2">Estado</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($variantes as $variante)
                            <tr class="border-b last:border-0">
                                <td class="py-2 font-mono text-xs">{{ $variante->sku }}</td>
                                <td class="py-2">{{ $variante->talla->valor }}</td>
                                <td class="py-2">{{ $variante->nombre_color_comercial ?? $variante->color->nombre }}</td>
                                <td class="py-2">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $variante->activa ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                        {{ $variante->activa ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <button type="button" wire:click="toggleActivaVariante({{ $variante->id }})" class="text-fp-primary text-xs font-medium">
                                        {{ $variante->activa ? 'Desactivar' : 'Activar' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Agregar variantes (matriz talla x color) --}}
        <div class="bg-white rounded-lg shadow-sm p-6">
            <h2 class="text-sm font-semibold text-slate-700 mb-4">Agregar variantes</h2>

            @error('seleccion') <p class="text-fp-badge-danger-fg text-xs mb-3">{{ $message }}</p> @enderror

            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-2">Tallas disponibles</label>
                <div class="flex gap-2 flex-wrap">
                    @foreach ($tallas as $talla)
                        <label class="flex items-center gap-1 text-sm border rounded px-2 py-1 cursor-pointer">
                            <input type="checkbox" wire:model="tallasSeleccionadas" value="{{ $talla->id }}" class="rounded border-slate-300">
                            {{ $talla->valor }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 mb-2">Colores</label>
                <div class="flex gap-2 flex-wrap">
                    @foreach ($colores as $color)
                        <label class="flex items-center gap-1 text-sm border rounded px-2 py-1 cursor-pointer">
                            <input type="checkbox" wire:model="coloresSeleccionados" value="{{ $color->id }}" class="rounded border-slate-300">
                            {{ $color->nombre }}
                        </label>
                    @endforeach
                </div>
            </div>

            <button type="button" wire:click="crearVariantesSeleccionadas" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled" wire:target="crearVariantesSeleccionadas">
                Agregar combinaciones seleccionadas
            </button>
        </div>
    </div>

    {{-- Publicaciones (producto_campana): crear + gestionar precios/imágenes/disponibilidad --}}
    <div class="bg-white rounded-lg shadow-sm p-6 mt-6">
        @if ($errorNegocio)
            <div class="mb-4 rounded-md bg-fp-badge-danger-bg text-fp-badge-danger-fg px-4 py-2 text-sm">
                {{ $errorNegocio }}
            </div>
        @endif

        @if ($publicacionGestionandoId)
            {{-- Panel de gestión de UNA publicación --}}
            @php
                $publicacionActual = $publicaciones->firstWhere('id', $publicacionGestionandoId);
            @endphp
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-sm font-semibold text-slate-700">
                    Gestionando: {{ $publicacionActual->campana->nombre ?? '' }}
                </h2>
                <button type="button" wire:click="cerrarGestionPublicacion" class="text-slate-600 text-sm">
                    ← Volver a la lista
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Precios --}}
                <div>
                    <h3 class="text-xs font-semibold text-slate-500 uppercase mb-3">Precios y publicación</h3>
                    <form wire:submit="actualizarPreciosPublicacion" class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">P. Mayorista</label>
                                <input type="number" step="0.01" wire:model="gestion_precio_mayorista" class="w-full rounded-md border-slate-300">
                                @error('gestion_precio_mayorista') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">P. Minorista</label>
                                <input type="number" step="0.01" wire:model="gestion_precio_minorista_sugerido" class="w-full rounded-md border-slate-300">
                                @error('gestion_precio_minorista_sugerido') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="gestion_publicado" class="rounded border-slate-300">
                            Publicado (visible en el catálogo consultable)
                        </label>
                        <button type="submit" class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium" wire:loading.attr="disabled" wire:target="actualizarPreciosPublicacion">
                            Guardar precios
                        </button>
                    </form>

                    {{-- Imágenes --}}
                    <h3 class="text-xs font-semibold text-slate-500 uppercase mt-6 mb-3">Imágenes</h3>
                    <div class="flex gap-2 flex-wrap mb-3">
                        @foreach ($imagenesPublicacion as $imagen)
                            <div class="relative">
                                <img src="{{ $imagen->url }}" class="h-16 w-16 object-cover rounded {{ $imagen->es_principal ? 'ring-2 ring-fp-primary' : '' }}">
                                <div class="flex gap-1 mt-1">
                                    @if (! $imagen->es_principal)
                                        <button type="button" wire:click="marcarImagenPrincipal({{ $imagen->id }})" class="text-[10px] text-fp-primary">Principal</button>
                                    @endif
                                    <button type="button" wire:click="eliminarImagen({{ $imagen->id }})" class="text-[10px] text-fp-badge-danger-fg">Borrar</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <input type="file" wire:model="nuevaImagen" accept="image/png,image/jpeg">
                    @error('nuevaImagen') <span class="text-fp-badge-danger-fg text-xs block">{{ $message }}</span> @enderror
                    <div wire:loading wire:target="nuevaImagen" class="text-xs text-fp-text-muted">Subiendo...</div>
                    @if ($nuevaImagen)
                        <button type="button" wire:click="subirImagenPublicacion" class="block mt-2 bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium" wire:loading.attr="disabled" wire:target="subirImagenPublicacion">
                            Confirmar subida
                        </button>
                    @endif
                </div>

                {{-- Disponibilidad por variante --}}
                <div>
                    <h3 class="text-xs font-semibold text-slate-500 uppercase mb-3">Disponibilidad por variante</h3>
                    @if (count($variantes) === 0)
                        <p class="text-sm text-fp-text-muted">Este producto todavía no tiene variantes — agrégalas arriba primero.</p>
                    @else
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-slate-500 border-b">
                                    <th class="py-2">SKU</th>
                                    <th class="py-2">Disponibilidad</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($variantes as $variante)
                                    <tr class="border-b last:border-0">
                                        <td class="py-2 font-mono text-xs">{{ $variante->sku }}</td>
                                        <td class="py-2">
                                            <select
                                                wire:change="actualizarDisponibilidad({{ $variante->id }}, $event.target.value)"
                                                class="rounded-md border-slate-300 text-sm"
                                            >
                                                <option value="" @selected(! $disponibilidadPorVariante[$variante->id])>Sin definir</option>
                                                <option value="disponible" @selected($disponibilidadPorVariante[$variante->id] === 'disponible')>Disponible</option>
                                                <option value="bajo_pedido" @selected($disponibilidadPorVariante[$variante->id] === 'bajo_pedido')>Bajo pedido</option>
                                                <option value="no_disponible" @selected($disponibilidadPorVariante[$variante->id] === 'no_disponible')>No disponible</option>
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        @elseif (! $mostrandoFormularioPublicacion)
            {{-- Lista de publicaciones --}}
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-sm font-semibold text-slate-700">Publicaciones por campaña</h2>
                <button type="button" wire:click="abrirFormularioNuevaPublicacion" class="bg-fp-primary text-white px-3 py-1.5 rounded-md text-sm font-medium">
                    + Nueva publicación
                </button>
            </div>
            @if (count($publicaciones) === 0)
                <p class="text-sm text-fp-text-muted">Este producto todavía no está publicado en ninguna campaña.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b">
                            <th class="py-2">Campaña</th>
                            <th class="py-2">Código catálogo</th>
                            <th class="py-2">P. Mayorista</th>
                            <th class="py-2">P. Minorista</th>
                            <th class="py-2">Publicado</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($publicaciones as $publicacion)
                            <tr class="border-b last:border-0">
                                <td class="py-2">{{ $publicacion->campana->nombre ?? '—' }}</td>
                                <td class="py-2">{{ $publicacion->codigo_catalogo }}</td>
                                <td class="py-2">${{ number_format($publicacion->precio_mayorista, 2) }}</td>
                                <td class="py-2">${{ number_format($publicacion->precio_minorista_sugerido, 2) }}</td>
                                <td class="py-2">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $publicacion->publicado ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                        {{ $publicacion->publicado ? 'Sí' : 'No' }}
                                    </span>
                                </td>
                                <td class="py-2 text-right">
                                    <button type="button" wire:click="gestionarPublicacion({{ $publicacion->id }})" class="text-fp-primary text-xs font-medium">
                                        Gestionar
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @else
            {{-- Formulario: nueva publicación --}}
            <form wire:submit="guardarPublicacion" class="space-y-4 max-w-lg">
                <h2 class="text-sm font-semibold text-slate-700">Nueva publicación</h2>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Campaña</label>
                    <select wire:model="publicacion_campana_id" class="w-full rounded-md border-slate-300">
                        <option value="">Seleccionar campaña</option>
                        @foreach ($campanasDeLaMarca as $campana)
                            <option value="{{ $campana->id }}">{{ $campana->nombre }} ({{ $campana->estado }})</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-fp-text-muted mt-1">Solo se muestran campañas de la misma marca que este producto.</p>
                    @error('publicacion_campana_id') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Código de catálogo</label>
                    <input type="text" wire:model="publicacion_codigo_catalogo" class="w-full rounded-md border-slate-300">
                    @error('publicacion_codigo_catalogo') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Precio Mayorista (MXN)</label>
                        <input type="number" step="0.01" wire:model="publicacion_precio_mayorista" class="w-full rounded-md border-slate-300">
                        @error('publicacion_precio_mayorista') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Precio Minorista Sugerido (MXN)</label>
                        <input type="number" step="0.01" wire:model="publicacion_precio_minorista_sugerido" class="w-full rounded-md border-slate-300">
                        @error('publicacion_precio_minorista_sugerido') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="bg-fp-primary text-white px-4 py-2 rounded-md text-sm font-medium" wire:loading.attr="disabled" wire:target="guardarPublicacion">
                        Guardar Producto
                    </button>
                    <button type="button" wire:click="cancelarFormularioPublicacion" class="text-slate-600 px-4 py-2 rounded-md text-sm font-medium">
                        Cancelar
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
