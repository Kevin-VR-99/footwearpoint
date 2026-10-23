<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Variante;
use App\Services\Catalogo\ImagenDeProducto;
use App\Services\Stock\StockService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use App\Models\StockLocal;
use App\Services\Stock\ImportarEntradasService;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.panel')] #[Title('Stock local — FootwearPoint')] class extends Component
{
    use WithPagination;
    use WithFileUploads;

    /** Pestaña de la tarjeta de entrada: variante, modelo (todas las tallas) o importar. */
    public string $tabEntrada = 'variante';

    // Entrada por modelo: producto + color y una cantidad por talla.
    public string $modelo_producto_id = '';
    public string $modelo_color_id = '';
    /** Filtra el selector de modelo por nombre, modelo, marca, línea, SKU o código de catálogo. */
    public string $buscarModelo = '';
    /** @var array<int|string, string> variante_id => cantidad */
    public array $modelo_cantidades = [];
    public string $modelo_motivo = '';
    /** @var list<array{talla:string, sku:string, cantidad:int, antes:int, despues:int}> */
    public array $resumenModelo = [];

    // Importar desde Excel/CSV
    public $archivo = null;
    public ?array $vistaPrevia = null;
    public string $errorImportacion = '';

    // Tabla agrupada por producto + color
    /** @var list<string> claves "producto-color" abiertas */
    public array $expandidos = [];

    // Modal de movimientos
    public ?int $movimientosStockId = null;
    public int $movimientosPagina = 1;

    // Entrada de mercancía (E6-01)
    public string $entrada_variante_id = '';
    public string $entrada_cantidad = '';
    public string $entrada_motivo = '';

    // Ajuste manual (E6-02): el motivo es obligatorio
    public string $ajuste_variante_id = '';
    public string $ajuste_tipo = 'ajuste_negativo';
    public string $ajuste_cantidad = '';
    public string $ajuste_motivo = '';

    #[\Livewire\Attributes\Url(except: '')]
    public string $busqueda = '';

    /** Existencias es esta pantalla. Conteo es el inventario físico, si el permiso lo permite. */
    #[\Livewire\Attributes\Url(as: 'pestana', except: 'existencias')]
    public string $pestana = 'existencias';

    /** Alerta de stock bajo: filtro y edición del mínimo propio de la variante. */
    public bool $soloBajo = false;
    public ?int $editandoMinimoId = null;
    public string $minimoNuevo = '';

    public string $mensaje = '';
    public string $errorMsg = '';

    public function mount()
    {
        if (! Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }

        $this->normalizarPestana();
    }

    public function updatedPestana(): void
    {
        $this->normalizarPestana();
    }

    /** Permiso fino «Inventario físico (conteos)» (prendido por omisión). */
    public function getPuedeContarProperty(): bool
    {
        return app(\App\Services\Auth\PermisoModuloService::class)
            ->puede(Auth::user(), \App\Services\Auth\PermisoModuloService::INVENTARIO_CONTEO);
    }

    private function normalizarPestana(): void
    {
        $permitidas = ['existencias'];

        if ($this->puedeContar) {
            $permitidas[] = 'conteo';
        }

        if (! in_array($this->pestana, $permitidas, true)) {
            $this->pestana = 'existencias';
        }
    }

    /**
     * Toda la lógica vive en StockService, el mismo que usa la API
     * (convención 1.5: la vista muestra, el servicio decide).
     */
    /**
     * Grupos producto + color de esta sucursal, paginados (25 por página).
     * Busca por SKU, nombre o modelo; "solo bajo" deja los grupos con alguna
     * talla por debajo de su mínimo.
     */
    public function getGruposProperty()
    {
        try {
            $general = $this->minimoGeneral;
            $query = app(StockService::class)->consultar()
                ->reorder()
                ->setEagerLoads([])
                ->join('variantes', 'variantes.id', '=', 'stock_local.variante_id')
                ->join('productos', 'productos.id', '=', 'variantes.producto_id')
                ->leftJoin('colores', 'colores.id', '=', 'variantes.color_id')
                ->groupBy('variantes.producto_id', 'variantes.color_id')
                ->selectRaw('variantes.producto_id, variantes.color_id, MIN(productos.nombre) as producto_nombre, MIN(productos.modelo) as producto_modelo,
                    MIN(COALESCE(NULLIF(variantes.nombre_color_comercial, ""), colores.nombre)) as color_nombre,
                    SUM(stock_local.cantidad_disponible) as total, COUNT(*) as tallas,
                    SUM(CASE WHEN stock_local.cantidad_disponible < (CASE WHEN stock_local.stock_minimo > 0 THEN stock_local.stock_minimo ELSE ? END) THEN 1 ELSE 0 END) as bajos', [$general])
                ->orderBy('producto_nombre')
                ->orderBy('color_nombre');

            if ($this->busqueda !== '') {
                $termino = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $this->busqueda).'%';
                $query->where(fn ($q) => $q->where('variantes.sku', 'like', $termino)
                    ->orWhere('productos.nombre', 'like', $termino)
                    ->orWhere('productos.modelo', 'like', $termino));
            }

            if ($this->soloBajo) {
                $query->havingRaw('bajos > 0');
            }

            return $query->paginate(25);
        } catch (OperacionInvalidaException $e) {
            return new LengthAwarePaginator([], 0, 25);
        }
    }

    /** Existencias (por talla) de los grupos de la página, en orden de talla. */
    public function getFilasPorGrupoProperty(): array
    {
        $grupos = collect($this->grupos->items());

        if ($grupos->isEmpty()) {
            return [];
        }

        $filas = app(StockService::class)->consultar()
            ->reorder()
            ->whereHas('variante', fn ($q) => $q->whereIn('producto_id', $grupos->pluck('producto_id')->unique()))
            ->with('variante.producto:id,nombre')
            ->get()
            ->tap(fn ($filas) => app(\App\Support\Cache\CatalogoCache::class)->asignarTallaYColor($filas->pluck('variante')))
            ->sortBy(fn ($s) => sprintf('%08d-%s', (int) ($s->variante?->talla?->orden ?? 0), $s->variante?->talla?->valor))
            ->groupBy(fn ($s) => $s->variante?->producto_id.'-'.(int) $s->variante?->color_id);

        return $filas->map->values()->all();
    }

    /** @return array<int, int> variante_id => piezas en pedidos abiertos */
    public function getEnPedidosProperty(): array
    {
        $ids = collect($this->filasPorGrupo)->flatten(1)->pluck('variante_id');

        return app(StockService::class)->enPedidosAbiertos($ids);
    }

    public function alternarGrupo(string $clave): void
    {
        $this->expandidos = in_array($clave, $this->expandidos, true)
            ? array_values(array_diff($this->expandidos, [$clave]))
            : [...$this->expandidos, $clave];
    }

    /**
     * Combobox: busca variantes por SKU (o código escaneado), nombre o modelo.
     * El SKU exacto va primero para que el Enter del lector lo elija.
     *
     * @return list<array{id:int, sku:string, etiqueta:string, detalle:string, imagen:?string}>
     */
    public function buscarVariantes(string $termino): array
    {
        $termino = trim(mb_substr($termino, 0, 80));

        if ($termino === '') {
            return [];
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $termino).'%';

        $variantes = Variante::query()
            ->with('talla:id,sistema,valor', 'color:id,nombre', 'producto:id,nombre,modelo')
            ->where(fn ($q) => $q->where('sku', 'like', $like)
                ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', $like)->orWhere('modelo', 'like', $like)))
            ->orderByRaw('CASE WHEN sku = ? THEN 0 WHEN sku LIKE ? THEN 1 ELSE 2 END', [$termino, $termino.'%'])
            ->orderBy('sku')
            ->limit(20)
            ->get();

        $fotos = app(ImagenDeProducto::class)->porProducto($variantes->pluck('producto_id'));

        return $variantes->map(fn (Variante $v) => [
            'id' => (int) $v->id,
            'sku' => (string) $v->sku,
            'etiqueta' => $v->sku.' — '.$this->descripcionVariante($v),
            'detalle' => $this->descripcionVariante($v),
            'imagen' => $fotos[(int) $v->producto_id] ?? null,
        ])->all();
    }

    private function descripcionVariante(Variante $v): string
    {
        return implode(' · ', array_filter([
            $v->producto?->nombre,
            $v->talla ? 'Talla '.$v->talla->valor : null,
            $v->nombre_color_comercial ?: $v->color?->nombre,
        ]));
    }

    // ---------- Entrada por modelo ----------

    /** Productos que tienen variantes, para el selector de modelo. */
    public function getProductosModeloProperty()
    {
        $termino = trim($this->buscarModelo);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $termino).'%';

        return \App\Models\Producto::query()
            ->whereHas('variantes')
            ->when($termino !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nombre', 'like', $like)
                ->orWhere('modelo', 'like', $like)
                ->orWhereHas('marca', fn ($m) => $m->where('nombre', 'like', $like))
                ->orWhereHas('variantes', fn ($v) => $v->where('sku', 'like', $like))
                ->orWhereHas('publicacionesCampana', fn ($p) => $p->where('codigo_catalogo', 'like', $like)
                    ->orWhereHas('campana.linea', fn ($l) => $l->where('nombre', 'like', $like)))
                // El modelo ya elegido no desaparece del selector al seguir escribiendo.
                ->when($this->modelo_producto_id !== '', fn ($o) => $o->orWhere('id', (int) $this->modelo_producto_id))))
            ->with('marca:id,nombre')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'modelo', 'marca_id']);
    }

    public function getColoresModeloProperty()
    {
        if ($this->modelo_producto_id === '') {
            return collect();
        }

        return Variante::query()
            ->where('producto_id', (int) $this->modelo_producto_id)
            ->with('color:id,nombre')
            ->get(['id', 'color_id', 'nombre_color_comercial'])
            ->unique('color_id')
            ->map(fn ($v) => ['id' => (int) $v->color_id, 'nombre' => $v->nombre_color_comercial ?: ($v->color?->nombre ?? 'Sin color')])
            ->values();
    }

    /** Tallas (variantes) del producto + color, con su existencia actual. */
    public function getTallasModeloProperty()
    {
        if ($this->modelo_producto_id === '' || $this->modelo_color_id === '') {
            return collect();
        }

        $variantes = Variante::query()
            ->where('producto_id', (int) $this->modelo_producto_id)
            ->where('color_id', (int) $this->modelo_color_id)
            ->with('talla:id,sistema,valor,orden')
            ->get(['id', 'sku', 'talla_id'])
            ->sortBy(fn ($v) => sprintf('%08d-%s', (int) ($v->talla?->orden ?? 0), $v->talla?->valor))
            ->values();

        try {
            $existencias = app(StockService::class)->consultar()->reorder()->setEagerLoads([])
                ->whereIn('variante_id', $variantes->pluck('id'))->pluck('cantidad_disponible', 'variante_id');
        } catch (OperacionInvalidaException) {
            $existencias = collect();
        }

        return $variantes->map(fn ($v) => [
            'id' => (int) $v->id,
            'sku' => $v->sku,
            'talla' => $v->talla?->valor ?? '—',
            'existencia' => (int) ($existencias[$v->id] ?? 0),
        ]);
    }

    public function updatedModeloProductoId(): void
    {
        $this->modelo_color_id = '';
        $this->modelo_cantidades = [];
        $colores = $this->coloresModelo;
        if ($colores->count() === 1) {
            $this->modelo_color_id = (string) $colores->first()['id'];
        }
    }

    public function updatedModeloColorId(): void
    {
        $this->modelo_cantidades = [];
    }

    public function registrarEntradaModelo(StockService $stock): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';
        $this->resumenModelo = [];

        $this->validate([
            'modelo_producto_id' => ['required', 'integer'],
            'modelo_color_id' => ['required', 'integer'],
            'modelo_cantidades' => ['array'],
            'modelo_cantidades.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'modelo_motivo' => ['nullable', 'string', 'max:300'],
        ], [
            'modelo_producto_id.required' => 'Elige el modelo.',
            'modelo_color_id.required' => 'Elige el color.',
            'modelo_cantidades.*.integer' => 'Las cantidades deben ser números enteros.',
            'modelo_cantidades.*.min' => 'Las cantidades no pueden ser negativas.',
        ]);

        // Solo tallas que pertenecen al modelo y color elegidos.
        $tallas = $this->tallasModelo->keyBy('id');
        $cantidades = collect($this->modelo_cantidades)
            ->filter(fn ($c, $id) => $tallas->has((int) $id) && (int) $c > 0)
            ->mapWithKeys(fn ($c, $id) => [(int) $id => (int) $c])
            ->all();

        if ($cantidades === []) {
            $this->addError('modelo_cantidades', 'Escribe la cantidad de al menos una talla.');

            return;
        }

        try {
            $movimientos = $stock->registrarEntradas($cantidades, $this->modelo_motivo !== '' ? $this->modelo_motivo : null);

            $this->resumenModelo = collect($movimientos)->map(fn ($m) => [
                'talla' => $tallas[$m->stockLocal->variante_id]['talla'],
                'sku' => $tallas[$m->stockLocal->variante_id]['sku'],
                'cantidad' => (int) $m->cantidad,
                'antes' => (int) $m->existencia_anterior,
                'despues' => (int) $m->existencia_posterior,
            ])->all();

            $piezas = array_sum($cantidades);
            $this->mensaje = 'Entrada registrada: '.$piezas.' '.($piezas === 1 ? 'pieza' : 'piezas').' en '.count($movimientos).' '.(count($movimientos) === 1 ? 'talla' : 'tallas').'.';
            $this->modelo_cantidades = [];
            $this->modelo_motivo = '';
        } catch (OperacionInvalidaException $e) {
            $this->errorMsg = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $this->errorMsg = 'No se pudo registrar la entrada. Revisa los datos e intenta de nuevo.';
        }
    }

    // ---------- Importar desde Excel/CSV ----------

    public function updatedArchivo(): void
    {
        $this->vistaPrevia = null;
        $this->errorImportacion = '';
        $this->mensaje = '';

        $this->validate(['archivo' => ['required', 'file', 'max:'.ImportarEntradasService::MAX_KB]], [
            'archivo.max' => 'El archivo pesa más de 2 MB.',
        ]);

        try {
            $this->vistaPrevia = app(ImportarEntradasService::class)->previsualizar($this->archivo);
        } catch (OperacionInvalidaException $e) {
            $this->errorImportacion = $e->getMessage();
        }
    }

    public function aplicarImportacion(): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        if ($this->archivo === null) {
            $this->errorImportacion = 'Sube primero el archivo.';

            return;
        }

        try {
            // Se vuelve a leer y validar el archivo en el servidor: la vista
            // previa del navegador no se toma como verdad.
            $servicio = app(ImportarEntradasService::class);
            $previa = $servicio->previsualizar($this->archivo);

            if ($previa['errores'] > 0) {
                $this->vistaPrevia = $previa;
                $this->errorImportacion = 'Corrige las filas con error antes de aplicar.';

                return;
            }

            $movimientos = $servicio->aplicar($previa['lineas'], $this->archivo->getClientOriginalName());
            $piezas = array_sum(array_column($previa['lineas'], 'cantidad'));
            $this->mensaje = 'Importación aplicada: '.$piezas.' piezas en '.count($movimientos).' variantes.';
            $this->cancelarImportacion();
        } catch (OperacionInvalidaException $e) {
            $this->errorImportacion = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $this->errorImportacion = 'No se pudo aplicar la importación. No se registró ninguna entrada.';
        }
    }

    public function cancelarImportacion(): void
    {
        $this->archivo = null;
        $this->vistaPrevia = null;
        $this->errorImportacion = '';
    }

    // ---------- Movimientos de una variante ----------

    public function verMovimientos(int $stockId): void
    {
        $this->movimientosStockId = $stockId;
        $this->movimientosPagina = 1;
    }

    public function cerrarMovimientos(): void
    {
        $this->movimientosStockId = null;
    }

    public function paginaMovimientos(int $pagina): void
    {
        $this->movimientosPagina = max(1, $pagina);
    }

    public function getMovimientosProperty()
    {
        if ($this->movimientosStockId === null) {
            return null;
        }

        return app(StockService::class)->movimientosDe($this->movimientosStockId, 10, $this->movimientosPagina);
    }

    public function getStockMovimientosProperty(): ?StockLocal
    {
        if ($this->movimientosStockId === null) {
            return null;
        }

        return app(StockService::class)->consultar()->with('variante.talla', 'variante.producto:id,nombre')
            ->whereKey($this->movimientosStockId)->firstOrFail();
    }

    public function updatedSoloBajo(): void
    {
        $this->expandidos = [];
        $this->resetPage();
    }

    public function updatedBusqueda(): void
    {
        $this->resetPage();
    }

    /** @return array<int, string> producto_id => url */
    public function getFotosProperty(): array
    {
        return app(ImagenDeProducto::class)->porProducto(
            collect($this->grupos->items())->pluck('producto_id'),
        );
    }

    public function getFotoModeloProperty(): ?string
    {
        if ($this->modelo_producto_id === '') {
            return null;
        }

        $id = (int) $this->modelo_producto_id;

        return app(ImagenDeProducto::class)->porProducto([$id])[$id] ?? null;
    }

    /** @return array<string, string> sku en minúsculas => url */
    public function getFotosImportacionProperty(): array
    {
        if ($this->vistaPrevia === null) {
            return [];
        }

        $lineas = collect($this->vistaPrevia['lineas'] ?? []);
        $porVariante = app(ImagenDeProducto::class)->porVariante($lineas->pluck('variante_id'));
        $mapa = [];

        foreach ($lineas as $linea) {
            $url = $porVariante[(int) $linea['variante_id']] ?? null;

            if ($url !== null) {
                $mapa[mb_strtolower((string) $linea['sku'])] = $url;
            }
        }

        return $mapa;
    }

    public function getMinimoGeneralProperty(): int
    {
        return (int) (\App\Models\ConfiguracionDistribuidora::query()->value('stock_minimo_general') ?? 0);
    }

    /** Permiso fino «Entradas y ajustes de stock» (prendido por omisión). */
    public function getPuedeAjustarStockProperty(): bool
    {
        return app(\App\Services\Auth\PermisoModuloService::class)
            ->puede(Auth::user(), \App\Services\Auth\PermisoModuloService::STOCK_AJUSTAR);
    }

    public function getPuedeEditarMinimoProperty(): bool
    {
        return app(\App\Services\Auth\PermisoModuloService::class)
            ->puede(Auth::user(), \App\Services\Auth\PermisoModuloService::CONFIGURACION);
    }

    public function getTotalBajosProperty(): int
    {
        try {
            return app(StockService::class)->consultar()
                ->whereRaw('cantidad_disponible < (CASE WHEN stock_minimo > 0 THEN stock_minimo ELSE ? END)', [$this->minimoGeneral])
                ->count();
        } catch (OperacionInvalidaException) {
            return 0;
        }
    }

    public function editarMinimo(int $stockId): void
    {
        abort_unless($this->puedeEditarMinimo, 403);
        $stock = app(StockService::class)->consultar()->whereKey($stockId)->firstOrFail();
        $this->editandoMinimoId = (int) $stock->id;
        $this->minimoNuevo = (string) (int) $stock->stock_minimo;
        $this->resetErrorBag('minimoNuevo');
    }

    public function cancelarMinimo(): void
    {
        $this->editandoMinimoId = null;
        $this->minimoNuevo = '';
    }

    public function guardarMinimo(): void
    {
        abort_unless($this->puedeEditarMinimo, 403);
        $this->validate(['minimoNuevo' => ['required', 'integer', 'min:0', 'max:100000']], [
            'minimoNuevo.required' => 'Escribe el mínimo (0 = usar el general).',
            'minimoNuevo.integer' => 'El mínimo debe ser un número entero.',
            'minimoNuevo.min' => 'El mínimo no puede ser negativo.',
        ]);

        // Solo una existencia de esta distribuidora y su sucursal principal.
        $stock = app(StockService::class)->consultar()->whereKey($this->editandoMinimoId)->firstOrFail();
        $antes = (int) $stock->stock_minimo;
        $stock->timestamps = false;
        $stock->stock_minimo = (int) $this->minimoNuevo;
        $stock->save();

        app(\App\Services\Auditoria\RegistrarAuditoriaAction::class)->ejecutar(
            'stock.minimo', 'stock_local', (int) $stock->id,
            ['stock_minimo' => $antes], ['stock_minimo' => (int) $stock->stock_minimo, 'variante_id' => (int) $stock->variante_id],
        );

        $this->cancelarMinimo();
        $this->dispatch('guardado', mensaje: 'Mínimo actualizado.');
    }

    public function registrarEntrada(StockService $stock)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        $this->validate([
            'entrada_variante_id' => ['required', 'integer', 'min:1'],
            'entrada_cantidad' => ['required', 'integer', 'min:1'],
            'entrada_motivo' => ['nullable', 'string', 'max:300'],
        ], [
            'entrada_variante_id.required' => 'Selecciona una variante.',
            'entrada_cantidad.required' => 'Indica cuántas piezas entraron.',
            'entrada_cantidad.min' => 'La cantidad debe ser mayor que cero.',
        ]);

        try {
            $movimiento = $stock->registrarEntrada(
                (int) $this->entrada_variante_id,
                (int) $this->entrada_cantidad,
                $this->entrada_motivo !== '' ? $this->entrada_motivo : null,
            );

            $this->mensaje = 'Entrada registrada. Existencia: '
                . $movimiento->existencia_anterior . ' → ' . $movimiento->existencia_posterior . '.';

            $this->reset(['entrada_variante_id', 'entrada_cantidad', 'entrada_motivo']);
            $this->dispatch('stock-limpiar-variantes');
        } catch (OperacionInvalidaException $e) {
            $this->errorMsg = $e->getMessage();
        } catch (\Throwable $e) {
            report($e); // TG-224 (G3): el detalle va al log.
            $this->errorMsg = 'No se pudo registrar la entrada. Revisa los datos e intenta de nuevo.';
        }
    }

    public function registrarAjuste(StockService $stock)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        $this->validate([
            'ajuste_variante_id' => ['required', 'integer', 'min:1'],
            'ajuste_tipo' => ['required', 'in:ajuste_positivo,ajuste_negativo'],
            'ajuste_cantidad' => ['required', 'integer', 'min:1'],
            'ajuste_motivo' => ['required', 'string', 'max:300'],
        ], [
            'ajuste_variante_id.required' => 'Selecciona una variante.',
            'ajuste_cantidad.required' => 'Indica cuántas piezas ajustar.',
            'ajuste_motivo.required' => 'Todo ajuste manual necesita un motivo (merma, corrección, etc.).',
        ]);

        try {
            $movimiento = $stock->registrarAjuste(
                (int) $this->ajuste_variante_id,
                $this->ajuste_tipo,
                (int) $this->ajuste_cantidad,
                $this->ajuste_motivo,
            );

            $this->mensaje = 'Ajuste registrado. Existencia: '
                . $movimiento->existencia_anterior . ' → ' . $movimiento->existencia_posterior . '.';

            $this->reset(['ajuste_variante_id', 'ajuste_cantidad', 'ajuste_motivo']);
            $this->dispatch('stock-limpiar-variantes');
            $this->ajuste_tipo = 'ajuste_negativo';
        } catch (OperacionInvalidaException $e) {
            $this->errorMsg = $e->getMessage();
        } catch (\Throwable $e) {
            report($e); // TG-224 (G3): el detalle va al log.
            $this->errorMsg = 'No se pudo registrar el ajuste. Revisa los datos e intenta de nuevo.';
        }
    }
};
?>

