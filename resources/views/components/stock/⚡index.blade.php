<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Producto;
use App\Models\Variante;
use App\Services\Catalogo\ImagenDeProducto;
use App\Services\Stock\StockService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;
use App\Models\StockLocal;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.panel')] #[Title('Stock local — FootwearPoint')] class extends Component
{
    use WithPagination;

    /**
     * Movimiento de stock (entrada o salida), una sola tarjeta con un solo buscador:
     * se escribe modelo, marca, línea, SKU o código, se elige el modelo y su color y
     * se marcan las tallas (recuadros) con la cantidad de cada una.
     */

    /** 'entrada' suma piezas; 'salida' las descuenta (ajuste negativo, con motivo). */
    public string $tipoMovimiento = 'entrada';

    // Movimiento por modelo: producto + color y una cantidad por talla marcada.
    public string $modelo_producto_id = '';
    public string $modelo_color_id = '';
    /** Texto del buscador único: nombre, modelo, marca, línea, SKU o código de catálogo. */
    public string $buscarModelo = '';
    /** @var array<int|string, string> variante_id => cantidad; estar en la lista = talla marcada */
    public array $modelo_cantidades = [];
    public string $modelo_motivo = '';
    /** @var list<array{talla:string, sku:string, cantidad:int, antes:int, despues:int, tipo:string}> */
    public array $resumenModelo = [];

    // Tabla agrupada por producto + color
    /** @var list<string> claves "producto-color" abiertas */
    public array $expandidos = [];

    // Modal de movimientos
    public ?int $movimientosStockId = null;
    public int $movimientosPagina = 1;

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

    // ---------- Entrada por modelo ----------

    /** Mínimo de letras para sugerir modelos. */
    private const MIN_LETRAS_BUSQUEDA = 2;

    /** Texto limpio del buscador; vacío si todavía es muy corto para sugerir. */
    public function getTerminoModeloProperty(): string
    {
        $termino = trim(mb_substr($this->buscarModelo, 0, 80));

        return mb_strlen($termino) >= self::MIN_LETRAS_BUSQUEDA ? $termino : '';
    }

    /**
     * Modelos sugeridos al escribir: por nombre, modelo, marca, línea, SKU o código
     * de catálogo. Los que empiezan igual que lo escrito salen primero.
     */
    public function getProductosModeloProperty()
    {
        $termino = $this->terminoModelo;

        if ($termino === '') {
            return collect();
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $termino).'%';

        return Producto::query()
            ->whereHas('variantes')
            ->where(fn ($w) => $w
                ->where('nombre', 'like', $like)
                ->orWhere('modelo', 'like', $like)
                ->orWhereHas('marca', fn ($m) => $m->where('nombre', 'like', $like))
                ->orWhereHas('variantes', fn ($v) => $v->where('sku', 'like', $like))
                ->orWhereHas('publicacionesCampana', fn ($p) => $p->where('codigo_catalogo', 'like', $like)
                    ->orWhereHas('campana.linea', fn ($l) => $l->where('nombre', 'like', $like))))
            ->with(['marca:id,nombre', 'publicacionesCampana.campana.linea:id,nombre'])
            ->orderByRaw('CASE WHEN modelo = ? THEN 0 WHEN nombre LIKE ? THEN 1 ELSE 2 END', [$termino, $termino.'%'])
            ->orderBy('nombre')
            ->limit(8)
            ->get(['id', 'nombre', 'modelo', 'marca_id']);
    }

    /** Variante cuyo SKU es exactamente lo escrito (pre-elige modelo, color y talla). */
    public function getVarianteExactaProperty(): ?Variante
    {
        $termino = $this->terminoModelo;

        if ($termino === '') {
            return null;
        }

        return Variante::query()
            ->with('talla:id,sistema,valor', 'color:id,nombre', 'producto:id,nombre,modelo')
            ->where('sku', $termino)
            ->first();
    }

    /** Fotos de los modelos sugeridos: producto_id => url. */
    public function getFotosSugerenciasProperty(): array
    {
        return app(ImagenDeProducto::class)->porProducto($this->productosModelo->pluck('id'));
    }

    /** Modelo ya elegido, para mostrarlo en lugar del buscador. */
    public function getProductoElegidoProperty(): ?Producto
    {
        if ($this->modelo_producto_id === '') {
            return null;
        }

        return Producto::query()->with('marca:id,nombre')->find((int) $this->modelo_producto_id, ['id', 'nombre', 'modelo', 'marca_id']);
    }

    /** Elige un modelo de las sugerencias; el color se pre-elige si solo tiene uno. */
    public function elegirModelo(int $productoId): void
    {
        if (! Producto::query()->whereHas('variantes')->whereKey($productoId)->exists()) {
            return;
        }

        $this->modelo_producto_id = (string) $productoId;
        $this->buscarModelo = '';
        $this->errorMsg = '';
        $this->resetErrorBag();
        $this->updatedModeloProductoId();
    }

    /** Elige una variante por SKU exacto: deja listo su modelo, color y talla. */
    public function elegirSku(int $varianteId): void
    {
        $variante = Variante::query()->find($varianteId, ['id', 'producto_id', 'color_id']);

        if (! $variante) {
            return;
        }

        $this->modelo_producto_id = (string) $variante->producto_id;
        $this->modelo_color_id = (string) $variante->color_id;
        $this->modelo_cantidades = [(int) $variante->id => ''];
        $this->buscarModelo = '';
        $this->errorMsg = '';
        $this->resetErrorBag();
    }

    /** Enter en el buscador: un SKU exacto, o el único modelo que coincide. */
    public function entrarBusqueda(): void
    {
        if ($this->modelo_producto_id !== '') {
            return;
        }

        if ($exacta = $this->varianteExacta) {
            $this->elegirSku((int) $exacta->id);

            return;
        }

        $sugeridos = $this->productosModelo;

        if ($sugeridos->count() === 1) {
            $this->elegirModelo((int) $sugeridos->first()->id);
        }
    }

    public function limpiarModelo(): void
    {
        $this->modelo_producto_id = '';
        $this->modelo_color_id = '';
        $this->modelo_cantidades = [];
        $this->buscarModelo = '';
        $this->resetErrorBag();
    }

    public function elegirColor(int $colorId): void
    {
        if (! $this->coloresModelo->contains('id', $colorId)) {
            return;
        }

        $this->modelo_color_id = (string) $colorId;
        $this->modelo_cantidades = [];
        $this->resetErrorBag();
    }

    /** Marca o desmarca una talla (recuadro); al marcarla aparece su cantidad. */
    public function alternarTalla(int $varianteId): void
    {
        if (! $this->tallasModelo->contains('id', $varianteId)) {
            return;
        }

        if (array_key_exists($varianteId, $this->modelo_cantidades)) {
            unset($this->modelo_cantidades[$varianteId]);
        } else {
            $this->modelo_cantidades[$varianteId] = '';
        }

        $this->resetErrorBag();
    }

    public function marcarTodasLasTallas(): void
    {
        foreach ($this->tallasModelo as $talla) {
            $this->modelo_cantidades[$talla['id']] ??= '';
        }
    }

    public function quitarTallas(): void
    {
        $this->modelo_cantidades = [];
        $this->resetErrorBag();
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

        public function updatedTipoMovimiento(): void
    {
        if (! in_array($this->tipoMovimiento, ['entrada', 'salida'], true)) {
            $this->tipoMovimiento = 'entrada';
        }

        $this->mensaje = '';
        $this->errorMsg = '';
        $this->resetErrorBag();
    }

    private function esSalida(): bool
    {
        return $this->tipoMovimiento === 'salida';
    }

    /** Movimiento por modelo y color: una cantidad por talla, entrada o salida. */
    public function registrarEntradaModelo(StockService $stock): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';
        $this->resumenModelo = [];
        $salida = $this->esSalida();

        $this->validate([
            'modelo_producto_id' => ['required', 'integer'],
            'modelo_color_id' => ['required', 'integer'],
            'modelo_cantidades' => ['array'],
            'modelo_cantidades.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'modelo_motivo' => [$salida ? 'required' : 'nullable', 'string', 'max:300'],
        ], [
            'modelo_producto_id.required' => 'Busca y elige el modelo.',
            'modelo_color_id.required' => 'Elige el color.',
            'modelo_cantidades.*.integer' => 'Las cantidades deben ser números enteros.',
            'modelo_cantidades.*.min' => 'Las cantidades no pueden ser negativas.',
            'modelo_motivo.required' => 'Toda salida necesita un motivo (merma, corrección, etc.).',
        ]);

        // Solo tallas marcadas que pertenecen al modelo y color elegidos.
        $tallas = $this->tallasModelo->keyBy('id');
        $marcadas = collect($this->modelo_cantidades)->filter(fn ($c, $id) => $tallas->has((int) $id));

        if ($marcadas->isEmpty()) {
            $this->addError('modelo_cantidades', 'Marca al menos una talla y escribe su cantidad.');

            return;
        }

        if ($marcadas->contains(fn ($c) => (int) $c < 1)) {
            $this->addError('modelo_cantidades', 'Escribe la cantidad (mínimo 1) de cada talla marcada, o quita la talla.');

            return;
        }

        $cantidades = $marcadas->mapWithKeys(fn ($c, $id) => [(int) $id => (int) $c])->all();

        $motivo = $this->modelo_motivo !== '' ? $this->modelo_motivo : null;

        try {
            $movimientos = $salida
                ? $stock->registrarSalidas($cantidades, (string) $motivo)
                : $stock->registrarEntradas($cantidades, $motivo);

            $this->resumenModelo = collect($movimientos)->map(fn ($m) => [
                'talla' => $tallas[$m->stockLocal->variante_id]['talla'],
                'sku' => $tallas[$m->stockLocal->variante_id]['sku'],
                'cantidad' => (int) $m->cantidad,
                'antes' => (int) $m->existencia_anterior,
                'despues' => (int) $m->existencia_posterior,
                'tipo' => $salida ? 'salida' : 'entrada',
            ])->all();

            $piezas = array_sum($cantidades);
            $this->mensaje = ($salida ? 'Salida registrada: ' : 'Entrada registrada: ')
                .$piezas.' '.($piezas === 1 ? 'pieza' : 'piezas').' en '.count($movimientos).' '.(count($movimientos) === 1 ? 'talla' : 'tallas').'.';
            $this->modelo_cantidades = [];
            $this->modelo_motivo = '';
            unset($this->tallasModelo); // que «Hay N» muestre la existencia ya actualizada
        } catch (OperacionInvalidaException $e) {
            $this->errorMsg = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $this->errorMsg = $salida
                ? 'No se pudo registrar la salida. Revisa los datos e intenta de nuevo.'
                : 'No se pudo registrar la entrada. Revisa los datos e intenta de nuevo.';
        }
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

        };
?>

<div>
    <x-panel.pestanas-tienda activa="stock" />

    <x-panel.encabezado titulo="Stock local"
        subtitulo="Existencias, entradas y salidas de la sucursal, y el conteo físico." class="mb-6" />

    <div class="fp-pestanas fp-pestanas-seccion mb-6" role="tablist" aria-label="Stock local">
        <button type="button" role="tab" wire:click="$set('pestana', 'existencias')"
            aria-selected="{{ $pestana === 'existencias' ? 'true' : 'false' }}" data-pestana-stock="existencias"
            @class([
                'fp-pestana',
                'fp-pestana-activa' => $pestana === 'existencias',
            ])>Existencias</button>
        @if ($this->puedeContar)
            <button type="button" role="tab" wire:click="$set('pestana', 'conteo')"
                aria-selected="{{ $pestana === 'conteo' ? 'true' : 'false' }}" data-pestana-stock="conteo"
                @class([
                    'fp-pestana',
                    'fp-pestana-activa' => $pestana === 'conteo',
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
                            Talla {{ $linea['talla'] }}: {{ ($linea['tipo'] ?? 'entrada') === 'salida' ? '−' : '+' }}{{ $linea['cantidad'] }} ({{ $linea['antes'] }} → {{ $linea['despues'] }})
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
            Puedes consultar las existencias. Para registrar entradas o salidas de stock pídele el permiso al administrador.
        </x-panel.alerta>
    @endif

    @if ($this->puedeAjustarStock)
    <div class="mb-6">

        {{-- Un solo movimiento de stock: entrada o salida, con un solo buscador (modelo, marca, línea, SKU o código) --}}
        <div class="relative fp-tarjeta p-5 fp-formulario fp-aparecer" data-movimiento-stock>
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">Registrar movimiento</h3>
                    <p class="mt-0.5 text-xs text-slate-500">Da entrada a mercancía o descuenta piezas (merma, corrección). Cada movimiento queda en el historial de la talla.</p>
                </div>
                <div class="inline-flex rounded-lg bg-slate-100 p-0.5 text-xs" role="group" aria-label="Tipo de movimiento">
                    @foreach (['entrada' => 'Entrada', 'salida' => 'Salida'] as $clave => $texto)
                        <button type="button" data-tipo-movimiento="{{ $clave }}" aria-pressed="{{ $tipoMovimiento === $clave ? 'true' : 'false' }}"
                            wire:click="$set('tipoMovimiento', '{{ $clave }}')"
                            class="rounded-md px-3 py-1 font-medium transition {{ $tipoMovimiento === $clave ? 'bg-white text-fp-sidebar shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
                            {{ $texto }}
                        </button>
                    @endforeach
                </div>
            </div>

            @php
                $salida = $tipoMovimiento === 'salida';
                $terminoBusqueda = $this->terminoModelo;
                $elegido = $this->productoElegido;
                $tallasDelModelo = $this->tallasModelo;
                $marcadas = $tallasDelModelo->filter(fn ($t) => array_key_exists($t['id'], $modelo_cantidades));
                $chip = 'rounded-lg border px-3 py-1.5 text-sm font-semibold transition';
                $chipActivo = 'border-fp-primary bg-fp-primary text-white';
                $chipLibre = 'border-slate-300 bg-white text-slate-700 hover:border-fp-primary';
            @endphp

            <form wire:submit="registrarEntradaModelo" class="space-y-4" data-formulario-movimiento>
                <p class="text-xs text-slate-500">Los campos con <span class="fp-campo-requerido">*</span> son obligatorios.</p>

                {{-- 1. Modelo: un solo buscador (modelo, marca, línea, SKU o código) --}}
                <div class="fp-campo">
                    <label for="movimiento-buscar" class="block text-sm font-medium text-slate-700 mb-1">Modelo <span class="fp-campo-requerido" aria-label="obligatorio">*</span></label>

                    @if ($elegido)
                        <div class="flex items-center gap-3 rounded-xl border border-fp-primary/30 bg-white p-2" data-modelo-elegido>
                            <x-ui.imagen-ampliable :url="$this->fotoModelo" :alt="$elegido->nombre" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-fp-sidebar">{{ $elegido->nombre }}</p>
                                <p class="truncate text-xs text-slate-500">{{ collect([$elegido->modelo, $elegido->marca?->nombre])->filter()->implode(' · ') }}</p>
                            </div>
                            <button type="button" wire:click="limpiarModelo" class="fp-enlace text-xs" data-cambiar-modelo>Cambiar modelo</button>
                        </div>
                    @else
                        <div class="relative" x-data="{ abierto: false }" @click.outside="abierto = false" @keydown.escape="abierto = false">
                            <x-ui.buscador id="movimiento-buscar" wire:model.live.debounce.250ms="buscarModelo" wire:keydown.enter.prevent="entrarBusqueda"
                                @focus="abierto = true" @input="abierto = true"
                                placeholder="Escribe unas letras: modelo, marca, línea, SKU o código…"
                                etiqueta="Buscar modelo por nombre, marca, línea, SKU o código" class="w-full" data-buscador-modelo />

                            @if ($terminoBusqueda !== '')
                                @php
                                    $sugeridos = $this->productosModelo;
                                    $exacta = $this->varianteExacta;
                                    $fotosSugeridas = $this->fotosSugerencias;
                                @endphp
                                <div x-show="abierto" x-cloak role="listbox" aria-label="Modelos sugeridos" data-sugerencias-modelo
                                    class="mt-2 max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
                                    @if ($exacta)
                                        <button type="button" role="option" wire:key="sug-sku-{{ $exacta->id }}" wire:click="elegirSku({{ $exacta->id }})" data-sugerencia-sku
                                            class="flex w-full items-center gap-3 rounded-lg bg-fp-primary/5 px-3 py-2 text-left transition hover:bg-fp-primary/10">
                                            <span class="rounded-md bg-fp-primary px-1.5 py-0.5 text-[11px] font-semibold text-white">SKU</span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-semibold text-fp-sidebar">{{ $exacta->producto?->nombre }}</span>
                                                <span class="block truncate text-xs text-slate-500">{{ $exacta->sku }} · Talla {{ $exacta->talla?->valor ?? '—' }} · {{ $exacta->nombre_color_comercial ?: ($exacta->color?->nombre ?? 'Sin color') }}</span>
                                            </span>
                                        </button>
                                    @endif
                                    @foreach ($sugeridos as $sugerido)
                                        @php
                                            $lineas = $sugerido->publicacionesCampana->map(fn ($p) => $p->campana?->linea?->nombre)->filter()->unique()->take(2)->implode(', ');
                                            $detalle = collect([$sugerido->modelo, $sugerido->marca?->nombre, $lineas])->filter()->implode(' · ');
                                        @endphp
                                        <button type="button" role="option" wire:key="sug-{{ $sugerido->id }}" wire:click="elegirModelo({{ $sugerido->id }})" data-sugerencia-modelo
                                            class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left transition hover:bg-slate-50 focus:bg-slate-50 focus:outline-none">
                                            @if (! empty($fotosSugeridas[$sugerido->id]))
                                                <img src="{{ $fotosSugeridas[$sugerido->id] }}" alt="" loading="lazy" width="40" height="40" class="h-10 w-10 shrink-0 rounded-lg border border-slate-200 bg-white object-contain">
                                            @else
                                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-fp-page text-slate-400" aria-hidden="true">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.6-4.6a2 2 0 012.8 0L16 16m-2-2l1.6-1.6a2 2 0 012.8 0L20 14M14 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                                </span>
                                            @endif
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-semibold text-fp-sidebar">{{ $sugerido->nombre }}</span>
                                                <span class="block truncate text-xs text-slate-500">{{ $detalle }}</span>
                                            </span>
                                        </button>
                                    @endforeach
                                    @if (! $exacta && $sugeridos->isEmpty())
                                        <p class="px-3 py-3 text-sm text-slate-500" data-sin-sugerencias>No encontramos modelos con «{{ $terminoBusqueda }}». Prueba con otras letras o con el SKU.</p>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-slate-500">Escribe al menos 2 letras. Si escribes un SKU exacto, se marcan solos su color y su talla.</p>
                    @endif
                    @error('modelo_producto_id') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- 2. Color (recuadros, uno a la vez) --}}
                @if ($elegido)
                    <div class="fp-campo" data-colores-modelo>
                        <p class="block text-sm font-medium text-slate-700 mb-1" id="movimiento-color-etiqueta">Color <span class="fp-campo-requerido" aria-label="obligatorio">*</span></p>
                        <div class="flex flex-wrap gap-1.5" role="group" aria-labelledby="movimiento-color-etiqueta">
                            @foreach ($this->coloresModelo as $color)
                                <button type="button" wire:key="color-{{ $color['id'] }}" wire:click="elegirColor({{ $color['id'] }})" data-color-chip="{{ $color['id'] }}"
                                    aria-pressed="{{ (string) $color['id'] === $modelo_color_id ? 'true' : 'false' }}"
                                    class="{{ $chip }} {{ (string) $color['id'] === $modelo_color_id ? $chipActivo : $chipLibre }}">
                                    {{ $color['nombre'] }}
                                </button>
                            @endforeach
                        </div>
                        @error('modelo_color_id') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                @endif

                {{-- 3. Tallas (recuadros, selección múltiple) y cantidad de cada una --}}
                @if ($elegido && $modelo_color_id !== '')
                    <div class="fp-campo relative" data-tallas-modelo>
                        <x-ui.cargando objetivo="elegirModelo,elegirSku,elegirColor,limpiarModelo" />
                        @if ($tallasDelModelo->isNotEmpty())
                            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-medium text-slate-700" id="movimiento-tallas-etiqueta">Tallas <span class="fp-campo-requerido" aria-label="obligatorio">*</span> <span class="text-xs font-normal text-slate-500">(marca una o varias)</span></p>
                                <span class="flex items-center gap-3 text-xs">
                                    <button type="button" class="fp-enlace" wire:click="marcarTodasLasTallas" data-marcar-todas>Marcar todas</button>
                                    @if ($marcadas->isNotEmpty())
                                        <button type="button" class="fp-enlace" wire:click="quitarTallas" data-quitar-tallas>Quitar selección</button>
                                    @endif
                                </span>
                            </div>
                            <div class="flex flex-wrap gap-1.5" role="group" aria-labelledby="movimiento-tallas-etiqueta" data-selector-tallas>
                                @foreach ($tallasDelModelo as $talla)
                                    @php $marcada = array_key_exists($talla['id'], $modelo_cantidades); @endphp
                                    <button type="button" wire:key="chip-talla-{{ $talla['id'] }}" wire:click="alternarTalla({{ $talla['id'] }})" data-talla-chip="{{ $talla['id'] }}"
                                        aria-pressed="{{ $marcada ? 'true' : 'false' }}" aria-label="Talla {{ $talla['talla'] }}, hay {{ $talla['existencia'] }}"
                                        class="min-w-14 rounded-lg border px-2.5 py-1.5 text-center transition {{ $marcada ? $chipActivo : $chipLibre }}">
                                        <span class="block text-sm font-semibold leading-tight">{{ $talla['talla'] }}</span>
                                        <span class="block text-[10px] leading-tight {{ $marcada ? 'text-white/80' : 'text-slate-500' }}">Hay {{ $talla['existencia'] }}</span>
                                    </button>
                                @endforeach
                            </div>

                            @if ($marcadas->isNotEmpty())
                                <p class="mb-2 mt-4 text-xs text-slate-500">{{ $salida ? 'Cantidad que sale de cada talla marcada.' : 'Cantidad que entra de cada talla marcada.' }}</p>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2" data-rejilla-tallas>
                                    @foreach ($marcadas as $talla)
                                        <label class="rounded-lg border border-fp-primary/40 bg-white p-2 text-center transition focus-within:border-fp-primary" wire:key="talla-{{ $talla['id'] }}">
                                            <span class="block text-sm font-semibold text-fp-sidebar">Talla {{ $talla['talla'] }}</span>
                                            <span class="block text-[11px] text-slate-500">Hay {{ $talla['existencia'] }}</span>
                                            <input type="number" min="1" step="1" inputmode="numeric" data-cantidad-talla
                                                wire:model="modelo_cantidades.{{ $talla['id'] }}" aria-label="Cantidad talla {{ $talla['talla'] }}"
                                                class="mt-1 w-full rounded-md py-1 text-center text-sm focus:ring-fp-primary" placeholder="0">
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        @else
                            <p class="text-xs text-slate-500">Este modelo y color no tiene tallas registradas.</p>
                        @endif
                        @error('modelo_cantidades') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                        @error('modelo_cantidades.*') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="fp-campo">
                    <label for="modelo-motivo" class="block text-sm font-medium text-slate-700 mb-1">
                        @if ($salida)
                            Motivo <span class="fp-campo-requerido" aria-label="obligatorio">*</span> <span class="text-xs font-normal text-slate-500">(para todas las tallas)</span>
                        @else
                            Motivo (opcional, para todas las tallas)
                        @endif
                    </label>
                    <input id="modelo-motivo" type="text" maxlength="300" wire:model="modelo_motivo"
                           class="w-full rounded-lg text-sm focus:ring-fp-primary" placeholder="{{ $salida ? 'Ej. merma, corrección de conteo' : 'Ej. compra a fábrica' }}">
                    @error('modelo_motivo') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="fp-btn {{ $salida ? 'fp-btn-oscuro' : 'fp-btn-primario' }}" data-registrar-modelo wire:loading.attr="disabled" wire:target="registrarEntradaModelo">
                    <span wire:loading.delay.remove wire:target="registrarEntradaModelo">{{ $salida ? 'Registrar salida de las tallas' : 'Registrar todas las tallas' }}</span>
                    <span wire:loading.delay wire:target="registrarEntradaModelo">Registrando…</span>
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
