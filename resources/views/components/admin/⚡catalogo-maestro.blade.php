<?php

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Catálogo maestro — FootwearPoint')] class extends Component {
    public string $pestana = 'lineas';

    public string $linea_nombre = '';
    public string $linea_descripcion = '';

    public string $marca_nombre = '';
    public string $marca_descripcion = '';

    public string $temporada_linea_id = '';
    public string $temporada_nombre = '';
    public string $temporada_estado = 'borrador';

    public string $producto_marca_id = '';
    public string $producto_categoria_id = '';
    public string $producto_modelo = '';
    public string $producto_nombre = '';
    public string $producto_campana_id = '';
    public string $producto_codigo = '';
    public string $producto_precio = '';

    public function guardarLinea(): void
    {
        $datos = $this->validate([
            'linea_nombre' => ['required', 'string', 'max:150', 'unique:lineas,nombre'],
            'linea_descripcion' => ['nullable', 'string', 'max:500'],
        ], [
            'linea_nombre.required' => 'El nombre es obligatorio.',
            'linea_nombre.unique' => 'Esa línea ya existe.',
        ]);

        Linea::create([
            'nombre' => $datos['linea_nombre'],
            'descripcion' => $datos['linea_descripcion'] ?: null,
            'activa' => true,
        ]);

        $this->reset('linea_nombre', 'linea_descripcion');
        $this->dispatch('guardado', mensaje: 'Línea creada.');
    }

    public function guardarMarca(): void
    {
        $datos = $this->validate([
            'marca_nombre' => ['required', 'string', 'max:150', 'unique:marcas,nombre'],
            'marca_descripcion' => ['nullable', 'string', 'max:500'],
        ], [
            'marca_nombre.required' => 'El nombre es obligatorio.',
            'marca_nombre.unique' => 'Esa marca ya existe.',
        ]);

        Marca::create([
            'nombre' => $datos['marca_nombre'],
            'descripcion' => $datos['marca_descripcion'] ?: null,
            'activa' => true,
        ]);

        $this->reset('marca_nombre', 'marca_descripcion');
        $this->dispatch('guardado', mensaje: 'Marca creada.');
    }

    public function guardarTemporada(): void
    {
        $datos = $this->validate([
            'temporada_linea_id' => ['required', 'exists:lineas,id'],
            'temporada_nombre' => ['required', 'string', 'max:150'],
            'temporada_estado' => ['required', 'in:borrador,activa,cerrada'],
        ], [
            'temporada_linea_id.required' => 'Elige la línea.',
            'temporada_nombre.required' => 'El nombre es obligatorio.',
        ]);

        Campana::create([
            'linea_id' => $datos['temporada_linea_id'],
            'nombre' => $datos['temporada_nombre'],
            'estado' => $datos['temporada_estado'],
        ]);

        $this->reset('temporada_linea_id', 'temporada_nombre');
        $this->temporada_estado = 'borrador';
        $this->dispatch('guardado', mensaje: 'Temporada creada.');
    }

    public function guardarProducto(): void
    {
        $datos = $this->validate([
            'producto_marca_id' => ['required', 'exists:marcas,id'],
            'producto_categoria_id' => ['required', 'exists:categorias_producto,id'],
            'producto_modelo' => ['required', 'string', 'max:100'],
            'producto_nombre' => ['required', 'string', 'max:200'],
            'producto_campana_id' => ['required', 'exists:campanas,id'],
            'producto_codigo' => ['required', 'string', 'max:60', 'unique:producto_campana,codigo_catalogo'],
            'producto_precio' => ['required', 'numeric', 'min:0'],
        ], [
            'producto_marca_id.required' => 'Elige la marca.',
            'producto_categoria_id.required' => 'Elige la categoría.',
            'producto_modelo.required' => 'El modelo es obligatorio.',
            'producto_nombre.required' => 'El nombre es obligatorio.',
            'producto_campana_id.required' => 'Elige la temporada.',
            'producto_codigo.required' => 'El código es obligatorio.',
            'producto_codigo.unique' => 'Ese código de catálogo ya existe.',
            'producto_precio.required' => 'El precio de menudeo es obligatorio.',
        ]);

        $producto = Producto::create([
            'marca_id' => $datos['producto_marca_id'],
            'categoria_id' => $datos['producto_categoria_id'],
            'modelo' => $datos['producto_modelo'],
            'nombre' => $datos['producto_nombre'],
            'activo' => true,
        ]);

        ProductoCampana::create([
            'producto_id' => $producto->id,
            'campana_id' => $datos['producto_campana_id'],
            'codigo_catalogo' => $datos['producto_codigo'],
            'precio_publico' => $datos['producto_precio'],
            'activo' => true,
        ]);

        $this->reset(
            'producto_marca_id',
            'producto_categoria_id',
            'producto_modelo',
            'producto_nombre',
            'producto_campana_id',
            'producto_codigo',
            'producto_precio',
        );
        $this->dispatch('guardado', mensaje: 'Producto publicado en la temporada.');
    }

    public function getLineasProperty()
    {
        return Linea::query()->withCount('campanas')->orderBy('nombre')->get();
    }

    public function getMarcasProperty()
    {
        return Marca::query()->orderBy('nombre')->get();
    }

    public function getCategoriasProperty()
    {
        return CategoriaProducto::query()->where('activa', true)->orderBy('nombre')->get();
    }

    public function getTemporadasProperty()
    {
        return Campana::query()->with('linea')->orderBy('nombre')->get();
    }

    public function getProductosProperty()
    {
        return ProductoCampana::query()
            ->with(['producto.marca', 'campana.linea'])
            ->latest('id')
            ->limit(50)
            ->get();
    }
};
?>

