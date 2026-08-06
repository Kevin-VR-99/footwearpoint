<?php

use App\Models\Color;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Talla;
use App\Models\Variante;
use App\Services\Catalogo\GestionarVarianteAction;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::distribuidora')] class extends Component {
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

    public function mount(Producto $producto): void
    {
        $this->productoId = $producto->id;
        $this->cargarProducto();
        $this->cargarVariantes();
        $this->cargarPublicaciones();

        $this->tallas = Talla::orderBy('sistema')->orderByRaw('CAST(valor AS DECIMAL(5,2))')->get();
        $this->colores = Color::orderBy('nombre')->get();
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

    {{-- Publicaciones (producto_campana) — solo lectura por ahora --}}
    <div class="bg-white rounded-lg shadow-sm p-6 mt-6">
        <h2 class="text-sm font-semibold text-slate-700 mb-4">Publicaciones por campaña</h2>
        @if (count($publicaciones) === 0)
            <p class="text-sm text-fp-text-muted">
                Este producto todavía no está publicado en ninguna campaña. La gestión de precios, imágenes y disponibilidad por publicación se agrega en el siguiente paso.
            </p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 border-b">
                        <th class="py-2">Campaña</th>
                        <th class="py-2">Código catálogo</th>
                        <th class="py-2">P. Mayorista</th>
                        <th class="py-2">P. Minorista</th>
                        <th class="py-2">Publicado</th>
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
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
