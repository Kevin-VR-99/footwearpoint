<?php

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Services\Catalogo\GestionarCampanaAction;
use App\Services\Catalogo\GestionarImagenProductoCampanaAction;
use App\Services\Catalogo\ImagenDeProducto;
use App\Services\Catalogo\GestionarLineaAction;
use App\Services\Catalogo\GestionarMarcaAction;
use App\Services\Catalogo\GestionarProductoAction;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Catálogo maestro — FootwearPoint')] class extends Component {
    use WithFileUploads;

    public string $pestana = 'lineas';

    public string $linea_nombre = '';
    public string $linea_descripcion = '';

    public string $marca_nombre = '';
    public string $marca_descripcion = '';

    public string $temporada_linea_id = '';
    public string $temporada_nombre = '';
    public string $temporada_estado = 'borrador';

    #[Locked]
    public string $porEliminarTipo = '';

    #[Locked]
    public ?int $porEliminarId = null;

    public string $porEliminarTitulo = '';

    public string $porEliminarAviso = '';

    public string $producto_marca_id = '';
    public string $producto_categoria_id = '';
    public string $producto_modelo = '';
    public string $producto_nombre = '';
    public string $producto_campana_id = '';
    public string $producto_codigo = '';
    public string $producto_precio = '';

    // TG-247: edición y borrado. Un solo panel de edición para las 4 entidades.
    public string $editando_tipo = '';
    #[Locked]
    public ?int $editando_id = null;
    public string $editar_nombre = '';
    public string $editar_descripcion = '';
    public string $editar_marca_id = '';
    public string $editar_categoria_id = '';
    public string $editar_modelo = '';
    public string $editar_estado = '';

    /*
     * E5-05 — Fotos de una publicación.
     *
     * Hasta ahora no había NINGUNA forma de subir la foto de un producto
     * desde el panel: solo existía la ruta del API, así que en la práctica
     * las únicas fotos eran las de la semilla. Las pantallas ya las enseñan;
     * lo que faltaba era ponerlas.
     */
    #[Locked]
    public ?int $fotosDe = null;

    public string $fotosDeNombre = '';

    public $fotoNueva = null;

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
            'editar_descripcion.max' => 'La descripción no puede tener más de :max caracteres.',
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
                'editar_descripcion' => ['nullable', 'string', 'max:2000'],
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

    public function pedirEliminar(string $tipo, int $id): void
    {
        $this->autorizar();
        $registro = $this->buscar($tipo, $id);

        [$titulo, $aviso] = match ($tipo) {
            'linea' => [
                'Eliminar línea',
                '¿Eliminar la línea «'.$registro->nombre.'»? Esta acción no se puede deshacer.',
            ],
            'marca' => [
                'Eliminar marca',
                '¿Eliminar la marca «'.$registro->nombre.'»? Esta acción no se puede deshacer.',
            ],
            'temporada' => [
                'Eliminar temporada',
                '¿Eliminar la temporada «'.$registro->nombre.'»? Esta acción no se puede deshacer.',
            ],
            'producto' => [
                'Eliminar producto',
                '¿Eliminar el producto «'.$registro->nombre.'» de todo el catálogo, con sus tallas y publicaciones? Esta acción no se puede deshacer.',
            ],
        };

        $this->porEliminarTipo = $tipo;
        $this->porEliminarId = $registro->id;
        $this->porEliminarTitulo = $titulo;
        $this->porEliminarAviso = $aviso;
    }

    public function cancelarEliminar(): void
    {
        $this->porEliminarTipo = '';
        $this->porEliminarId = null;
        $this->porEliminarTitulo = '';
        $this->porEliminarAviso = '';
    }

    public function confirmarEliminar(): void
    {
        if ($this->porEliminarId === null || $this->porEliminarTipo === '') {
            return;
        }

        $tipo = $this->porEliminarTipo;
        $id = $this->porEliminarId;
        $this->cancelarEliminar();
        $this->eliminar($tipo, $id);
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

    /** @return array<int, string> producto_campana_id => url */
    public function getFotosProperty(): array
    {
        return app(ImagenDeProducto::class)->porProductoCampana($this->productos->pluck('id'));
    }

    // ------------------------------------------------------------------
    // E5-05 — Fotos de una publicación
    // ------------------------------------------------------------------

    public function abrirFotos(int $productoCampanaId): void
    {
        $this->autorizar();

        $publicacion = ProductoCampana::with('producto')->findOrFail($productoCampanaId);

        $this->fotosDe = $publicacion->id;
        $this->fotosDeNombre = trim(($publicacion->producto?->nombre ?? 'Producto').' '.($publicacion->producto?->modelo ?? ''));
        $this->fotoNueva = null;
        $this->errorMsg = '';
        $this->resetValidation();
    }

    public function cerrarFotos(): void
    {
        $this->fotosDe = null;
        $this->fotosDeNombre = '';
        $this->fotoNueva = null;
        $this->resetValidation();
    }

    /** Las mismas reglas que la ruta del API: jpg/png y 5 MB. */
    public function subirFoto(): void
    {
        $this->autorizar();

        $this->validate([
            'fotoNueva' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'fotoNueva.required' => 'Elige una foto.',
            'fotoNueva.image'    => 'El archivo tiene que ser una imagen.',
            'fotoNueva.mimes'    => 'La foto tiene que ser jpg o png.',
            'fotoNueva.max'      => 'La foto no puede pasar de 5 MB.',
        ]);

        $publicacion = ProductoCampana::findOrFail($this->fotosDe);

        try {
            // La primera queda principal sola; eso lo decide la acción.
            app(GestionarImagenProductoCampanaAction::class)->agregar($publicacion, $this->fotoNueva);
        } catch (\Throwable $e) {
            report($e);
            $this->errorMsg = 'No se pudo guardar la foto. Inténtalo otra vez.';

            return;
        }

        $this->fotoNueva = null;
        $this->errorMsg = '';
    }

    public function hacerPrincipal(int $imagenId): void
    {
        $this->autorizar();

        app(GestionarImagenProductoCampanaAction::class)->marcarPrincipal($this->imagenDeLaPublicacion($imagenId));
    }

    public function quitarFoto(int $imagenId): void
    {
        $this->autorizar();

        // Se resuelve FUERA del try a propósito: si quedara dentro, el 404 de
        // una foto que no es de esta publicación se convertiría en un aviso
        // suave y el intento pasaría por bueno.
        $imagen = $this->imagenDeLaPublicacion($imagenId);

        try {
            app(GestionarImagenProductoCampanaAction::class)->eliminar($imagen);
        } catch (\Throwable $e) {
            report($e);
            // Si el bucket no confirmó el borrado, la fila se queda a
            // propósito: es el único dato que dice dónde está el archivo.
            $this->errorMsg = 'No se pudo borrar la foto del almacenamiento, así que sigue en el catálogo. Inténtalo otra vez.';
        }
    }

    /**
     * Que la foto sea de la publicación abierta. Sin esto, el id viaja desde
     * el navegador y se podría tocar la foto de cualquier otra.
     */
    private function imagenDeLaPublicacion(int $imagenId): ProductoImagen
    {
        abort_if($this->fotosDe === null, 404);

        return ProductoImagen::where('producto_campana_id', $this->fotosDe)->findOrFail($imagenId);
    }

    public function getFotosDeLaPublicacionProperty()
    {
        if ($this->fotosDe === null) {
            return collect();
        }

        return ProductoImagen::where('producto_campana_id', $this->fotosDe)
            ->orderByDesc('es_principal')
            ->orderBy('orden')
            ->get();
    }
};
?>