<div class="space-y-6">
    <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-fp-primary">Catálogo maestro</p>
        <h1 class="mt-1 text-2xl font-bold text-fp-sidebar">Líneas, marcas, temporadas y productos</h1>
        <p class="mt-1 text-sm text-slate-500">Solo el admin general puede crearlos. Son los mismos para todas las distribuidoras.</p>
    </div>

    <div x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible" x-cloak
        class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
        <span x-text="mensaje"></span>
    </div>

    <div class="flex flex-wrap gap-2">
        <button type="button" wire:click="$set('pestana', 'lineas')" class="rounded-lg px-3 py-2 text-sm {{ $pestana === 'lineas' ? 'bg-fp-primary text-white' : 'bg-white text-slate-600' }}">Líneas</button>
        <button type="button" wire:click="$set('pestana', 'marcas')" class="rounded-lg px-3 py-2 text-sm {{ $pestana === 'marcas' ? 'bg-fp-primary text-white' : 'bg-white text-slate-600' }}">Marcas</button>
        <button type="button" wire:click="$set('pestana', 'temporadas')" class="rounded-lg px-3 py-2 text-sm {{ $pestana === 'temporadas' ? 'bg-fp-primary text-white' : 'bg-white text-slate-600' }}">Temporadas</button>
        <button type="button" wire:click="$set('pestana', 'productos')" class="rounded-lg px-3 py-2 text-sm {{ $pestana === 'productos' ? 'bg-fp-primary text-white' : 'bg-white text-slate-600' }}">Productos</button>
    </div>

    @if ($pestana === 'lineas')
        <form wire:submit="guardarLinea" class="grid max-w-xl grid-cols-1 gap-3 rounded-2xl border bg-white p-4">
            <input type="text" wire:model="linea_nombre" placeholder="Nombre de la línea" class="rounded-lg border-slate-200 text-sm">
            @error('linea_nombre') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="text" wire:model="linea_descripcion" placeholder="Descripción" class="rounded-lg border-slate-200 text-sm">
            <button class="w-fit rounded-lg bg-fp-primary px-4 py-2 text-sm text-white">Crear línea</button>
        </form>
        <table class="w-full rounded-2xl bg-white text-sm">
            <thead><tr class="text-left text-slate-500"><th class="p-3">Nombre</th><th class="p-3">Temporadas</th><th class="p-3">Estado</th></tr></thead>
            <tbody>
                @foreach ($this->lineas as $linea)
                    <tr class="border-t">
                        <td class="p-3">{{ $linea->nombre }}</td>
                        <td class="p-3">{{ $linea->campanas_count }}</td>
                        <td class="p-3">{{ $linea->activa ? 'Activa' : 'Inactiva' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif ($pestana === 'marcas')
        <form wire:submit="guardarMarca" class="grid max-w-xl grid-cols-1 gap-3 rounded-2xl border bg-white p-4">
            <input type="text" wire:model="marca_nombre" placeholder="Nombre de la marca" class="rounded-lg border-slate-200 text-sm">
            @error('marca_nombre') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="text" wire:model="marca_descripcion" placeholder="Descripción" class="rounded-lg border-slate-200 text-sm">
            <button class="w-fit rounded-lg bg-fp-primary px-4 py-2 text-sm text-white">Crear marca</button>
        </form>
        <table class="w-full rounded-2xl bg-white text-sm">
            <thead><tr class="text-left text-slate-500"><th class="p-3">Nombre</th><th class="p-3">Estado</th></tr></thead>
            <tbody>
                @foreach ($this->marcas as $marca)
                    <tr class="border-t"><td class="p-3">{{ $marca->nombre }}</td><td class="p-3">{{ $marca->activa ? 'Activa' : 'Inactiva' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @elseif ($pestana === 'temporadas')
        <form wire:submit="guardarTemporada" class="grid max-w-xl grid-cols-1 gap-3 rounded-2xl border bg-white p-4">
            <select wire:model="temporada_linea_id" class="rounded-lg border-slate-200 text-sm">
                <option value="">Línea</option>
                @foreach ($this->lineas as $linea)
                    <option value="{{ $linea->id }}">{{ $linea->nombre }}</option>
                @endforeach
            </select>
            @error('temporada_linea_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="text" wire:model="temporada_nombre" placeholder="Nombre de la temporada" class="rounded-lg border-slate-200 text-sm">
            @error('temporada_nombre') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <select wire:model="temporada_estado" class="rounded-lg border-slate-200 text-sm">
                <option value="borrador">Borrador</option>
                <option value="activa">Activa</option>
                <option value="cerrada">Cerrada</option>
            </select>
            @error('estado') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <button class="w-fit rounded-lg bg-fp-primary px-4 py-2 text-sm text-white">Crear temporada</button>
        </form>
        <table class="w-full rounded-2xl bg-white text-sm">
            <thead><tr class="text-left text-slate-500"><th class="p-3">Temporada</th><th class="p-3">Línea</th><th class="p-3">Estado</th></tr></thead>
            <tbody>
                @foreach ($this->temporadas as $temporada)
                    <tr class="border-t">
                        <td class="p-3">{{ $temporada->nombre }}</td>
                        <td class="p-3">{{ $temporada->linea?->nombre }}</td>
                        <td class="p-3">{{ ucfirst($temporada->estado) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <form wire:submit="guardarProducto" class="grid max-w-xl grid-cols-1 gap-3 rounded-2xl border bg-white p-4">
            <select wire:model="producto_marca_id" class="rounded-lg border-slate-200 text-sm">
                <option value="">Marca</option>
                @foreach ($this->marcas as $marca)
                    <option value="{{ $marca->id }}">{{ $marca->nombre }}</option>
                @endforeach
            </select>
            @error('producto_marca_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <select wire:model="producto_categoria_id" class="rounded-lg border-slate-200 text-sm">
                <option value="">Categoría</option>
                @foreach ($this->categorias as $categoria)
                    <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                @endforeach
            </select>
            @error('producto_categoria_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="text" wire:model="producto_modelo" placeholder="Modelo" class="rounded-lg border-slate-200 text-sm">
            @error('producto_modelo') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="text" wire:model="producto_nombre" placeholder="Nombre" class="rounded-lg border-slate-200 text-sm">
            @error('producto_nombre') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <select wire:model="producto_campana_id" class="rounded-lg border-slate-200 text-sm">
                <option value="">Temporada</option>
                @foreach ($this->temporadas as $temporada)
                    <option value="{{ $temporada->id }}">{{ $temporada->linea?->nombre }} — {{ $temporada->nombre }}</option>
                @endforeach
            </select>
            @error('producto_campana_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="text" wire:model="producto_codigo" placeholder="Código de catálogo" class="rounded-lg border-slate-200 text-sm">
            @error('producto_codigo') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="number" step="0.01" wire:model="producto_precio" placeholder="Precio de menudeo" class="rounded-lg border-slate-200 text-sm">
            @error('producto_precio') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <button class="w-fit rounded-lg bg-fp-primary px-4 py-2 text-sm text-white">Publicar producto</button>
        </form>
        <table class="w-full rounded-2xl bg-white text-sm">
            <thead>
                <tr class="text-left text-slate-500">
                    <th class="p-3">Producto</th>
                    <th class="p-3">Marca</th>
                    <th class="p-3">Temporada</th>
                    <th class="p-3">Código</th>
                    <th class="p-3">Menudeo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->productos as $fila)
                    <tr class="border-t">
                        <td class="p-3">{{ $fila->producto?->nombre }} <span class="text-slate-400">{{ $fila->producto?->modelo }}</span></td>
                        <td class="p-3">{{ $fila->producto?->marca?->nombre }}</td>
                        <td class="p-3">{{ $fila->campana?->linea?->nombre }} — {{ $fila->campana?->nombre }}</td>
                        <td class="p-3">{{ $fila->codigo_catalogo }}</td>
                        <td class="p-3">${{ number_format((float) $fila->precio_publico, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>