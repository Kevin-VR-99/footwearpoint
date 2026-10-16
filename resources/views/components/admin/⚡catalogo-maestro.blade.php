<?php

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Services\Catalogo\GestionarCampanaAction;
use App\Services\Catalogo\GestionarLineaAction;
use App\Services\Catalogo\GestionarMarcaAction;
use App\Services\Catalogo\GestionarProductoAction;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Validation\Rule;
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

    public ?int $temporadaPorEliminar = null;
    public string $temporadaPorEliminarNombre = '';

    public string $producto_marca_id = '';
    public string $producto_categoria_id = '';
    public string $producto_modelo = '';
    public string $producto_nombre = '';
    public string $producto_campana_id = '';
    public string $producto_codigo = '';
    public string $producto_precio = '';

    // TG-247: edición y borrado. Un solo panel de edición para las 4 entidades.
    public string $editando_tipo = '';
    public ?int $editando_id = null;
    public string $editar_nombre = '';
    public string $editar_descripcion = '';
    public string $editar_marca_id = '';
    public string $editar_categoria_id = '';
    public string $editar_modelo = '';
    public string $editar_estado = '';

    public string $errorMsg = '';

    private const TIPOS = ['linea', 'marca', 'temporada', 'producto'];

    public function mount(): void
    {
        $this->autorizar();
    }

    /** Solo el admin general; la ruta ya lo exige, esto cubre llamadas directas al componente. */
    private function autorizar(): void
    {
        abort_unless(Tenant::esAdminGeneral(), 403, MensajeError::SIN_PERMISO);
    }

    private function buscar(string $tipo, int $id)
    {
        abort_unless(in_array($tipo, self::TIPOS, true), 404);

        return match ($tipo) {
            'linea' => Linea::findOrFail($id),
            'marca' => Marca::findOrFail($id),
            'temporada' => Campana::findOrFail($id),
            'producto' => Producto::findOrFail($id),
        };
    }

    public function editar(string $tipo, int $id): void
    {
        $this->autorizar();
        $this->errorMsg = '';
        $this->resetValidation();

        try {
            $registro = $this->buscar($tipo, $id);
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e);

            return;
        }

        $this->editando_tipo = $tipo;
        $this->editando_id = $registro->id;
        $this->editar_nombre = (string) $registro->nombre;
        $this->editar_descripcion = (string) ($registro->descripcion ?? '');
        $this->editar_marca_id = $tipo === 'producto' ? (string) $registro->marca_id : '';
        $this->editar_categoria_id = $tipo === 'producto' ? (string) $registro->categoria_id : '';
        $this->editar_modelo = $tipo === 'producto' ? (string) $registro->modelo : '';
        $this->editar_estado = $tipo === 'temporada' ? (string) $registro->estado : '';
    }

    public function cancelarEdicion(): void
    {
        $this->reset(
            'editando_tipo', 'editando_id', 'editar_nombre', 'editar_descripcion',
            'editar_marca_id', 'editar_categoria_id', 'editar_modelo', 'editar_estado',
        );
        $this->resetValidation();
    }

    public function guardarEdicion(): void
    {
        $this->autorizar();
        $this->errorMsg = '';

        $tipo = $this->editando_tipo;
        $id = (int) $this->editando_id;

        if (! in_array($tipo, self::TIPOS, true) || $id === 0) {
            return;
        }

        $mensajes = [
            'editar_nombre.required' => 'El nombre es obligatorio.',
            'editar_nombre.unique' => $tipo === 'linea' ? 'Esa línea ya existe.' : 'Esa marca ya existe.',
            'editar_marca_id.required' => 'Elige la marca.',
            'editar_categoria_id.required' => 'Elige la categoría.',
            'editar_modelo.required' => 'El modelo es obligatorio.',
            'editar_estado.in' => 'Ese estado no existe.',
        ];

        $datos = $this->validate(match ($tipo) {
            'linea' => [
                'editar_nombre' => ['required', 'string', 'max:150', Rule::unique('lineas', 'nombre')->ignore($id)],
                'editar_descripcion' => ['nullable', 'string', 'max:500'],
            ],
            'marca' => [
                'editar_nombre' => ['required', 'string', 'max:150', Rule::unique('marcas', 'nombre')->ignore($id)],
                'editar_descripcion' => ['nullable', 'string', 'max:500'],
            ],
            'temporada' => [
                'editar_nombre' => ['required', 'string', 'max:150'],
                'editar_descripcion' => ['nullable', 'string', 'max:500'],
                'editar_estado' => ['required', Rule::in(GestionarCampanaAction::ORDEN_ESTADOS)],
            ],
            'producto' => [
                'editar_nombre' => ['required', 'string', 'max:200'],
                'editar_descripcion' => ['nullable', 'string'],
                'editar_marca_id' => ['required', 'exists:marcas,id'],
                'editar_categoria_id' => ['required', 'exists:categorias_producto,id'],
                'editar_modelo' => ['required', 'string', 'max:120'],
            ],
        }, $mensajes);

        $comunes = [
            'nombre' => $datos['editar_nombre'],
            'descripcion' => ($datos['editar_descripcion'] ?? '') !== '' ? $datos['editar_descripcion'] : null,
        ];

        try {
            $registro = $this->buscar($tipo, $id);

            match ($tipo) {
                'linea' => app(GestionarLineaAction::class)->actualizar($registro, $comunes),
                'marca' => app(GestionarMarcaAction::class)->actualizar($registro, $comunes),
                'temporada' => app(GestionarCampanaAction::class)->actualizar($registro, $comunes + ['estado' => $datos['editar_estado']]),
                'producto' => app(GestionarProductoAction::class)->actualizar($registro, $comunes + [
                    'marca_id' => (int) $datos['editar_marca_id'],
                    'categoria_id' => (int) $datos['editar_categoria_id'],
                    'modelo' => $datos['editar_modelo'],
                ]),
            };
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudieron guardar los cambios.');

            return;
        }

        $this->cancelarEdicion();
        $this->dispatch('guardado', mensaje: 'Cambios guardados.');
    }

    /** Desactivar es la alternativa a borrar cuando hay datos que dependen del registro. */
    public function alternarActivo(string $tipo, int $id): void
    {
        $this->autorizar();
        $this->errorMsg = '';

        try {
            $registro = $this->buscar($tipo, $id);

            match ($tipo) {
                'linea' => app(GestionarLineaAction::class)->actualizar($registro, ['activa' => ! $registro->activa]),
                'marca' => app(GestionarMarcaAction::class)->actualizar($registro, ['activa' => ! $registro->activa]),
                'producto' => app(GestionarProductoAction::class)->actualizar($registro, ['activo' => ! $registro->activo]),
                default => abort(404),
            };
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo cambiar el estado.');

            return;
        }

        $this->dispatch('guardado', mensaje: 'Estado actualizado.');
    }

    public function pedirEliminarTemporada(int $id): void
    {
        $this->autorizar();
        $temporada = Campana::query()->findOrFail($id);
        $this->temporadaPorEliminar = $temporada->id;
        $this->temporadaPorEliminarNombre = $temporada->nombre;
    }

    public function cancelarEliminarTemporada(): void
    {
        $this->temporadaPorEliminar = null;
        $this->temporadaPorEliminarNombre = '';
    }

    public function confirmarEliminarTemporada(): void
    {
        if ($this->temporadaPorEliminar === null) {
            return;
        }

        $id = $this->temporadaPorEliminar;
        $this->cancelarEliminarTemporada();
        $this->eliminar('temporada', $id);
    }

    public function eliminar(string $tipo, int $id): void
    {
        $this->autorizar();
        $this->errorMsg = '';

        try {
            $registro = $this->buscar($tipo, $id);

            match ($tipo) {
                'linea' => app(GestionarLineaAction::class)->eliminar($registro),
                'marca' => app(GestionarMarcaAction::class)->eliminar($registro),
                'temporada' => app(GestionarCampanaAction::class)->eliminar($registro),
                'producto' => app(GestionarProductoAction::class)->eliminar($registro),
            };
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo eliminar.');

            return;
        }

        if ($this->editando_tipo === $tipo && $this->editando_id === $id) {
            $this->cancelarEdicion();
        }

        $nombres = ['linea' => 'Línea eliminada.', 'marca' => 'Marca eliminada.', 'temporada' => 'Temporada eliminada.', 'producto' => 'Producto eliminado.'];
        $this->dispatch('guardado', mensaje: $nombres[$tipo]);
    }

    public function guardarLinea(): void
    {
        $this->autorizar();

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
        $this->autorizar();

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
        $this->autorizar();

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
        $this->autorizar();

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
        return Marca::query()->withCount('productos')->orderBy('nombre')->get();
    }

    public function getCategoriasProperty()
    {
        return CategoriaProducto::query()->where('activa', true)->orderBy('nombre')->get();
    }

    public function getTemporadasProperty()
    {
        return Campana::query()->with('linea')->withCount('productosCampana')->orderBy('nombre')->get();
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
        <p class="mt-1 text-sm text-slate-500">Solo el admin general puede crearlos, editarlos y eliminarlos. Son los mismos para todas las distribuidoras.</p>
    </div>

    <div x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible" x-cloak
        class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
        <span x-text="mensaje"></span>
    </div>

    @if ($errorMsg !== '')
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700" role="alert">{{ $errorMsg }}</div>
    @endif

    @if ($editando_tipo !== '')
        @php
            $titulos = ['linea' => 'Editar línea', 'marca' => 'Editar marca', 'temporada' => 'Editar temporada', 'producto' => 'Editar producto'];
        @endphp
        <form wire:submit="guardarEdicion" class="grid max-w-xl grid-cols-1 gap-3 rounded-2xl border border-fp-primary/40 bg-white p-4">
            <h2 class="text-sm font-semibold text-fp-sidebar">{{ $titulos[$editando_tipo] ?? 'Editar' }}</h2>
            <input type="text" wire:model="editar_nombre" placeholder="Nombre" class="rounded-lg text-sm">
            @error('editar_nombre') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            @if ($editando_tipo === 'producto')
                <input type="text" wire:model="editar_modelo" placeholder="Modelo" class="rounded-lg text-sm">
                @error('editar_modelo') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                <select wire:model="editar_marca_id" class="rounded-lg text-sm">
                    <option value="">Marca</option>
                    @foreach ($this->marcas as $marca)
                        <option value="{{ $marca->id }}">{{ $marca->nombre }}</option>
                    @endforeach
                </select>
                @error('editar_marca_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                <select wire:model="editar_categoria_id" class="rounded-lg text-sm">
                    <option value="">Categoría</option>
                    @foreach ($this->categorias as $categoria)
                        <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                    @endforeach
                </select>
                @error('editar_categoria_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            @endif
            @if ($editando_tipo === 'temporada')
                <select wire:model="editar_estado" class="rounded-lg text-sm">
                    @foreach (\App\Services\Catalogo\GestionarCampanaAction::ORDEN_ESTADOS as $estado)
                        <option value="{{ $estado }}">{{ ucfirst(str_replace('_', ' ', $estado)) }}</option>
                    @endforeach
                </select>
                <span class="text-xs text-slate-500">El estado solo avanza un paso a la vez.</span>
                @error('editar_estado') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            @endif
            <input type="text" wire:model="editar_descripcion" placeholder="Descripción" class="rounded-lg text-sm">
            @error('editar_descripcion') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <div class="flex gap-2">
                <button class="w-fit rounded-lg bg-fp-primary px-4 py-2 text-sm text-white">Guardar cambios</button>
                <button type="button" wire:click="cancelarEdicion" class="w-fit rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600">Cancelar</button>
            </div>
        </form>
    @endif

    <div class="flex flex-wrap gap-2">
        <button type="button" wire:click="$set('pestana', 'lineas')" class="rounded-lg px-3 py-2 text-sm {{ $pestana === 'lineas' ? 'bg-fp-primary text-white' : 'bg-white text-slate-600' }}">Líneas</button>
        <button type="button" wire:click="$set('pestana', 'marcas')" class="rounded-lg px-3 py-2 text-sm {{ $pestana === 'marcas' ? 'bg-fp-primary text-white' : 'bg-white text-slate-600' }}">Marcas</button>
        <button type="button" wire:click="$set('pestana', 'temporadas')" class="rounded-lg px-3 py-2 text-sm {{ $pestana === 'temporadas' ? 'bg-fp-primary text-white' : 'bg-white text-slate-600' }}">Temporadas</button>
        <button type="button" wire:click="$set('pestana', 'productos')" class="rounded-lg px-3 py-2 text-sm {{ $pestana === 'productos' ? 'bg-fp-primary text-white' : 'bg-white text-slate-600' }}">Productos</button>
    </div>

    @if ($pestana === 'lineas')
        <form wire:submit="guardarLinea" class="grid max-w-xl grid-cols-1 gap-3 rounded-2xl border bg-white p-4">
            <input type="text" wire:model="linea_nombre" placeholder="Nombre de la línea" class="rounded-lg text-sm">
            @error('linea_nombre') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="text" wire:model="linea_descripcion" placeholder="Descripción" class="rounded-lg text-sm">
            <button class="w-fit rounded-lg bg-fp-primary px-4 py-2 text-sm text-white">Crear línea</button>
        </form>
        <table class="w-full rounded-2xl bg-white text-sm">
            <thead><tr class="text-left text-slate-500"><th class="p-3">Nombre</th><th class="p-3">Temporadas</th><th class="p-3">Estado</th><th class="p-3">Acciones</th></tr></thead>
            <tbody>
                @foreach ($this->lineas as $linea)
                    <tr class="border-t">
                        <td class="p-3">{{ $linea->nombre }}</td>
                        <td class="p-3">{{ $linea->campanas_count }}</td>
                        <td class="p-3">{{ $linea->activa ? 'Activa' : 'Inactiva' }}</td>
                        <td class="p-3 whitespace-nowrap text-xs">
                            <button type="button" wire:click="editar('linea', {{ $linea->id }})" class="text-fp-primary hover:underline">Editar</button>
                            <button type="button" wire:click="alternarActivo('linea', {{ $linea->id }})" class="text-slate-600 hover:underline">{{ $linea->activa ? 'Desactivar' : 'Activar' }}</button>
                            <button type="button" wire:click="eliminar('linea', {{ $linea->id }})" wire:confirm="¿Eliminar la línea «{{ $linea->nombre }}»? Esta acción no se puede deshacer." class="text-red-600 hover:underline">Eliminar</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif ($pestana === 'marcas')
        <form wire:submit="guardarMarca" class="grid max-w-xl grid-cols-1 gap-3 rounded-2xl border bg-white p-4">
            <input type="text" wire:model="marca_nombre" placeholder="Nombre de la marca" class="rounded-lg text-sm">
            @error('marca_nombre') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="text" wire:model="marca_descripcion" placeholder="Descripción" class="rounded-lg text-sm">
            <button class="w-fit rounded-lg bg-fp-primary px-4 py-2 text-sm text-white">Crear marca</button>
        </form>
        <table class="w-full rounded-2xl bg-white text-sm">
            <thead><tr class="text-left text-slate-500"><th class="p-3">Nombre</th><th class="p-3">Productos</th><th class="p-3">Estado</th><th class="p-3">Acciones</th></tr></thead>
            <tbody>
                @foreach ($this->marcas as $marca)
                    <tr class="border-t">
                        <td class="p-3">{{ $marca->nombre }}</td>
                        <td class="p-3">{{ $marca->productos_count }}</td>
                        <td class="p-3">{{ $marca->activa ? 'Activa' : 'Inactiva' }}</td>
                        <td class="p-3 whitespace-nowrap text-xs">
                            <button type="button" wire:click="editar('marca', {{ $marca->id }})" class="text-fp-primary hover:underline">Editar</button>
                            <button type="button" wire:click="alternarActivo('marca', {{ $marca->id }})" class="text-slate-600 hover:underline">{{ $marca->activa ? 'Desactivar' : 'Activar' }}</button>
                            <button type="button" wire:click="eliminar('marca', {{ $marca->id }})" wire:confirm="¿Eliminar la marca «{{ $marca->nombre }}»? Esta acción no se puede deshacer." class="text-red-600 hover:underline">Eliminar</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif ($pestana === 'temporadas')
        <form wire:submit="guardarTemporada" class="grid max-w-xl grid-cols-1 gap-3 rounded-2xl border bg-white p-4">
            <select wire:model="temporada_linea_id" class="rounded-lg text-sm">
                <option value="">Línea</option>
                @foreach ($this->lineas as $linea)
                    <option value="{{ $linea->id }}">{{ $linea->nombre }}</option>
                @endforeach
            </select>
            @error('temporada_linea_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="text" wire:model="temporada_nombre" placeholder="Nombre de la temporada" class="rounded-lg text-sm">
            @error('temporada_nombre') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <select wire:model="temporada_estado" class="rounded-lg text-sm">
                <option value="borrador">Borrador</option>
                <option value="activa">Activa</option>
                <option value="cerrada">Cerrada</option>
            </select>
            @error('estado') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <button class="w-fit rounded-lg bg-fp-primary px-4 py-2 text-sm text-white">Crear temporada</button>
        </form>
        <table class="w-full rounded-2xl bg-white text-sm">
            <thead><tr class="text-left text-slate-500"><th class="p-3">Temporada</th><th class="p-3">Línea</th><th class="p-3">Productos</th><th class="p-3">Estado</th><th class="p-3">Acciones</th></tr></thead>
            <tbody>
                @foreach ($this->temporadas as $temporada)
                    <tr class="border-t">
                        <td class="p-3">{{ $temporada->nombre }}</td>
                        <td class="p-3">{{ $temporada->linea?->nombre }}</td>
                        <td class="p-3">{{ $temporada->productos_campana_count }}</td>
                        <td class="p-3">{{ ucfirst(str_replace('_', ' ', $temporada->estado)) }}</td>
                        <td class="p-3 whitespace-nowrap text-xs">
                            <button type="button" wire:click="editar('temporada', {{ $temporada->id }})" class="text-fp-primary hover:underline">Editar</button>
                            <button type="button" wire:click="pedirEliminarTemporada({{ $temporada->id }})" class="text-red-600 hover:underline">Eliminar</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($temporadaPorEliminar)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-eliminar-temporada">
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                    <h2 id="titulo-eliminar-temporada" class="text-lg font-semibold text-slate-900">Eliminar temporada</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        ¿Eliminar la temporada «{{ $temporadaPorEliminarNombre }}»? Esta acción no se puede deshacer.
                    </p>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" wire:click="cancelarEliminarTemporada" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">Cancelar</button>
                        <button type="button" wire:click="confirmarEliminarTemporada" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">Eliminar</button>
                    </div>
                </div>
            </div>
        @endif
    @else
        <form wire:submit="guardarProducto" class="grid max-w-xl grid-cols-1 gap-3 rounded-2xl border bg-white p-4">
            <select wire:model="producto_marca_id" class="rounded-lg text-sm">
                <option value="">Marca</option>
                @foreach ($this->marcas as $marca)
                    <option value="{{ $marca->id }}">{{ $marca->nombre }}</option>
                @endforeach
            </select>
            @error('producto_marca_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <select wire:model="producto_categoria_id" class="rounded-lg text-sm">
                <option value="">Categoría</option>
                @foreach ($this->categorias as $categoria)
                    <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                @endforeach
            </select>
            @error('producto_categoria_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="text" wire:model="producto_modelo" placeholder="Modelo" class="rounded-lg text-sm">
            @error('producto_modelo') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="text" wire:model="producto_nombre" placeholder="Nombre" class="rounded-lg text-sm">
            @error('producto_nombre') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <select wire:model="producto_campana_id" class="rounded-lg text-sm">
                <option value="">Temporada</option>
                @foreach ($this->temporadas as $temporada)
                    <option value="{{ $temporada->id }}">{{ $temporada->linea?->nombre }} — {{ $temporada->nombre }}</option>
                @endforeach
            </select>
            @error('producto_campana_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="text" wire:model="producto_codigo" placeholder="Código de catálogo" class="rounded-lg text-sm">
            @error('producto_codigo') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            <input type="number" step="0.01" wire:model="producto_precio" placeholder="Precio de menudeo" class="rounded-lg text-sm">
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
                    <th class="p-3">Estado</th>
                    <th class="p-3">Acciones</th>
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
                        <td class="p-3">{{ $fila->producto?->activo ? 'Activo' : 'Inactivo' }}</td>
                        <td class="p-3 whitespace-nowrap text-xs">
                            @if ($fila->producto)
                                <button type="button" wire:click="editar('producto', {{ $fila->producto->id }})" class="text-fp-primary hover:underline">Editar</button>
                                <button type="button" wire:click="alternarActivo('producto', {{ $fila->producto->id }})" class="text-slate-600 hover:underline">{{ $fila->producto->activo ? 'Desactivar' : 'Activar' }}</button>
                                <button type="button" wire:click="eliminar('producto', {{ $fila->producto->id }})" wire:confirm="¿Eliminar el producto «{{ $fila->producto->nombre }}» de todo el catálogo, con sus tallas y publicaciones? Esta acción no se puede deshacer." class="text-red-600 hover:underline">Eliminar</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>