<div>
    <x-panel.encabezado titulo="Stock local"
        subtitulo="Existencias, entradas y ajustes de la sucursal, y el conteo físico." class="mb-6" />

    <div class="fp-pestanas mb-6 flex flex-wrap gap-1" role="tablist" aria-label="Stock local">
        <button type="button" role="tab" wire:click="$set('pestana', 'existencias')"
            aria-selected="{{ $pestana === 'existencias' ? 'true' : 'false' }}" data-pestana-stock="existencias"
            @class([
                'fp-pestana rounded-lg px-3.5 py-2 text-sm font-medium transition',
                'bg-fp-primary text-white shadow-sm' => $pestana === 'existencias',
                'text-slate-600 hover:bg-slate-50' => $pestana !== 'existencias',
            ])>Existencias</button>
        @if ($this->puedeContar)
            <button type="button" role="tab" wire:click="$set('pestana', 'conteo')"
                aria-selected="{{ $pestana === 'conteo' ? 'true' : 'false' }}" data-pestana-stock="conteo"
                @class([
                    'fp-pestana rounded-lg px-3.5 py-2 text-sm font-medium transition',
                    'bg-fp-primary text-white shadow-sm' => $pestana === 'conteo',
                    'text-slate-600 hover:bg-slate-50' => $pestana !== 'conteo',
                ])>Conteo</button>
        @endif
    </div>

    @if ($pestana === 'conteo' && $this->puedeContar)
        <livewire:conteos.index :embebido="true" wire:key="stock-local-conteo" />
    @else
    @if ($mensaje)
        <x-panel.alerta tipo="exito" class="mb-4">
            {{ $mensaje }}
            @if ($resumenModelo)
                <ul class="mt-2 flex flex-wrap gap-1.5 text-xs" data-resumen-modelo>
                    @foreach ($resumenModelo as $linea)
                        <li class="rounded-full bg-white/70 px-2 py-0.5 ring-1 ring-fp-success/30">
                            Talla {{ $linea['talla'] }}: +{{ $linea['cantidad'] }} ({{ $linea['antes'] }} → {{ $linea['despues'] }})
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-panel.alerta>
    @endif

    @if ($errorMsg)
        <x-panel.alerta tipo="error" class="mb-4">
            {{ $errorMsg }}
        </x-panel.alerta>
    @endif

    @if (! $this->puedeAjustarStock)
        <x-panel.alerta tipo="info" class="mb-4">
            Puedes consultar las existencias. Para registrar entradas o ajustes pídele el permiso al administrador.
        </x-panel.alerta>
    @endif

    @if ($this->puedeAjustarStock)
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- Entrada de mercancía: por variante, por modelo (todas las tallas) o importando un archivo --}}
        <div class="relative fp-tarjeta p-5 fp-formulario fp-aparecer">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-slate-800">Registrar entrada</h3>
                <div class="inline-flex rounded-lg bg-slate-100 p-0.5 text-xs" role="tablist" aria-label="Forma de registrar la entrada">
                    @foreach (['variante' => 'Una variante', 'modelo' => 'Por modelo', 'importar' => 'Importar archivo'] as $clave => $texto)
                        <button type="button" role="tab" data-tab="{{ $clave }}" aria-selected="{{ $tabEntrada === $clave ? 'true' : 'false' }}"
                            wire:click="$set('tabEntrada', '{{ $clave }}')"
                            class="rounded-md px-2.5 py-1 font-medium transition {{ $tabEntrada === $clave ? 'bg-white text-fp-sidebar shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
                            {{ $texto }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($tabEntrada === 'variante')
                <form wire:submit="registrarEntrada" class="space-y-4 fp-emerger" wire:key="tab-variante">
                    <div class="fp-campo">
                        <x-stock.combobox-variante id="index-entrada-variante-id" modelo="entrada_variante_id" />
                        @error('entrada_variante_id') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="fp-campo">
                        <label for="index-entrada-cantidad" class="block text-sm font-medium text-slate-700 mb-1">Cantidad</label>
                        <input id="index-entrada-cantidad" type="number" min="1" step="1" wire:model="entrada_cantidad"
                               class="w-full rounded-lg text-sm focus:ring-fp-primary" placeholder="10">
                        @error('entrada_cantidad') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="fp-campo">
                        <label for="index-entrada-motivo" class="block text-sm font-medium text-slate-700 mb-1">Motivo (opcional)</label>
                        <input id="index-entrada-motivo" type="text" maxlength="300" wire:model="entrada_motivo"
                               class="w-full rounded-lg text-sm focus:ring-fp-primary" placeholder="Ej. compra a fábrica">
                    </div>

                    <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled" wire:target="registrarEntrada">
                        <span wire:loading.delay.remove wire:target="registrarEntrada">Registrar entrada</span>
                        <span wire:loading.delay wire:target="registrarEntrada">Registrando…</span>
                    </button>
                </form>
            @elseif ($tabEntrada === 'modelo')
                <form wire:submit="registrarEntradaModelo" class="space-y-4 fp-emerger" wire:key="tab-modelo">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="fp-campo">
                            <label for="modelo-producto" class="block text-sm font-medium text-slate-700 mb-1">Modelo</label>
                            <x-ui.buscador wire:model.live.debounce.300ms="buscarModelo" placeholder="Modelo, marca, línea, SKU o código…" etiqueta="Buscar modelo por nombre, marca, línea, SKU o código" class="mb-2 w-full" data-buscador-modelo />
                            <div class="flex items-center gap-2">
                                @if ($modelo_producto_id !== '')
                                    <x-ui.imagen-ampliable :url="$this->fotoModelo" :alt="$this->productosModelo->firstWhere('id', (int) $modelo_producto_id)?->nombre ?? 'Producto'" />
                                @endif
                            <select id="modelo-producto" wire:model.live="modelo_producto_id" class="w-full rounded-lg text-sm focus:ring-fp-primary">
                                <option value="">— Elegir modelo —</option>
                                @foreach ($this->productosModelo as $producto)
                                    <option value="{{ $producto->id }}">{{ $producto->nombre }}{{ $producto->modelo ? ' ('.$producto->modelo.')' : '' }}{{ $producto->marca ? ' - '.$producto->marca->nombre : '' }}</option>
                                @endforeach
                            </select>
                            </div>
                            @error('modelo_producto_id') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="fp-campo">
                            <label for="modelo-color" class="block text-sm font-medium text-slate-700 mb-1">Color</label>
                            <select id="modelo-color" wire:model.live="modelo_color_id" class="w-full rounded-lg text-sm focus:ring-fp-primary" @disabled($modelo_producto_id === '')>
                                <option value="">— Elegir color —</option>
                                @foreach ($this->coloresModelo as $color)
                                    <option value="{{ $color['id'] }}">{{ $color['nombre'] }}</option>
                                @endforeach
                            </select>
                            @error('modelo_color_id') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="relative">
                        <x-ui.cargando objetivo="modelo_producto_id,modelo_color_id" />
                        @if ($this->tallasModelo->isNotEmpty())
                            <p class="mb-2 text-xs text-slate-500">Cantidad que entra por talla (deja vacío o 0 las que no llegaron).</p>
                            <div class="grid grid-cols-3 sm:grid-cols-4 gap-2" data-rejilla-tallas>
                                @foreach ($this->tallasModelo as $talla)
                                    <label class="rounded-lg border border-slate-200 p-2 text-center transition focus-within:border-fp-primary" wire:key="talla-{{ $talla['id'] }}">
                                        <span class="block text-sm font-semibold text-fp-sidebar">{{ $talla['talla'] }}</span>
                                        <span class="block text-[11px] text-slate-500">Hay {{ $talla['existencia'] }}</span>
                                        <input type="number" min="0" step="1" inputmode="numeric" data-cantidad-talla
                                            wire:model="modelo_cantidades.{{ $talla['id'] }}" aria-label="Cantidad talla {{ $talla['talla'] }}"
                                            class="mt-1 w-full rounded-md py-1 text-center text-sm focus:ring-fp-primary" placeholder="0">
                                    </label>
                                @endforeach
                            </div>
                        @elseif ($modelo_producto_id !== '' && $modelo_color_id !== '')
                            <p class="text-xs text-slate-500">Este modelo y color no tiene tallas registradas.</p>
                        @endif
                        @error('modelo_cantidades') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                        @error('modelo_cantidades.*') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="fp-campo">
                        <label for="modelo-motivo" class="block text-sm font-medium text-slate-700 mb-1">Motivo (opcional, para todas las tallas)</label>
                        <input id="modelo-motivo" type="text" maxlength="300" wire:model="modelo_motivo"
                               class="w-full rounded-lg text-sm focus:ring-fp-primary" placeholder="Ej. compra a fábrica">
                    </div>

                    <button type="submit" class="fp-btn fp-btn-primario" data-registrar-modelo wire:loading.attr="disabled" wire:target="registrarEntradaModelo">
                        <span wire:loading.delay.remove wire:target="registrarEntradaModelo">Registrar todas las tallas</span>
                        <span wire:loading.delay wire:target="registrarEntradaModelo">Registrando…</span>
                    </button>
                </form>
            @else
                <div class="space-y-4 fp-emerger" wire:key="tab-importar">
                    <p class="text-xs text-slate-500">
                        Sube un Excel (.xlsx) o CSV con las columnas <strong>SKU</strong>, <strong>cantidad</strong> y <strong>motivo</strong> (opcional).
                        Máximo {{ \App\Services\Stock\ImportarEntradasService::MAX_FILAS }} filas y 2 MB. Los SKU repetidos se suman.
                        <a href="{{ route('stock.plantilla') }}" class="font-medium text-fp-primary hover:underline" data-descargar-plantilla>Descargar plantilla</a>
                    </p>

                    <div class="fp-campo">
                        <label for="importar-archivo" class="block text-sm font-medium text-slate-700 mb-1">Archivo</label>
                        <input id="importar-archivo" type="file" wire:model="archivo" accept=".xlsx,.xls,.csv,.txt"
                            class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-fp-primary/10 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-fp-primary">
                        <p wire:loading.delay wire:target="archivo" class="mt-1 text-xs text-slate-500">Leyendo archivo…</p>
                        @error('archivo') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                    </div>

                    @if ($errorImportacion)
                        <x-panel.alerta tipo="error">{{ $errorImportacion }}</x-panel.alerta>
                    @endif

                    @if ($vistaPrevia)
                        <div data-vista-previa>
                            <p class="mb-2 text-xs text-slate-600">
                                {{ count($vistaPrevia['filas']) }} filas leídas ·
                                <span class="text-fp-success">{{ count($vistaPrevia['lineas']) }} variantes válidas ({{ array_sum(array_column($vistaPrevia['lineas'], 'cantidad')) }} piezas)</span>
                                @if ($vistaPrevia['errores'] > 0)
                                    · <span class="font-medium text-fp-danger">{{ $vistaPrevia['errores'] }} con error</span>
                                @endif
                            </p>
                            <div class="max-h-64 overflow-auto rounded-lg border border-slate-200">
                                <table class="min-w-full text-xs">
                                    <thead class="sticky top-0 bg-fp-page text-left uppercase tracking-wide text-slate-600">
                                        <tr><th class="px-3 py-2">Fila</th><th class="px-3 py-2">SKU</th><th class="px-3 py-2 text-right">Cantidad</th><th class="px-3 py-2">Motivo</th><th class="px-3 py-2">Estado</th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($vistaPrevia['filas'] as $fila)
                                            <tr class="{{ $fila['error'] ? 'bg-fp-danger/5' : '' }}">
                                                <td class="px-3 py-1.5 text-slate-500">{{ $fila['fila'] }}</td>
                                                <td class="px-3 py-1.5 font-mono">
                                                    <div class="flex items-center gap-2">
                                                        <x-ui.imagen-ampliable :url="$this->fotosImportacion[mb_strtolower((string) $fila['sku'])] ?? null" :alt="$fila['sku'] !== '' ? $fila['sku'] : 'Producto'" />
                                                        <span>{{ $fila['sku'] ?: '—' }}</span>
                                                    </div>
                                                </td>
                                                <td class="px-3 py-1.5 text-right tabular-nums">{{ $fila['cantidad'] }}</td>
                                                <td class="px-3 py-1.5 text-slate-500">{{ $fila['motivo'] }}</td>
                                                <td class="px-3 py-1.5 {{ $fila['error'] ? 'text-fp-danger font-medium' : 'text-fp-success' }}">{{ $fila['error'] ?? 'OK' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3 flex gap-2">
                                <button type="button" wire:click="aplicarImportacion" class="fp-btn fp-btn-primario" data-aplicar-importacion
                                    @disabled($vistaPrevia['errores'] > 0 || $vistaPrevia['lineas'] === []) wire:loading.attr="disabled" wire:target="aplicarImportacion">
                                    <span wire:loading.delay.remove wire:target="aplicarImportacion">Aplicar entradas</span>
                                    <span wire:loading.delay wire:target="aplicarImportacion">Aplicando…</span>
                                </button>
                                <button type="button" wire:click="cancelarImportacion" class="fp-btn fp-btn-secundario">Cancelar</button>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Ajuste manual --}}
        <div class="fp-tarjeta p-5 fp-formulario fp-aparecer">
            <h3 class="text-sm font-semibold text-slate-800 mb-4">Ajuste manual</h3>

            <form wire:submit="registrarAjuste" class="space-y-4">
                <div class="fp-campo">
                    <x-stock.combobox-variante id="index-ajuste-variante-id" modelo="ajuste_variante_id" />
                    @error('ajuste_variante_id') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="fp-campo">
                        <label for="index-ajuste-tipo" class="block text-sm font-medium text-slate-700 mb-1">Tipo</label>
                        <select id="index-ajuste-tipo" wire:model="ajuste_tipo" class="w-full rounded-lg text-sm focus:ring-fp-primary">
                            <option value="ajuste_negativo">Descontar</option>
                            <option value="ajuste_positivo">Agregar</option>
                        </select>
                    </div>
                    <div class="fp-campo">
                        <label for="index-ajuste-cantidad" class="block text-sm font-medium text-slate-700 mb-1">Cantidad</label>
                        <input id="index-ajuste-cantidad" type="number" min="1" step="1" wire:model="ajuste_cantidad"
                               class="w-full rounded-lg text-sm focus:ring-fp-primary" placeholder="1">
                        @error('ajuste_cantidad') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="fp-campo">
                    <label for="index-ajuste-motivo" class="block text-sm font-medium text-slate-700 mb-1">Motivo</label>
                    <input id="index-ajuste-motivo" type="text" maxlength="300" wire:model="ajuste_motivo"
                           class="w-full rounded-lg text-sm focus:ring-fp-primary" placeholder="Ej. merma, corrección de conteo">
                    @error('ajuste_motivo') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="fp-btn fp-btn-oscuro" wire:loading.attr="disabled" wire:target="registrarAjuste">
                    <span wire:loading.delay.remove wire:target="registrarAjuste">Registrar ajuste</span>
                    <span wire:loading.delay wire:target="registrarAjuste">Registrando…</span>
                </button>
            </form>
        </div>
    </div>
    @endif

    @php
        $tipApartado = 'Piezas de esta talla en pedidos abiertos (colocados, en revisión, confirmados, en ciclo, pedidos a fábrica, en tránsito o listos para entregar). '
            .'Los pedidos se surten de fábrica: no apartan ni descuentan este stock. Es solo informativo.';
        $tipDisponible = 'Lo que puedes vender en mostrador ahora. Hoy es igual a la existencia, porque ningún pedido aparta piezas de la sucursal.';
    @endphp

    {{-- Existencias agrupadas por modelo + color --}}
    <div class="relative fp-tarjeta overflow-hidden fp-formulario fp-aparecer" data-tabla-existencias>
        <x-ui.cargando objetivo="busqueda,soloBajo,gotoPage,nextPage,previousPage" />
        <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-center gap-3 justify-between">
            <h3 class="text-sm font-semibold text-slate-800">Existencias</h3>
            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 transition hover:border-fp-primary/40" data-filtro-stock-bajo>
                    <input type="checkbox" wire:model.live="soloBajo" class="rounded text-fp-primary">
                    Solo stock bajo
                    @if ($this->totalBajos > 0)
                        <x-panel.insignia variante="danger" viva>{{ $this->totalBajos }}</x-panel.insignia>
                    @endif
                </label>
                <x-ui.buscador wire:model.live.debounce.300ms="busqueda"
                       placeholder="Buscar por SKU o modelo…" etiqueta="Buscar existencias por SKU o modelo" class="w-full sm:w-72" />
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="fp-tabla min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="text-left font-medium px-4 py-3">Producto</th>
                        <th class="text-left font-medium px-4 py-3">Tallas</th>
                        <th class="text-right font-medium px-4 py-3">Existencia</th>
                        <th class="text-right font-medium px-4 py-3"><span title="{{ $tipApartado }}" class="cursor-help border-b border-dotted border-slate-400" data-tip-apartado>Apartado</span></th>
                        <th class="text-right font-medium px-4 py-3"><span title="{{ $tipDisponible }}" class="cursor-help border-b border-dotted border-slate-400">Disponible para vender</span></th>
                        <th class="px-4 py-3"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                @forelse ($this->grupos as $grupo)
                    @php
                        $clave = $grupo->producto_id.'-'.(int) $grupo->color_id;
                        $filas = $this->filasPorGrupo[$clave] ?? collect();
                        // Con "solo bajo" los grupos se muestran abiertos; el botón los cierra.
                        $abierto = $soloBajo ? ! in_array($clave, $expandidos, true) : in_array($clave, $expandidos, true);
                        $apartadoGrupo = $filas->sum(fn ($f) => $this->enPedidos[$f->variante_id] ?? 0);
                    @endphp
                    <tbody class="border-t border-slate-100" wire:key="grupo-{{ $clave }}" data-grupo="{{ $clave }}">
                        <tr class="{{ $abierto ? 'bg-fp-page/60' : '' }}">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <x-ui.imagen-ampliable :url="$this->fotos[$grupo->producto_id] ?? null" :alt="$grupo->producto_nombre ?? 'Producto'" />
                                    <div>
                                        <span class="text-slate-800">{{ $grupo->producto_nombre }}</span>
                                        <span class="block text-xs text-slate-500">{{ implode(' · ', array_filter([$grupo->color_nombre, $grupo->producto_modelo])) }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1" data-chips-tallas>
                                    @foreach ($filas as $fila)
                                        @php $bajo = $fila->estaBajo($this->minimoGeneral); @endphp
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] tabular-nums ring-1 {{ $bajo ? 'bg-fp-danger/10 text-fp-danger ring-fp-danger/30 font-semibold' : 'bg-fp-page text-fp-text-muted ring-slate-200' }}"
                                            @if ($bajo) title="Por debajo del mínimo ({{ $fila->minimoEfectivo($this->minimoGeneral) }})" data-chip-bajo @endif>
                                            {{ $fila->variante?->talla?->valor ?? '—' }}: {{ (int) $fila->cantidad_disponible }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums {{ (int) $grupo->bajos > 0 ? 'text-fp-danger' : 'text-slate-900' }}">
                                <span class="inline-flex items-center justify-end gap-2">
                                    @if ((int) $grupo->bajos > 0)
                                        <x-panel.insignia variante="danger" punto data-stock-bajo>Stock bajo ({{ (int) $grupo->bajos }})</x-panel.insignia>
                                    @endif
                                    {{ (int) $grupo->total }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-500" title="{{ $tipApartado }}">{{ $apartadoGrupo }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-medium text-slate-900">{{ (int) $grupo->total }}</td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" wire:click="alternarGrupo('{{ $clave }}')" data-expandir-grupo aria-expanded="{{ $abierto ? 'true' : 'false' }}"
                                    class="fp-accion fp-accion-neutra inline-flex items-center gap-1">
                                    {{ $abierto ? 'Ocultar' : 'Ver tallas' }}
                                    <svg class="h-3.5 w-3.5 transition-transform duration-150 {{ $abierto ? 'rotate-180' : '' }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                                </button>
                            </td>
                        </tr>
                        @if ($abierto)
                            @foreach ($filas as $existencia)
                                @php
                                    $bajo = $existencia->estaBajo($this->minimoGeneral);
                                    $enPedido = $this->enPedidos[$existencia->variante_id] ?? 0;
                                @endphp
                                <tr class="fp-emerger bg-slate-50/50 text-[13px]" wire:key="fila-{{ $existencia->id }}" data-fila-talla>
                                    <td class="py-2 pl-14 pr-4">
                                        <div class="flex items-center gap-2">
                                            <x-ui.imagen-ampliable :url="$this->fotos[$existencia->variante?->producto_id] ?? null" :alt="$existencia->variante?->producto?->nombre ?? 'Producto'" />
                                            <span class="font-mono text-xs">{{ $existencia->variante?->sku ?? '—' }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 text-slate-600">
                                        Talla {{ $existencia->variante?->talla?->valor ?? '—' }}
                                        <span class="ml-2 text-xs text-slate-500 whitespace-nowrap">
                                            Mín.
                                            @if ($editandoMinimoId === (int) $existencia->id)
                                                <form wire:submit="guardarMinimo" class="fp-emerger inline-flex items-center gap-1.5" data-editar-minimo>
                                                    <label for="minimo-{{ $existencia->id }}" class="sr-only">Mínimo de {{ $existencia->variante?->sku }}</label>
                                                    <input id="minimo-{{ $existencia->id }}" type="number" min="0" wire:model="minimoNuevo" class="w-20 rounded-lg py-1 text-right text-sm" autofocus>
                                                    <button type="submit" class="fp-accion fp-accion-primaria">Guardar</button>
                                                    <button type="button" wire:click="cancelarMinimo" class="fp-accion fp-accion-neutra">Cancelar</button>
                                                </form>
                                                @error('minimoNuevo') <span class="block text-xs text-fp-danger">{{ $message }}</span> @enderror
                                            @else
                                                @if ((int) $existencia->stock_minimo > 0)
                                                    <span class="tabular-nums text-slate-600">{{ (int) $existencia->stock_minimo }}</span>
                                                @else
                                                    <span class="tabular-nums" title="Usa el mínimo general">{{ $this->minimoGeneral > 0 ? $this->minimoGeneral.' (general)' : '—' }}</span>
                                                @endif
                                                @if ($this->puedeEditarMinimo)
                                                    <button type="button" wire:click="editarMinimo({{ $existencia->id }})" class="fp-accion fp-accion-neutra" aria-label="Editar mínimo de {{ $existencia->variante?->sku }}">Editar</button>
                                                @endif
                                            @endif
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right font-medium tabular-nums {{ (int) $existencia->cantidad_disponible === 0 || $bajo ? 'text-fp-danger' : 'text-slate-900' }}">
                                        <span class="inline-flex items-center justify-end gap-2">
                                            @if ($bajo)
                                                <x-panel.insignia variante="danger" punto>Stock bajo</x-panel.insignia>
                                            @endif
                                            {{ (int) $existencia->cantidad_disponible }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums text-slate-500" title="{{ $tipApartado }}">{{ $enPedido }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-slate-900">{{ (int) $existencia->cantidad_disponible }}</td>
                                    <td class="px-4 py-2 text-right whitespace-nowrap">
                                        <button type="button" wire:click="verMovimientos({{ $existencia->id }})" data-ver-movimientos
                                            class="fp-accion fp-accion-neutra" aria-label="Movimientos de {{ $existencia->variante?->sku }}">Movimientos</button>
                                        <span class="block text-[11px] text-slate-500">{{ optional($existencia->updated_at)->format('d/m/Y H:i') }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    </tbody>
                @empty
                    <tbody>
                        <x-panel.vacio colspan="6" :mensaje="$soloBajo ? 'Ninguna variante está por debajo de su mínimo.' : 'No hay existencias registradas todavía.'" />
                    </tbody>
                @endforelse
            </table>
        </div>
        <div class="mt-4">
            {{ $this->grupos->links() }}
        </div>
    </div>

    {{-- Modal: movimientos recientes de una variante --}}
    @if ($movimientosStockId !== null && $this->stockMovimientos)
        @php $sm = $this->stockMovimientos; @endphp
        <x-panel.modal titulo="Movimientos" ancho="max-w-3xl" cerrar="cerrarMovimientos"
            :subtitulo="($sm->variante?->sku ?? '').' · '.($sm->variante?->producto?->nombre ?? '').' · Talla '.($sm->variante?->talla?->valor ?? '—').' · Existencia '.(int) $sm->cantidad_disponible">
            <div class="mb-3">
                <x-ui.imagen-ampliable :url="$this->fotos[$sm->variante?->producto_id] ?? null" :alt="$sm->variante?->producto?->nombre ?? 'Producto'" />
            </div>
            <div class="relative" data-modal-movimientos>
                <x-ui.cargando objetivo="paginaMovimientos" />
                <div class="overflow-x-auto rounded-lg border border-slate-200">
                    <table class="min-w-full text-xs">
                        <thead class="bg-fp-page text-left uppercase tracking-wide text-slate-600">
                            <tr>
                                <th class="px-3 py-2">Fecha</th><th class="px-3 py-2">Tipo</th><th class="px-3 py-2 text-right">Cant.</th>
                                <th class="px-3 py-2 text-right">Antes → después</th><th class="px-3 py-2">Usuario</th><th class="px-3 py-2">Motivo / referencia</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @php
                                $tipos = [
                                    'entrada' => ['Entrada', 'text-fp-success', '+'],
                                    'ajuste_positivo' => ['Ajuste +', 'text-fp-success', '+'],
                                    'ajuste_negativo' => ['Ajuste −', 'text-fp-danger', '−'],
                                    'venta' => ['Venta', 'text-fp-primary', '−'],
                                    'devolucion' => ['Devolución', 'text-fp-success', '+'],
                                ];
                            @endphp
                            @forelse ($this->movimientos as $mov)
                                @php [$tipoTexto, $tipoColor, $signo] = $tipos[$mov->tipo] ?? [$mov->tipo, 'text-slate-600', '']; @endphp
                                <tr wire:key="mov-{{ $mov->id }}">
                                    <td class="px-3 py-1.5 whitespace-nowrap text-slate-500">{{ \Illuminate\Support\Carbon::parse($mov->created_at)->format('d/m/Y H:i') }}</td>
                                    <td class="px-3 py-1.5 font-medium {{ $tipoColor }}">{{ $tipoTexto }}</td>
                                    <td class="px-3 py-1.5 text-right tabular-nums">{{ $signo }}{{ (int) $mov->cantidad }}</td>
                                    <td class="px-3 py-1.5 text-right tabular-nums text-slate-600">{{ (int) $mov->existencia_anterior }} → {{ (int) $mov->existencia_posterior }}</td>
                                    <td class="px-3 py-1.5 text-slate-600">{{ $mov->registradoPor?->usuario?->nombre ?? '—' }}</td>
                                    <td class="px-3 py-1.5 text-slate-500">
                                        @if ($mov->ventaDetalle?->ventaDirecta)
                                            Venta {{ $mov->ventaDetalle->ventaDirecta->folio }}
                                        @endif
                                        {{ $mov->motivo }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">Esta variante aún no tiene movimientos.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-[11px] text-slate-500">Aquí se registran entradas, ajustes, ventas de mostrador y devoluciones. Los pedidos y los vales no mueven este stock.</p>
                @if ($this->movimientos->lastPage() > 1)
                    <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
                        <span>Página {{ $this->movimientos->currentPage() }} de {{ $this->movimientos->lastPage() }}</span>
                        <div class="flex gap-2">
                            <button type="button" class="fp-accion fp-accion-neutra" wire:click="paginaMovimientos({{ $movimientosPagina - 1 }})" @disabled($this->movimientos->onFirstPage())>Anteriores</button>
                            <button type="button" class="fp-accion fp-accion-neutra" wire:click="paginaMovimientos({{ $movimientosPagina + 1 }})" @disabled(! $this->movimientos->hasMorePages())>Más antiguos</button>
                        </div>
                    </div>
                @endif
            </div>
            <x-slot:pie>
                <button type="button" wire:click="cerrarMovimientos" class="fp-btn fp-btn-secundario">Cerrar</button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
    @endif
</div>
