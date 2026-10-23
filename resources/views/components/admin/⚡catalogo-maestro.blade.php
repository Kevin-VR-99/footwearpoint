<?php

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\ProductoImagen;
use App\Models\Color;
use App\Models\Temporada;
use App\Services\Catalogo\CampanaDeTemporada;
use App\Services\Catalogo\GestionarColorAction;
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

    // Buscadores: con 128 marcas en 6 líneas las tablas ya no caben a la vista.
    // Filtran en el servidor; las métricas siguen contando todo.
    public string $buscarLinea = '';
    public string $buscarMarca = '';
    public string $buscarTemporada = '';
    public string $buscarProducto = '';

    /** Filtra las opciones de marca en los formularios de producto. */
    public string $buscarMarcaOpcion = '';

    public string $linea_nombre = '';
    public string $linea_descripcion = '';

    public string $marca_nombre = '';
    public string $marca_descripcion = '';

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

    /* TG-282: la paleta. De ella sale la lista que se le manda a la IA. */
    public string $color_nombre = '';
    public string $color_hex = '';
    public string $editar_hex = '';

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

    /**
     * Qué alta está abierta en modal ('linea', 'marca', 'producto',
     * 'color' o ''). Las temporadas son globales y se calculan por fecha:
     * no se crean ni se editan aquí. Crear y editar se hacen en la misma
     * pantalla: antes el formulario de edición salía arriba de todo y había
     * que subir hasta él, lo que se sentía como ir a otra página.
     */
    public string $creando = '';

    private const TIPOS = ['linea', 'marca', 'producto', 'color'];

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
            'producto' => Producto::findOrFail($id),
            'color' => Color::findOrFail($id),
        };
    }

    public function editar(string $tipo, int $id): void
    {
        $this->autorizar();
        $this->errorMsg = '';
        $this->resetValidation();
        $this->creando = '';

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
        $this->editar_hex = $tipo === 'color' ? (string) $registro->codigo_hex : '';
    }

    public function abrirCrear(string $tipo): void
    {
        $this->autorizar();
        abort_unless(in_array($tipo, self::TIPOS, true), 404);

        $this->cancelarEdicion();
        $this->errorMsg = '';
        $this->resetValidation();
        $this->creando = $tipo;
    }

    public function cerrarCrear(): void
    {
        $this->creando = '';
        $this->resetValidation();
    }

    public function cancelarEdicion(): void
    {
        $this->reset(
            'editando_tipo', 'editando_id', 'editar_nombre', 'editar_descripcion',
            'editar_marca_id', 'editar_categoria_id', 'editar_modelo', 'editar_estado',
            'editar_hex',
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
            'producto' => [
                'editar_nombre' => ['required', 'string', 'max:200'],
                'editar_descripcion' => ['nullable', 'string', 'max:2000'],
                'editar_marca_id' => ['required', 'exists:marcas,id'],
                'editar_categoria_id' => ['required', 'exists:categorias_producto,id'],
                'editar_modelo' => ['required', 'string', 'max:120'],
            ],
            'color' => [
                'editar_nombre' => ['required', 'string', 'max:60', Rule::unique('colores', 'nombre')->ignore($id)],
                'editar_hex' => ['nullable', 'string', 'max:7'],
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
                'producto' => app(GestionarProductoAction::class)->actualizar($registro, $comunes + [
                    'marca_id' => (int) $datos['editar_marca_id'],
                    'categoria_id' => (int) $datos['editar_categoria_id'],
                    'modelo' => $datos['editar_modelo'],
                ]),
                // La tabla de colores no tiene descripción.
                'color' => app(GestionarColorAction::class)->actualizar($registro, [
                    'nombre' => $datos['editar_nombre'],
                    'codigo_hex' => $datos['editar_hex'] ?? null,
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
                'color' => app(GestionarColorAction::class)->actualizar($registro, ['activo' => ! $registro->activo]),
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
            'producto' => [
                'Eliminar producto',
                '¿Eliminar el producto «'.$registro->nombre.'» de todo el catálogo, con sus tallas y publicaciones? Esta acción no se puede deshacer.',
            ],
            'color' => [
                'Eliminar color',
                '¿Eliminar el color «'.$registro->nombre.'» de la paleta? Solo se puede si ninguna variante lo usa; si lo usan, desactívalo.',
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
                'color' => app(GestionarColorAction::class)->eliminar($registro),
                'producto' => app(GestionarProductoAction::class)->eliminar($registro),
            };
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo eliminar.');

            return;
        }

        if ($this->editando_tipo === $tipo && $this->editando_id === $id) {
            $this->cancelarEdicion();
        }

        $nombres = ['linea' => 'Línea eliminada.', 'marca' => 'Marca eliminada.', 'producto' => 'Producto eliminado.', 'color' => 'Color eliminado de la paleta.'];
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
        $this->cerrarCrear();
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
        $this->cerrarCrear();
        $this->dispatch('guardado', mensaje: 'Marca creada.');
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
        $this->cerrarCrear();
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

    /** Escapa los comodines de LIKE para buscar el texto tal cual. */
    private static function patron(string $texto): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($texto)).'%';
    }

    public function getLineasFiltradasProperty()
    {
        if (trim($this->buscarLinea) === '') {
            return $this->lineas;
        }

        return Linea::query()->withCount('campanas')
            ->where('nombre', 'like', self::patron($this->buscarLinea))
            ->orderBy('nombre')->get();
    }

    /** Por nombre de la marca o de la línea en la que se vende. */
    public function getMarcasFiltradasProperty()
    {
        if (trim($this->buscarMarca) === '') {
            return $this->marcas;
        }
        $t = self::patron($this->buscarMarca);

        return Marca::query()->withCount('productos')
            ->where(fn ($q) => $q->where('nombre', 'like', $t)
                ->orWhereHas('lineas', fn ($l) => $l->where('nombre', 'like', $t)))
            ->orderBy('nombre')->get();
    }

    /** Opciones del selector de marca; la elegida se queda aunque no coincida. */
    public function getMarcasOpcionesProperty()
    {
        if (trim($this->buscarMarcaOpcion) === '') {
            return $this->marcas;
        }
        $t = self::patron($this->buscarMarcaOpcion);
        $elegidas = array_filter([(int) $this->producto_marca_id, (int) $this->editar_marca_id]);

        return $this->marcas->filter(fn ($m) => in_array($m->id, $elegidas, true)
            || str_contains(mb_strtolower($m->nombre), mb_strtolower(trim($this->buscarMarcaOpcion))))->values();
    }

    /**
     * Temporadas globales (solo lectura): siempre la actual y la próxima,
     * más las anteriores que tuvieron catálogo; la más reciente primero, con
     * las líneas publicadas y cuántos productos tiene cada una.
     */
    public function getTemporadasGlobalesProperty()
    {
        [$actual, $siguiente] = app(CampanaDeTemporada::class)->elegibles();

        return Temporada::query()
            ->where(fn ($q) => $q->whereIn('id', [$actual->id, $siguiente->id])->orWhereHas('campanas'))
            ->with(['campanas' => fn ($c) => $c->with('linea')->withCount('productosCampana')])
            ->orderByDesc('fecha_inicio')
            ->get();
    }

    public function getTemporadasFiltradasProperty()
    {
        $texto = mb_strtolower(trim($this->buscarTemporada));
        if ($texto === '') {
            return $this->temporadasGlobales;
        }

        return $this->temporadasGlobales->filter(fn (Temporada $t) => str_contains(mb_strtolower($t->nombre), $texto)
            || $t->campanas->contains(fn ($c) => str_contains(mb_strtolower((string) $c->linea?->nombre), $texto)))->values();
    }

    /** Busca en todo el catálogo (no solo en las últimas 50) por nombre, modelo, marca, código o línea. */
    public function getProductosFiltradosProperty()
    {
        if (trim($this->buscarProducto) === '') {
            return $this->productos;
        }
        $t = self::patron($this->buscarProducto);

        return ProductoCampana::query()
            ->with(['producto.marca', 'campana.linea'])
            ->where(fn ($q) => $q->where('codigo_catalogo', 'like', $t)
                ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', $t)->orWhere('modelo', 'like', $t)
                    ->orWhereHas('marca', fn ($m) => $m->where('nombre', 'like', $t)))
                ->orWhereHas('campana', fn ($c) => $c->where('nombre', 'like', $t)
                    ->orWhereHas('linea', fn ($l) => $l->where('nombre', 'like', $t))))
            ->latest('id')
            ->limit(50)
            ->get();
    }

    public function getProductosProperty()
    {
        return ProductoCampana::query()
            ->with(['producto.marca', 'campana.linea'])
            ->latest('id')
            ->limit(50)
            ->get();
    }

    /**
     * TG-282 — Un color nuevo en la paleta.
     *
     * Esto no es solo para los formularios: la paleta es la lista que se le
     * manda a la IA al leer un catálogo, así que agregar un color aquí hace
     * que la próxima importación lo reconozca sola. Con los ocho sembrados,
     * "MALVA" y "MAQUILLAJE" no tenían a dónde ir.
     */
    public function guardarColor(): void
    {
        $this->autorizar();
        $this->errorMsg = '';

        $datos = $this->validate([
            'color_nombre' => ['required', 'string', 'max:60', Rule::unique('colores', 'nombre')],
            'color_hex'    => ['nullable', 'string', 'max:7'],
        ], [
            'color_nombre.required' => 'El nombre es obligatorio.',
            'color_nombre.unique'   => 'Ese color ya está en la paleta.',
        ]);

        try {
            app(GestionarColorAction::class)->crear([
                'nombre'     => $datos['color_nombre'],
                'codigo_hex' => $datos['color_hex'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo crear el color.');

            return;
        }

        $this->reset('color_nombre', 'color_hex');
        $this->cerrarCrear();
        $this->dispatch('guardado', mensaje: 'Color agregado a la paleta.');
    }

    public function getColoresProperty()
    {
        return Color::query()->orderBy('nombre')->get();
    }

    /** @return array<int, string> producto_campana_id => url */
    /**
     * Las fotos de lo que SE ESTÁ VIENDO, no de las últimas 50.
     *
     * La tabla dibuja productosFiltrados; si esto se queda con productos, al
     * buscar algo que no esté entre las últimas 50 publicaciones el renglón
     * sale sin miniatura aunque el producto sí tenga foto. Pasó con los DÚO
     * del Cklass: la ficha enseñaba la foto y la lista no.
     */
    public function getFotosProperty(): array
    {
        return app(ImagenDeProducto::class)->porProductoCampana($this->productosFiltrados->pluck('id'));
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
        $this->creando = '';
        $this->cancelarEdicion();
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
        <x-panel.metrica titulo="Temporadas" :valor="$this->temporadasGlobales->count()" tono="aviso" :detalle="'Actual: '.Temporada::actual()->nombre"
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
            $titulos = ['linea' => 'Editar línea', 'marca' => 'Editar marca', 'producto' => 'Editar producto', 'color' => 'Editar color'];
        @endphp
        <x-panel.modal :titulo="$titulos[$editando_tipo] ?? 'Editar'" cerrar="cancelarEdicion" formulario="guardarEdicion" data-edicion-catalogo>
            <div class="grid grid-cols-1 gap-3">
                @if ($errorMsg !== '')
                    <x-panel.alerta tipo="error">{{ $errorMsg }}</x-panel.alerta>
                @endif
            <input type="text" wire:model="editar_nombre" aria-label="Nombre" placeholder="Nombre" class="rounded-lg text-sm">
            @if ($editando_tipo === 'color')
                <input type="text" wire:model="editar_hex" placeholder="Color en hexadecimal, por ejemplo #7E22CE" class="rounded-lg text-sm">
                @error('editar_hex') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            @endif
            @error('editar_nombre') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            @if ($editando_tipo === 'producto')
                <input type="text" wire:model="editar_modelo" placeholder="Modelo" class="rounded-lg text-sm">
                @error('editar_modelo') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                <x-ui.buscador wire:model.live.debounce.300ms="buscarMarcaOpcion" placeholder="Filtrar marcas…" etiqueta="Filtrar la lista de marcas" data-buscador-opciones="editar_marca_id" />
                <select wire:model="editar_marca_id" aria-label="Marca" class="rounded-lg text-sm">
                    <option value="">Marca</option>
                    @foreach ($this->marcasOpciones as $marca)
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
            <input type="text" wire:model="editar_descripcion" placeholder="Descripción" class="rounded-lg text-sm">
            @error('editar_descripcion') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            </div>
            <x-slot:pie>
                <button type="button" wire:click="cancelarEdicion" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button class="fp-btn fp-btn-primario">Guardar cambios</button>
            </x-slot:pie>
        </x-panel.modal>
    @endif

    {{-- E5-05: las fotos del catálogo. Hasta ahora las pantallas las
         enseñaban pero no había forma de ponerlas: solo existía la ruta del
         API, así que las únicas fotos eran las de la semilla. Son del
         catálogo compartido, por eso las pone el admin general y las ven
         todas las distribuidoras y la app. --}}
    @if ($fotosDe !== null)
        <x-panel.modal :titulo="'Fotos de '.$fotosDeNombre" subtitulo="La primera que subas queda como principal." ancho="max-w-xl" cerrar="cerrarFotos" data-fotos-publicacion>
            <div class="space-y-4">

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
            <x-slot:pie>
                <button type="button" wire:click="cerrarFotos" class="fp-btn fp-btn-secundario">Cerrar</button>
            </x-slot:pie>
        </x-panel.modal>
    @endif

    <div class="fp-pestanas" role="tablist" aria-label="Secciones del catálogo">
        <button type="button" role="tab" wire:click="$set('pestana', 'lineas')" aria-selected="{{ $pestana === 'lineas' ? 'true' : 'false' }}" class="fp-pestana">Líneas</button>
        <button type="button" role="tab" wire:click="$set('pestana', 'marcas')" aria-selected="{{ $pestana === 'marcas' ? 'true' : 'false' }}" class="fp-pestana">Marcas</button>
        <button type="button" role="tab" wire:click="$set('pestana', 'temporadas')" aria-selected="{{ $pestana === 'temporadas' ? 'true' : 'false' }}" class="fp-pestana">Temporadas</button>
        <button type="button" role="tab" wire:click="$set('pestana', 'productos')" aria-selected="{{ $pestana === 'productos' ? 'true' : 'false' }}" class="fp-pestana">Productos</button>
        <button type="button" role="tab" wire:click="$set('pestana', 'colores')" aria-selected="{{ $pestana === 'colores' ? 'true' : 'false' }}" class="fp-pestana">Colores</button>
    </div>

    @if ($pestana === 'lineas')
        <button type="button" wire:click="abrirCrear('linea')" class="fp-btn fp-btn-primario w-fit" aria-haspopup="dialog">Crear línea</button>
        <x-ui.buscador wire:model.live.debounce.300ms="buscarLinea" placeholder="Buscar línea…" etiqueta="Buscar líneas" class="w-full sm:w-80" data-buscador-catalogo="buscarLinea" />
        <div class="fp-tarjeta fp-tabla fp-aparecer overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm">
            <thead><tr><th class="px-4 py-3 font-semibold">Nombre</th><th class="px-4 py-3 font-semibold">Temporadas</th><th class="px-4 py-3 font-semibold">Estado</th><th class="px-4 py-3 font-semibold">Acciones</th></tr></thead>
            <tbody>
                @forelse ($this->lineasFiltradas as $linea)
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
                    <x-panel.vacio colspan="4" :mensaje="trim($buscarLinea) !== '' ? 'Sin coincidencias.' : 'Aún no hay líneas.'" />
                @endforelse
            </tbody>
        </table></div></div>
    @elseif ($pestana === 'marcas')
        <button type="button" wire:click="abrirCrear('marca')" class="fp-btn fp-btn-primario w-fit" aria-haspopup="dialog">Crear marca</button>
        <x-ui.buscador wire:model.live.debounce.300ms="buscarMarca" placeholder="Buscar marca o línea…" etiqueta="Buscar marcas por nombre o línea" class="w-full sm:w-80" data-buscador-catalogo="buscarMarca" />
        <div class="fp-tarjeta fp-tabla fp-aparecer overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm">
            <thead><tr><th class="px-4 py-3 font-semibold">Nombre</th><th class="px-4 py-3 font-semibold">Productos</th><th class="px-4 py-3 font-semibold">Estado</th><th class="px-4 py-3 font-semibold">Acciones</th></tr></thead>
            <tbody>
                @forelse ($this->marcasFiltradas as $marca)
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
                    <x-panel.vacio colspan="4" :mensaje="trim($buscarMarca) !== '' ? 'Sin coincidencias.' : 'Aún no hay marcas.'" />
                @endforelse
            </tbody>
        </table></div></div>
    @elseif ($pestana === 'temporadas')
        <p class="text-sm text-slate-600">Hay dos temporadas por año, iguales para todas las líneas: Primavera-Verano (1 de enero al 30 de junio) y Otoño-Invierno (1 de julio al 31 de diciembre). Cambian solas por fecha; solo se ve el catálogo de la temporada actual. El de la próxima se puede importar antes y queda oculto hasta que empiece.</p>
        <x-ui.buscador wire:model.live.debounce.300ms="buscarTemporada" placeholder="Buscar temporada o línea…" etiqueta="Buscar temporadas" class="w-full sm:w-80" data-buscador-catalogo="buscarTemporada" />
        <div class="fp-tarjeta fp-tabla fp-aparecer overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm">
            <thead><tr><th class="px-4 py-3 font-semibold">Temporada</th><th class="px-4 py-3 font-semibold">Vigencia</th><th class="px-4 py-3 font-semibold">Líneas</th><th class="px-4 py-3 font-semibold">Productos</th><th class="px-4 py-3 font-semibold">Estado</th></tr></thead>
            <tbody>
                @forelse ($this->temporadasFiltradas as $temporada)
                    <tr class="border-t border-slate-100" data-temporada-global="{{ $temporada->vigencia() }}">
                        <td class="px-4 py-3">{{ $temporada->nombre }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $temporada->fecha_inicio->format('d/m/Y') }} – {{ $temporada->fecha_fin->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">{{ $temporada->campanas->map(fn ($c) => $c->linea?->nombre)->filter()->sort()->join(', ') ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $temporada->campanas->sum('productos_campana_count') }}</td>
                        <td class="px-4 py-3">{{ $temporada->etiquetaVigencia() }}</td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="5" :mensaje="trim($buscarTemporada) !== '' ? 'Sin coincidencias.' : 'Aún no hay temporadas.'" />
                @endforelse
            </tbody>
        </table></div></div>

    @else
        <button type="button" wire:click="abrirCrear('producto')" class="fp-btn fp-btn-primario w-fit" aria-haspopup="dialog">Publicar producto</button>
        <x-ui.buscador wire:model.live.debounce.300ms="buscarProducto" placeholder="Producto, modelo, marca, línea o código…" etiqueta="Buscar productos publicados" class="w-full sm:w-80" data-buscador-catalogo="buscarProducto" />
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
                @forelse ($this->productosFiltrados as $fila)
                    <tr class="border-t border-slate-100">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <x-ui.imagen-ampliable :url="$this->fotos[$fila->id] ?? null" :alt="$fila->producto?->nombre ?? 'Producto'" />
                                <span>{{ $fila->producto?->nombre }} <span class="text-slate-500">{{ $fila->producto?->modelo }}</span></span>
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
                    <x-panel.vacio colspan="7" :mensaje="trim($buscarProducto) !== '' ? 'Sin coincidencias.' : 'Aún no hay productos publicados.'" />
                @endforelse
            </tbody>
        </table></div></div>
    @endif

    {{-- TG-282: la paleta. No es solo para los formularios: de esta lista
         sale lo que se le manda a la IA al leer un catálogo, así que un color
         que falte aquí es un color que ella no va a poder asignar. --}}
    @if ($pestana === 'colores')
        <button type="button" wire:click="abrirCrear('color')" class="fp-btn fp-btn-primario w-fit" aria-haspopup="dialog">Agregar color</button>

        <div class="fp-tarjeta fp-tabla fp-aparecer overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm">
            <thead>
                <tr>
                    <th class="px-4 py-3 font-semibold">Color</th>
                    <th class="px-4 py-3 font-semibold">Hexadecimal</th>
                    <th class="px-4 py-3 font-semibold">Estado</th>
                    <th class="px-4 py-3 font-semibold">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->colores as $color)
                    <tr class="border-t border-slate-100">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="inline-block h-5 w-5 shrink-0 rounded-full ring-1 ring-slate-300"
                                    style="background: {{ $color->codigo_hex ?: '#FFFFFF' }}"></span>
                                <span>{{ $color->nombre }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ $color->codigo_hex ?: '—' }}</td>
                        <td class="px-4 py-3"><x-panel.insignia :variante="$color->activo ? 'success' : 'neutral'" punto>{{ $color->activo ? 'Activo' : 'Inactivo' }}</x-panel.insignia></td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <button type="button" wire:click="editar('color', {{ $color->id }})" class="fp-accion fp-accion-primaria">Editar</button>
                            <button type="button" wire:click="alternarActivo('color', {{ $color->id }})" class="fp-accion fp-accion-neutra">{{ $color->activo ? 'Desactivar' : 'Activar' }}</button>
                            <button type="button" wire:click="pedirEliminar('color', {{ $color->id }})" class="fp-accion fp-accion-peligro">Eliminar</button>
                        </td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="4" mensaje="La paleta está vacía." />
                @endforelse
            </tbody>
        </table></div></div>
    @endif

    @if ($creando === 'linea')
        <x-panel.modal titulo="Nueva línea" cerrar="cerrarCrear" formulario="guardarLinea" data-alta-catalogo="linea">
            <div class="grid grid-cols-1 gap-3">
                <input type="text" wire:model="linea_nombre" aria-label="Nombre de la línea" placeholder="Nombre de la línea" class="rounded-lg text-sm">
                @error('linea_nombre') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                <input type="text" wire:model="linea_descripcion" aria-label="Descripción de la línea" placeholder="Descripción" class="rounded-lg text-sm">
            </div>
            <x-slot:pie>
                <button type="button" wire:click="cerrarCrear" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button class="fp-btn fp-btn-primario">Crear línea</button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
    @if ($creando === 'marca')
        <x-panel.modal titulo="Nueva marca" cerrar="cerrarCrear" formulario="guardarMarca" data-alta-catalogo="marca">
            <div class="grid grid-cols-1 gap-3">
                <input type="text" wire:model="marca_nombre" placeholder="Nombre de la marca" class="rounded-lg text-sm">
                @error('marca_nombre') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                <input type="text" wire:model="marca_descripcion" placeholder="Descripción" class="rounded-lg text-sm">
            </div>
            <x-slot:pie>
                <button type="button" wire:click="cerrarCrear" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button class="fp-btn fp-btn-primario">Crear marca</button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
    @if ($creando === 'producto')
        <x-panel.modal titulo="Nuevo producto" cerrar="cerrarCrear" formulario="guardarProducto" data-alta-catalogo="producto">
            <div class="grid grid-cols-1 gap-3">
                <x-ui.buscador wire:model.live.debounce.300ms="buscarMarcaOpcion" placeholder="Filtrar marcas…" etiqueta="Filtrar la lista de marcas" data-buscador-opciones="producto_marca_id" />
                <select wire:model="producto_marca_id" aria-label="Marca" class="rounded-lg text-sm">
                    <option value="">Marca</option>
                    @foreach ($this->marcasOpciones as $marca)
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
            </div>
            <x-slot:pie>
                <button type="button" wire:click="cerrarCrear" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button class="fp-btn fp-btn-primario">Publicar producto</button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
    @if ($creando === 'color')
        <x-panel.modal titulo="Nuevo color" cerrar="cerrarCrear" formulario="guardarColor" data-alta-catalogo="color">
            <div class="grid grid-cols-1 gap-3">
                <p class="text-sm text-slate-600">
                    Esta paleta es la que se le ofrece a la IA cuando lee un catálogo: solo puede
                    elegir de aquí. Si falta un color, lo deja sin resolver y hay que decidirlo a
                    mano en cada importación.
                </p>
                <input type="text" wire:model="color_nombre" aria-label="Nombre del color" placeholder="Nombre del color, por ejemplo Marino" class="rounded-lg text-sm">
                @error('color_nombre') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                <input type="text" wire:model="color_hex" aria-label="Color en hexadecimal" placeholder="Hexadecimal (opcional), por ejemplo #1E293B" class="rounded-lg text-sm">
                @error('color_hex') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
            </div>
            <x-slot:pie>
                <button type="button" wire:click="cerrarCrear" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button class="fp-btn fp-btn-primario">Agregar color</button>
            </x-slot:pie>
        </x-panel.modal>
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