<div class="space-y-6">
    <x-panel.encabezado eyebrow="Catálogo maestro" titulo="Líneas, marcas, temporadas y productos" nivel="h1"
        subtitulo="Solo el admin general puede crearlos, editarlos y eliminarlos. Son los mismos para todas las distribuidoras." />

    <div class="fp-escalonado grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" data-metricas-admin>
        <x-panel.metrica titulo="Líneas" :valor="$this->lineas->count()" :detalle="$this->lineas->where('activa', true)->count().' activas'"
            icono="M4 6h16M4 12h16M4 18h10" />
        <x-panel.metrica titulo="Marcas" :valor="$this->marcas->count()" tono="exito" :detalle="$this->marcas->where('activa', true)->count().' activas'"
            icono="M7 7h.01M7 3h5l9 9-7 7-9-9V3z" />
        <x-panel.metrica titulo="Temporadas" :valor="$this->temporadas->count()" tono="aviso" :detalle="$this->temporadas->where('estado', 'activa')->count().' activas'"
            icono="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z" />
        <x-panel.metrica titulo="Productos recientes" :valor="$this->productos->count()" tono="neutral" detalle="Últimas 50 publicaciones"
            icono="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
    </div>

    <div x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible" x-cloak
        x-transition.opacity.duration.200ms role="status"
        class="fp-emerger rounded-xl border border-fp-badge-success-fg/20 bg-fp-badge-success-bg px-4 py-3 text-sm text-fp-badge-success-fg">
        <span x-text="mensaje"></span>
    </div>

    @if ($errorMsg !== '')
        <x-panel.alerta tipo="error">{{ $errorMsg }}</x-panel.alerta>
    @endif

    @if ($editando_tipo !== '')
        @php
            $titulos = ['linea' => 'Editar línea', 'marca' => 'Editar marca', 'temporada' => 'Editar temporada', 'producto' => 'Editar producto'];
        @endphp
        <form wire:submit="guardarEdicion" class="fp-formulario fp-aparecer grid max-w-xl grid-cols-1 gap-3 p-5" data-edicion-catalogo>
            <h2 class="text-base font-semibold text-fp-sidebar">{{ $titulos[$editando_tipo] ?? 'Editar' }}</h2>
            <input type="text" wire:model="editar_nombre" placeholder="Nombre" class="rounded-lg text-sm">
            @error('editar_nombre') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            @if ($editando_tipo === 'producto')
                <input type="text" wire:model="editar_modelo" placeholder="Modelo" class="rounded-lg text-sm">
                @error('editar_modelo') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                <select wire:model="editar_marca_id" class="rounded-lg text-sm">
                    <option value="">Marca</option>
                    @foreach ($this->marcas as $marca)
                        <option value="{{ $marca->id }}">{{ $marca->nombre }}</option>
                    @endforeach
                </select>
                @error('editar_marca_id') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                <select wire:model="editar_categoria_id" class="rounded-lg text-sm">
                    <option value="">Categoría</option>
                    @foreach ($this->categorias as $categoria)
                        <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                    @endforeach
                </select>
                @error('editar_categoria_id') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            @endif
            @if ($editando_tipo === 'temporada')
                <select wire:model="editar_estado" class="rounded-lg text-sm">
                    @foreach (\App\Services\Catalogo\GestionarCampanaAction::ORDEN_ESTADOS as $estado)
                        <option value="{{ $estado }}">{{ ucfirst(str_replace('_', ' ', $estado)) }}</option>
                    @endforeach
                </select>
                <span class="text-xs text-slate-500">El estado solo avanza un paso a la vez.</span>
                @error('editar_estado') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            @endif
            <input type="text" wire:model="editar_descripcion" placeholder="Descripción" class="rounded-lg text-sm">
            @error('editar_descripcion') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <div class="flex gap-2">
                <button class="fp-btn fp-btn-primario w-fit">Guardar cambios</button>
                <button type="button" wire:click="cancelarEdicion" class="fp-btn fp-btn-secundario w-fit">Cancelar</button>
            </div>
        </form>
    @endif

    {{-- E5-05: las fotos del catálogo. Hasta ahora las pantallas las
         enseñaban pero no había forma de ponerlas: solo existía la ruta del
         API, así que las únicas fotos eran las de la semilla. Son del
         catálogo compartido, por eso las pone el admin general y las ven
         todas las distribuidoras y la app. --}}
    @if ($fotosDe !== null)
        <div class="fp-formulario fp-aparecer max-w-xl space-y-4 p-5" data-fotos-publicacion>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-fp-sidebar">Fotos de {{ $fotosDeNombre }}</h2>
                    <p class="text-xs text-slate-500">La primera que subas queda como principal.</p>
                </div>
                <button type="button" wire:click="cerrarFotos" class="fp-accion">Cerrar</button>
            </div>

            @if ($this->fotosDeLaPublicacion->isEmpty())
                <p class="text-sm text-slate-500">Todavía no tiene ninguna foto.</p>
            @else
                <ul class="flex flex-wrap gap-3">
                    @foreach ($this->fotosDeLaPublicacion as $foto)
                        <li class="w-28 space-y-1">
                            <img src="{{ $foto->urlMiniatura() }}" alt="Foto de {{ $fotosDeNombre }}"
                                width="112" height="112" loading="lazy" decoding="async"
                                class="h-28 w-28 rounded-lg object-cover {{ $foto->es_principal ? 'ring-2 ring-fp-primary' : 'ring-1 ring-slate-200' }}">
                            @if ($foto->es_principal)
                                <p class="text-center text-xs font-semibold text-fp-primary">Principal</p>
                            @else
                                <button type="button" wire:click="hacerPrincipal({{ $foto->id }})" class="fp-accion w-full">Hacer principal</button>
                            @endif
                            <button type="button" wire:click="quitarFoto({{ $foto->id }})"
                                wire:confirm="¿Quitar esta foto? Se borra del almacenamiento y deja de verse en el panel y en la app."
                                class="fp-accion fp-accion-peligro w-full">Quitar</button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form wire:submit="subirFoto" class="space-y-2">
                <label for="catalogo-maestro-foto" class="block text-sm font-medium text-slate-700">Agregar una foto</label>
                <input type="file" id="catalogo-maestro-foto" wire:model="fotoNueva" accept="image/jpeg,image/png" class="w-full rounded-lg text-sm">
                <p class="text-xs text-slate-500">jpg o png, hasta 5 MB.</p>
                @error('fotoNueva') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                <div wire:loading wire:target="fotoNueva" class="text-xs text-slate-500">Subiendo la foto…</div>
                <button class="fp-btn fp-btn-primario w-fit" wire:loading.attr="disabled" wire:target="fotoNueva,subirFoto">Guardar foto</button>
            </form>
        </div>
    @endif

    <div class="fp-pestanas" role="tablist" aria-label="Secciones del catálogo">
        <button type="button" role="tab" wire:click="$set('pestana', 'lineas')" aria-selected="{{ $pestana === 'lineas' ? 'true' : 'false' }}" class="fp-pestana">Líneas</button>
        <button type="button" role="tab" wire:click="$set('pestana', 'marcas')" aria-selected="{{ $pestana === 'marcas' ? 'true' : 'false' }}" class="fp-pestana">Marcas</button>
        <button type="button" role="tab" wire:click="$set('pestana', 'temporadas')" aria-selected="{{ $pestana === 'temporadas' ? 'true' : 'false' }}" class="fp-pestana">Temporadas</button>
        <button type="button" role="tab" wire:click="$set('pestana', 'productos')" aria-selected="{{ $pestana === 'productos' ? 'true' : 'false' }}" class="fp-pestana">Productos</button>
    </div>

    @if ($pestana === 'lineas')
        <form wire:submit="guardarLinea" class="fp-formulario fp-aparecer grid max-w-xl grid-cols-1 gap-3 p-5">
            <input type="text" wire:model="linea_nombre" aria-label="Nombre de la línea" placeholder="Nombre de la línea" class="rounded-lg text-sm">
            @error('linea_nombre') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <input type="text" wire:model="linea_descripcion" aria-label="Descripción de la línea" placeholder="Descripción" class="rounded-lg text-sm">
            <button class="fp-btn fp-btn-primario w-fit">Crear línea</button>
        </form>
        <div class="fp-tarjeta fp-tabla fp-aparecer overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm">
            <thead><tr><th class="px-4 py-3 font-semibold">Nombre</th><th class="px-4 py-3 font-semibold">Temporadas</th><th class="px-4 py-3 font-semibold">Estado</th><th class="px-4 py-3 font-semibold">Acciones</th></tr></thead>
            <tbody>
                @forelse ($this->lineas as $linea)
                    <tr class="border-t border-slate-100">
                        <td class="px-4 py-3">{{ $linea->nombre }}</td>
                        <td class="px-4 py-3">{{ $linea->campanas_count }}</td>
                        <td class="px-4 py-3"><x-panel.insignia :variante="$linea->activa ? 'success' : 'neutral'" punto>{{ $linea->activa ? 'Activa' : 'Inactiva' }}</x-panel.insignia></td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <button type="button" wire:click="editar('linea', {{ $linea->id }})" class="fp-accion fp-accion-primaria">Editar</button>
                            <button type="button" wire:click="alternarActivo('linea', {{ $linea->id }})" class="fp-accion fp-accion-neutra">{{ $linea->activa ? 'Desactivar' : 'Activar' }}</button>
                            <button type="button" wire:click="pedirEliminar('linea', {{ $linea->id }})" class="fp-accion fp-accion-peligro">Eliminar</button>
                        </td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="4" mensaje="Aún no hay líneas." />
                @endforelse
            </tbody>
        </table></div></div>
    @elseif ($pestana === 'marcas')
        <form wire:submit="guardarMarca" class="fp-formulario fp-aparecer grid max-w-xl grid-cols-1 gap-3 p-5">
            <input type="text" wire:model="marca_nombre" placeholder="Nombre de la marca" class="rounded-lg text-sm">
            @error('marca_nombre') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <input type="text" wire:model="marca_descripcion" placeholder="Descripción" class="rounded-lg text-sm">
            <button class="fp-btn fp-btn-primario w-fit">Crear marca</button>
        </form>
        <div class="fp-tarjeta fp-tabla fp-aparecer overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm">
            <thead><tr><th class="px-4 py-3 font-semibold">Nombre</th><th class="px-4 py-3 font-semibold">Productos</th><th class="px-4 py-3 font-semibold">Estado</th><th class="px-4 py-3 font-semibold">Acciones</th></tr></thead>
            <tbody>
                @forelse ($this->marcas as $marca)
                    <tr class="border-t border-slate-100">
                        <td class="px-4 py-3">{{ $marca->nombre }}</td>
                        <td class="px-4 py-3">{{ $marca->productos_count }}</td>
                        <td class="px-4 py-3"><x-panel.insignia :variante="$marca->activa ? 'success' : 'neutral'" punto>{{ $marca->activa ? 'Activa' : 'Inactiva' }}</x-panel.insignia></td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <button type="button" wire:click="editar('marca', {{ $marca->id }})" class="fp-accion fp-accion-primaria">Editar</button>
                            <button type="button" wire:click="alternarActivo('marca', {{ $marca->id }})" class="fp-accion fp-accion-neutra">{{ $marca->activa ? 'Desactivar' : 'Activar' }}</button>
                            <button type="button" wire:click="pedirEliminar('marca', {{ $marca->id }})" class="fp-accion fp-accion-peligro">Eliminar</button>
                        </td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="4" mensaje="Aún no hay marcas." />
                @endforelse
            </tbody>
        </table></div></div>
    @elseif ($pestana === 'temporadas')
        <form wire:submit="guardarTemporada" class="fp-formulario fp-aparecer grid max-w-xl grid-cols-1 gap-3 p-5">
            <select wire:model="temporada_linea_id" class="rounded-lg text-sm">
                <option value="">Línea</option>
                @foreach ($this->lineas as $linea)
                    <option value="{{ $linea->id }}">{{ $linea->nombre }}</option>
                @endforeach
            </select>
            @error('temporada_linea_id') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <input type="text" wire:model="temporada_nombre" placeholder="Nombre de la temporada" class="rounded-lg text-sm">
            @error('temporada_nombre') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <select wire:model="temporada_estado" class="rounded-lg text-sm">
                <option value="borrador">Borrador</option>
                <option value="activa">Activa</option>
                <option value="cerrada">Cerrada</option>
            </select>
            @error('estado') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <button class="fp-btn fp-btn-primario w-fit">Crear temporada</button>
        </form>
        <div class="fp-tarjeta fp-tabla fp-aparecer overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm">
            <thead><tr><th class="px-4 py-3 font-semibold">Temporada</th><th class="px-4 py-3 font-semibold">Línea</th><th class="px-4 py-3 font-semibold">Productos</th><th class="px-4 py-3 font-semibold">Estado</th><th class="px-4 py-3 font-semibold">Acciones</th></tr></thead>
            <tbody>
                @forelse ($this->temporadas as $temporada)
                    <tr class="border-t border-slate-100">
                        <td class="px-4 py-3">{{ $temporada->nombre }}</td>
                        <td class="px-4 py-3">{{ $temporada->linea?->nombre }}</td>
                        <td class="px-4 py-3">{{ $temporada->productos_campana_count }}</td>
                        <td class="px-4 py-3"><x-ui.insignia-estado :estado="$temporada->estado" /></td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <button type="button" wire:click="editar('temporada', {{ $temporada->id }})" class="fp-accion fp-accion-primaria">Editar</button>
                            <button type="button" wire:click="pedirEliminar('temporada', {{ $temporada->id }})" class="fp-accion fp-accion-peligro">Eliminar</button>
                        </td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="5" mensaje="Aún no hay temporadas." />
                @endforelse
            </tbody>
        </table></div></div>

    @else
        <form wire:submit="guardarProducto" class="fp-formulario fp-aparecer grid max-w-xl grid-cols-1 gap-3 p-5">
            <select wire:model="producto_marca_id" class="rounded-lg text-sm">
                <option value="">Marca</option>
                @foreach ($this->marcas as $marca)
                    <option value="{{ $marca->id }}">{{ $marca->nombre }}</option>
                @endforeach
            </select>
            @error('producto_marca_id') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <select wire:model="producto_categoria_id" class="rounded-lg text-sm">
                <option value="">Categoría</option>
                @foreach ($this->categorias as $categoria)
                    <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                @endforeach
            </select>
            @error('producto_categoria_id') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <input type="text" wire:model="producto_modelo" placeholder="Modelo" class="rounded-lg text-sm">
            @error('producto_modelo') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <input type="text" wire:model="producto_nombre" placeholder="Nombre" class="rounded-lg text-sm">
            @error('producto_nombre') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <select wire:model="producto_campana_id" class="rounded-lg text-sm">
                <option value="">Temporada</option>
                @foreach ($this->temporadas as $temporada)
                    <option value="{{ $temporada->id }}">{{ $temporada->linea?->nombre }} — {{ $temporada->nombre }}</option>
                @endforeach
            </select>
            @error('producto_campana_id') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <input type="text" wire:model="producto_codigo" placeholder="Código de catálogo" class="rounded-lg text-sm">
            @error('producto_codigo') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <input type="number" step="0.01" wire:model="producto_precio" placeholder="Precio de menudeo" class="rounded-lg text-sm">
            @error('producto_precio') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            <button class="fp-btn fp-btn-primario w-fit">Publicar producto</button>
        </form>
        <div class="fp-tarjeta fp-tabla fp-aparecer overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm">
            <thead>
                <tr>
                    <th class="px-4 py-3 font-semibold">Producto</th>
                    <th class="px-4 py-3 font-semibold">Marca</th>
                    <th class="px-4 py-3 font-semibold">Temporada</th>
                    <th class="px-4 py-3 font-semibold">Código</th>
                    <th class="px-4 py-3 font-semibold">Menudeo</th>
                    <th class="px-4 py-3 font-semibold">Estado</th>
                    <th class="px-4 py-3 font-semibold">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->productos as $fila)
                    <tr class="border-t border-slate-100">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <x-ui.imagen-ampliable :url="$this->fotos[$fila->id] ?? null" :alt="$fila->producto?->nombre ?? 'Producto'" />
                                <span>{{ $fila->producto?->nombre }} <span class="text-slate-400">{{ $fila->producto?->modelo }}</span></span>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ $fila->producto?->marca?->nombre }}</td>
                        <td class="px-4 py-3">{{ $fila->campana?->linea?->nombre }} — {{ $fila->campana?->nombre }}</td>
                        <td class="px-4 py-3">{{ $fila->codigo_catalogo }}</td>
                        <td class="px-4 py-3">${{ number_format((float) $fila->precio_publico, 2) }}</td>
                        <td class="px-4 py-3"><x-panel.insignia :variante="$fila->producto?->activo ? 'success' : 'neutral'" punto>{{ $fila->producto?->activo ? 'Activo' : 'Inactivo' }}</x-panel.insignia></td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{-- Va por publicación, no por producto: la foto es de lo que
                                 se vende en esa temporada (producto_imagenes cuelga de
                                 producto_campana_id). --}}
                            <button type="button" wire:click="abrirFotos({{ $fila->id }})" class="fp-accion">Fotos</button>
                            @if ($fila->producto)
                                <button type="button" wire:click="editar('producto', {{ $fila->producto->id }})" class="fp-accion fp-accion-primaria">Editar</button>
                                <button type="button" wire:click="alternarActivo('producto', {{ $fila->producto->id }})" class="fp-accion fp-accion-neutra">{{ $fila->producto->activo ? 'Desactivar' : 'Activar' }}</button>
                                <button type="button" wire:click="pedirEliminar('producto', {{ $fila->producto->id }})" class="fp-accion fp-accion-peligro">Eliminar</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="7" mensaje="Aún no hay productos publicados." />
                @endforelse
            </tbody>
        </table></div></div>
    @endif

    @if ($porEliminarId)
        <x-panel.modal :titulo="$porEliminarTitulo" ancho="max-w-md" cerrar="cancelarEliminar">
            <p class="text-sm text-slate-600">{{ $porEliminarAviso }}</p>
            <x-slot:pie>
                <button type="button" wire:click="cancelarEliminar" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button type="button" wire:click="confirmarEliminar" class="fp-btn fp-btn-peligro">Eliminar</button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
</div>