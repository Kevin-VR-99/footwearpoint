<?php

use App\Models\ClienteDirecto;
use App\Models\ProductoCampana;
use App\Services\Catalogo\CatalogoVisible;
use App\Services\Catalogo\ImagenDeProducto;
use App\Services\Catalogo\PrecioEfectivo;
use App\Models\StockLocal;
use App\Services\Vale\BuscarValeParaAplicarAction;
use App\Services\VentaDirecta\RegistrarVentaDirectaService;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Venta local — FootwearPoint')] class extends Component
{
    /** Tasa documentada en el Plan de Tareas. El modelo no tiene columna de tasa. */
    private const TASA_IVA = 0.16;

    public string $busqueda = '';
    public string $cliente_directo_id = '';
    public string $metodo_pago = 'efectivo';

    /** TG-250: folio que escribe el empleado y vale ya validado (solo para mostrar). */
    public string $valeFolio = '';
    public ?array $vale = null;

    /**
     * Carrito en memoria. El precio se guarda solo para mostrarlo: el que se
     * cobra lo vuelve a resolver el servidor.
     */
    public array $lineas = [];

    public string $mensaje = '';
    public string $aviso = '';
    public string $errorMsg = '';

    /** La venta recién cobrada, para el enlace a su comprobante (E7-02). */
    #[Locked]
    public ?int $ultimaVentaId = null;

    public function mount()
    {
        if (! Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }
    }

    /**
     * Solo se ofrece lo que hay físicamente en la sucursal: la venta directa
     * descuenta stock_local, nunca vende bajo pedido.
     *
     * Cada renglón es la combinación de una existencia con una publicación de
     * catálogo de su producto, porque el precio sale de producto_campana. Si un
     * mismo producto está publicado en dos campañas, aparece dos veces con su
     * precio: se prefiere que el empleado elija a inventar una regla que
     * ningún archivo define.
     *
     * Talla y color se resuelven igual que en RegistrarVentaDirectaService,
     * para que lo que se ve al vender y lo que se guarda en la venta digan
     * exactamente lo mismo.
     */
    public function getDisponiblesProperty()
    {
        $existencias = StockLocal::query()
            ->with('variante.producto:id,nombre')
            ->where('cantidad_disponible', '>', 0)
            ->get()
            ->filter(fn ($e) => $e->variante !== null);

        if ($existencias->isEmpty()) {
            return collect();
        }

        $variantes = $existencias->pluck('variante');

        // Solo lo que esta distribuidora vende del catálogo compartido
        // (TG-213). El precio es el de menudeo del catálogo (D8).
        $publicaciones = app(CatalogoVisible::class)->consulta()
            ->whereIn('producto_id', $variantes->pluck('producto_id')->unique()->all())
            ->get()
            ->groupBy('producto_id');

        $precios = app(PrecioEfectivo::class)->precargar($publicaciones->flatten());
        $fotos = app(ImagenDeProducto::class)->porProductoCampana($publicaciones->flatten()->pluck('id'));

        // tallas y colores son catálogos GLOBALES: no llevan distribuidora_id.
        // Salen de CatalogoCache (sin consulta mientras no cambie el catálogo).
        $catalogo = app(\App\Support\Cache\CatalogoCache::class);
        $tallas = $catalogo->modelosTalla($variantes->pluck('talla_id'));
        $colores = $catalogo->modelosColor($variantes->pluck('color_id'));

        $termino = mb_strtolower(trim($this->busqueda));

        return $existencias
            ->flatMap(function ($existencia) use ($publicaciones, $tallas, $colores, $precios, $fotos) {
                $variante = $existencia->variante;
                $delProducto = $publicaciones->get($variante->producto_id, collect());

                $talla = $tallas->get($variante->talla_id);
                $color = $colores->get($variante->color_id);

                $textoTalla = $talla !== null ? trim($talla->sistema . ' ' . $talla->valor) : '';
                $textoColor = (string) ($variante->nombre_color_comercial ?: ($color->nombre ?? ''));

                return $delProducto->map(fn ($publicacion) => [
                    'clave' => $existencia->variante_id . '-' . $publicacion->id,
                    'variante_id' => (int) $existencia->variante_id,
                    'producto_campana_id' => (int) $publicacion->id,
                    'sku' => (string) $variante->sku,
                    'imagen' => $fotos[$publicacion->id] ?? '',
                    'codigo_catalogo' => (string) $publicacion->codigo_catalogo,
                    'nombre' => (string) ($variante->producto?->nombre ?? ''),
                    'talla' => $textoTalla,
                    'color' => $textoColor,
                    'precio' => $precios->menudeo($publicacion),
                    'disponible' => (int) $existencia->cantidad_disponible,
                ]);
            })
            ->filter(function ($fila) use ($termino) {
                if ($termino === '') {
                    return true;
                }

                return str_contains(mb_strtolower($fila['sku']), $termino)
                    || str_contains(mb_strtolower($fila['codigo_catalogo']), $termino)
                    || str_contains(mb_strtolower($fila['nombre']), $termino)
                    || str_contains(mb_strtolower($fila['talla']), $termino)
                    || str_contains(mb_strtolower($fila['color']), $termino);
            })
            ->sortBy('sku')
            ->values();
    }

    /** Corte de caja: avisar si quien cobra no tiene caja abierta (solo si puede operar caja). */
    public function getSinCajaAbiertaProperty(): bool
    {
        try {
            return app(\App\Services\Auth\PermisoModuloService::class)->puede(Auth::user(), \App\Services\Auth\PermisoModuloService::CAJA_OPERAR)
                && app(\App\Services\Caja\CajaService::class)->abiertaDe() === null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getClientesProperty()
    {
        return ClienteDirecto::query()
            ->where('estado', 'activo')
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
    }

    /** Suma de líneas. Estos importes YA INCLUYEN IVA. */
    public function getTotalProperty(): float
    {
        return round(collect($this->lineas)->sum(fn ($l) => $l['precio'] * $l['cantidad']), 2);
    }

    /** TG-250: lo que cubre el vale (hasta su saldo). Solo para mostrar. */
    public function getMontoValeProperty(): float
    {
        return $this->vale === null ? 0.0 : round(min((float) $this->vale['saldo'], $this->total), 2);
    }

    public function getPorCobrarProperty(): float
    {
        return round($this->total - $this->montoVale, 2);
    }

    public function aplicarVale(BuscarValeParaAplicarAction $buscar): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $vale = $buscar->ejecutar($this->valeFolio);

            if ($this->cliente_directo_id !== '' && (int) $this->cliente_directo_id !== (int) $vale->cliente_directo_id) {
                $this->errorMsg = 'El vale '.$vale->folio.' es de otro cliente. Elige a su dueño o quita el vale.';

                return;
            }

            $this->vale = [
                'folio' => (string) $vale->folio,
                'saldo' => (float) $vale->saldo_actual,
                'vence' => $vale->fecha_vencimiento?->format('d/m/Y'),
                'cliente' => (string) ($vale->clienteDirecto?->nombre ?? ''),
            ];
            $this->valeFolio = (string) $vale->folio;
            // La venta queda a nombre del dueño del vale (decisión del PO).
            $this->cliente_directo_id = (string) $vale->cliente_directo_id;
        } catch (\Throwable $e) {
            $this->vale = null;
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo revisar el vale.');
        }
    }

    public function quitarVale(): void
    {
        $this->vale = null;
        $this->valeFolio = '';
        $this->errorMsg = '';
    }

    /**
     * Desglose SOLO para mostrar. No corresponde a ninguna columna:
     * ventas_directas.subtotal ya incluye IVA.
     */
    public function getBaseGravableProperty(): float
    {
        return round($this->total / (1 + self::TASA_IVA), 2);
    }

    public function getIvaProperty(): float
    {
        return round($this->total - $this->baseGravable, 2);
    }

    /**
     * Se dispara cada vez que el empleado escribe una cantidad. Como el campo
     * está enlazado con wire:model, lo que se corrija aquí SÍ se ve en pantalla.
     */
    public function updatedLineas(): void
    {
        foreach ($this->lineas as $clave => $linea) {
            $cantidad = (int) $linea['cantidad'];

            if ($cantidad < 1) {
                $this->lineas[$clave]['cantidad'] = 1;
                continue;
            }

            if ($cantidad > $linea['disponible']) {
                $this->lineas[$clave]['cantidad'] = $linea['disponible'];
                // Aviso, no error: la venta sigue siendo válida con la
                // cantidad corregida, que ya se ve en el campo.
                $this->aviso = 'Solo hay ' . $linea['disponible'] . ' piezas de ' . $linea['sku']
                    . ' (' . $linea['talla'] . '). Se ajustó la cantidad al máximo disponible.';
                continue;
            }

            $this->lineas[$clave]['cantidad'] = $cantidad;
        }
    }

    public function agregar(string $clave)
    {
        $this->mensaje = '';
        $this->aviso = '';
        $this->errorMsg = '';

        $fila = $this->disponibles->firstWhere('clave', $clave);

        if ($fila === null) {
            $this->errorMsg = 'Ese producto ya no está disponible.';

            return;
        }

        if (isset($this->lineas[$clave])) {
            $nueva = $this->lineas[$clave]['cantidad'] + 1;

            if ($nueva > $fila['disponible']) {
                $this->aviso = 'Solo hay ' . $fila['disponible'] . ' piezas de ' . $fila['sku']
                    . ' (' . $fila['talla'] . ').';

                return;
            }

            $this->lineas[$clave]['cantidad'] = $nueva;

            return;
        }

        $this->lineas[$clave] = [
            'variante_id' => $fila['variante_id'],
            'producto_campana_id' => $fila['producto_campana_id'],
            'sku' => $fila['sku'],
            'imagen' => $fila['imagen'],
            'nombre' => $fila['nombre'],
            'talla' => $fila['talla'],
            'color' => $fila['color'],
            'precio' => $fila['precio'],
            'cantidad' => 1,
            'disponible' => $fila['disponible'],
        ];
    }

    public function quitar(string $clave)
    {
        unset($this->lineas[$clave]);
        $this->aviso = '';
        $this->errorMsg = '';
    }

    public function limpiar()
    {
        $this->lineas = [];
        $this->cliente_directo_id = '';
        $this->metodo_pago = 'efectivo';
        $this->vale = null;
        $this->valeFolio = '';
        $this->mensaje = '';
        $this->aviso = '';
        $this->errorMsg = '';
    }

    public function cobrar(RegistrarVentaDirectaService $ventas)
    {
        $this->mensaje = '';
        $this->aviso = '';
        $this->errorMsg = '';

        if ($this->lineas === []) {
            $this->errorMsg = 'Agrega al menos un producto antes de cobrar.';

            return;
        }

        $this->validate([
            'metodo_pago' => ['required', 'in:efectivo,transferencia,tarjeta,otro'],
            'cliente_directo_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'metodo_pago.required' => 'Indica con qué método se cobró la venta.',
        ]);

        // Última red de seguridad antes de cobrar: la existencia pudo cambiar
        // desde que se agregó la línea al carrito (otra caja, un ajuste). El
        // servicio también lo valida y revierte todo, pero así el empleado ve
        // un mensaje claro en vez de un error genérico.
        $existencias = StockLocal::query()
            ->whereIn('variante_id', collect($this->lineas)->pluck('variante_id')->all())
            ->get()
            ->keyBy('variante_id');

        foreach ($this->lineas as $clave => $linea) {
            $disponible = (int) ($existencias[$linea['variante_id']]->cantidad_disponible ?? 0);

            if ($linea['cantidad'] > $disponible) {
                $this->lineas[$clave]['disponible'] = $disponible;
                $this->lineas[$clave]['cantidad'] = max(1, $disponible);
                $this->errorMsg = 'La existencia de ' . $linea['sku'] . ' cambió: quedan ' . $disponible
                    . '. Revisa la venta y vuelve a cobrar.';

                return;
            }
        }

        $lineasParaServicio = collect($this->lineas)
            ->map(fn ($l) => [
                'variante_id' => $l['variante_id'],
                'producto_campana_id' => $l['producto_campana_id'],
                'cantidad' => $l['cantidad'],
            ])
            ->values()
            ->all();

        try {
            $resultado = $ventas->registrar(
                $lineasParaServicio,
                $this->metodo_pago,
                $this->cliente_directo_id !== '' ? (int) $this->cliente_directo_id : null,
                $this->vale['folio'] ?? null,
            );

            // Se arma el mensaje ANTES de limpiar y se reasigna después:
            // limpiar() borra mensaje, aviso y errorMsg.
            $confirmacion = 'Venta ' . $resultado->venta->folio . ' registrada por $'
                . number_format((float) $resultado->venta->total, 2) . '.';

            if ($resultado->vale !== null) {
                $confirmacion .= ' Vale ' . $resultado->vale->folio . ': -$' . number_format($resultado->montoVale, 2)
                    . ' (le quedan $' . number_format((float) $resultado->vale->saldo_actual, 2) . ').';
            }

            $confirmacion .= $resultado->pago !== null
                ? ' Pago ' . $resultado->pago->folio . ' (' . $resultado->pago->metodo . ') por $' . number_format((float) $resultado->pago->monto, 2) . '.'
                : ' Pagado con vale.';

            $this->limpiar();
            $this->mensaje = $confirmacion;
            $this->ultimaVentaId = (int) $resultado->venta->id;
        } catch (\Throwable $e) {
            // TG-224 (G3): MensajeError deja el detalle técnico en el log.
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo registrar la venta. Revisa las existencias e intenta de nuevo.');
        }
    }
};
?>

<div>
    <x-panel.encabezado titulo="Venta local"
        subtitulo="Venta de contado con entrega inmediata. Solo se ofrece lo que hay en existencia." class="mb-6" />

    @if (\Illuminate\Support\Facades\Route::has('caja.index') && $this->sinCajaAbierta)
        <x-panel.alerta tipo="aviso" class="mb-4">
            No tienes una caja abierta: lo que cobres no entrará a ningún corte.
            <a href="{{ route('caja.index') }}" wire:navigate class="ml-1 font-semibold underline">Abrir caja</a>
        </x-panel.alerta>
    @endif

    @if ($mensaje)
        <x-panel.alerta tipo="exito" class="mb-4">
            {{ $mensaje }}
            @if ($ultimaVentaId)
                {{-- Pestaña nueva: el punto de venta queda listo para la siguiente venta. --}}
                <a href="{{ route('ventas-directas.comprobante', $ultimaVentaId) }}" target="_blank"
                    class="ml-2 font-semibold underline">Ver comprobante</a>
            @endif
        </x-panel.alerta>
    @endif

    @if ($aviso)
        <x-panel.alerta tipo="aviso" class="mb-4">
            {{ $aviso }}
        </x-panel.alerta>
    @endif

    @if ($errorMsg)
        <x-panel.alerta tipo="error" class="mb-4">
            {{ $errorMsg }}
        </x-panel.alerta>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

        {{-- Buscador de productos --}}
        <div class="relative lg:col-span-3 fp-tarjeta overflow-hidden fp-formulario fp-aparecer">
            <x-ui.cargando objetivo="busqueda,agregar,gotoPage,nextPage,previousPage" />
            <div class="px-5 py-3 border-b border-slate-100">
                <x-ui.buscador wire:model.live.debounce.300ms="busqueda"
                       placeholder="Buscar por SKU, código de catálogo, modelo, talla o color…" etiqueta="Buscar productos" />
            </div>

            <div class="overflow-x-auto max-h-[28rem] overflow-y-auto">
                <table class="fp-tabla min-w-full text-sm">
                    <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted sticky top-0">
                        <tr>
                            <th class="text-left font-medium px-4 py-3">SKU</th>
                            <th class="text-left font-medium px-4 py-3">Producto</th>
                            <th class="text-left font-medium px-4 py-3">Talla</th>
                            <th class="text-left font-medium px-4 py-3">Color</th>
                            <th class="text-right font-medium px-4 py-3">Precio</th>
                            <th class="text-right font-medium px-4 py-3">Existencia</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($this->disponibles as $fila)
                            <tr wire:key="disp-{{ $fila['clave'] }}">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <x-ui.miniatura :url="$fila['imagen'] ?: null" :alt="$fila['nombre'] !== '' ? $fila['nombre'] : 'Producto'" />
                                        <span class="font-mono text-xs">{{ $fila['sku'] }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-slate-900">{{ $fila['nombre'] !== '' ? $fila['nombre'] : '—' }}</div>
                                    <div class="text-xs text-slate-500">{{ $fila['codigo_catalogo'] }}</div>
                                </td>
                                <td class="px-4 py-3 font-medium text-slate-900">
                                    {{ $fila['talla'] !== '' ? $fila['talla'] : '—' }}
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    {{ $fila['color'] !== '' ? $fila['color'] : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">${{ number_format($fila['precio'], 2) }}</td>
                                <td class="px-4 py-3 text-right text-slate-600">{{ $fila['disponible'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" wire:click="agregar('{{ $fila['clave'] }}')"
                                            class="fp-btn fp-btn-primario fp-btn-sm">
                                        Agregar
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-slate-500">
                                    No hay productos con existencia que coincidan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Venta en curso --}}
        <div class="lg:col-span-2 fp-tarjeta p-5 h-fit fp-aparecer">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-slate-800">Venta en curso</h3>
                @if ($lineas !== [])
                    <button type="button" wire:click="limpiar"
                            class="text-xs text-slate-500 hover:text-fp-danger transition-colors">Vaciar</button>
                @endif
            </div>

            @if ($lineas === [])
                <p class="text-sm text-slate-500 py-6 text-center">
                    <span class="lg:hidden">Agrega productos desde la lista de arriba.</span><span class="hidden lg:inline">Agrega productos desde la lista de la izquierda.</span>
                </p>
            @else
                <div class="space-y-3 mb-4">
                    @foreach ($lineas as $clave => $linea)
                        <div class="flex items-start gap-3 pb-3 border-b border-slate-100 last:border-0"
                             wire:key="linea-{{ $clave }}">
                            <x-ui.miniatura :url="($linea['imagen'] ?? '') !== '' ? $linea['imagen'] : null" :alt="$linea['nombre'] !== '' ? $linea['nombre'] : 'Producto'" />
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-xs text-slate-500">{{ $linea['sku'] }}</p>
                                <p class="text-sm text-slate-900 truncate">
                                    {{ $linea['nombre'] !== '' ? $linea['nombre'] : '—' }}
                                </p>
                                <p class="text-xs text-slate-600">
                                    Talla {{ $linea['talla'] !== '' ? $linea['talla'] : '—' }}
                                    @if ($linea['color'] !== '') · {{ $linea['color'] }} @endif
                                </p>
                                <p class="text-xs text-slate-500">
                                    ${{ number_format($linea['precio'], 2) }} c/u
                                    · máx. {{ $linea['disponible'] }}
                                </p>
                            </div>

                            {{-- wire:model.live (300 ms de pausa): lo que el servidor corrija se ve aquí casi de inmediato sin un viaje por tecla. --}}
                            <input type="number" min="1" max="{{ $linea['disponible'] }}"
                                   wire:model.live.debounce.300ms="lineas.{{ $clave }}.cantidad"
                                   class="w-16 rounded-lg text-sm text-center focus:ring-fp-primary">

                            <div class="text-right w-20">
                                <p class="text-sm font-medium text-slate-900">
                                    ${{ number_format($linea['precio'] * $linea['cantidad'], 2) }}
                                </p>
                                <button type="button" wire:click="quitar('{{ $clave }}')"
                                        class="text-xs text-slate-400 hover:text-fp-danger transition-colors">Quitar</button>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{--
                    Los precios del catálogo YA INCLUYEN IVA. Por eso estos dos
                    campos se llaman "Base gravable" e "IVA (16%)" y nunca
                    "Subtotal": esa palabra significa algo distinto en la
                    columna real de la base de datos.
                --}}
                <div class="border-t border-slate-200 pt-3 space-y-1 text-sm">
                    <div class="flex justify-between text-slate-600">
                        <span>Base gravable</span>
                        <span>${{ number_format($this->baseGravable, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>IVA (16%)</span>
                        <span>${{ number_format($this->iva, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-base font-bold text-slate-900 pt-2 border-t border-slate-100">
                        <span>Total</span>
                        <span>${{ number_format($this->total, 2) }}</span>
                    </div>
                    @if ($vale)
                        <div class="flex justify-between text-emerald-700">
                            <span>Vale {{ $vale['folio'] }}</span>
                            <span>&minus;${{ number_format($this->montoVale, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-base font-bold text-slate-900">
                            <span>Por cobrar</span>
                            <span>${{ number_format($this->porCobrar, 2) }}</span>
                        </div>
                    @endif
                </div>

                <div class="mt-5 space-y-4">
                    {{-- TG-250: vale de cliente directo por folio. --}}
                    <div class="fp-campo">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Vale (opcional)</label>
                        @if ($vale)
                            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                                <p class="font-medium">{{ $vale['folio'] }} · saldo ${{ number_format($vale['saldo'], 2) }}</p>
                                <p class="text-xs">{{ $vale['cliente'] }}@if ($vale['vence']) · vence {{ $vale['vence'] }}@endif</p>
                                @if ($vale['saldo'] > $this->total)
                                    <p class="text-xs">Le quedarán ${{ number_format($vale['saldo'] - $this->montoVale, 2) }} en el vale.</p>
                                @endif
                                <button type="button" wire:click="quitarVale" class="mt-1 text-xs text-slate-600 underline">Quitar vale</button>
                            </div>
                        @else
                            <div class="flex gap-2">
                                <input type="text" wire:model="valeFolio" wire:keydown.enter="aplicarVale" placeholder="Folio del vale"
                                       class="w-full rounded-lg text-sm focus:ring-fp-primary">
                                <button type="button" wire:click="aplicarVale" class="fp-btn fp-btn-contorno px-3">Aplicar</button>
                            </div>
                        @endif
                    </div>

                    @if ($this->porCobrar > 0)
                    <div class="fp-campo">
                        <label for="index-metodo-pago" class="block text-sm font-medium text-slate-700 mb-1">Método de pago</label>
                        <select id="index-metodo-pago" wire:model="metodo_pago"
                                class="w-full rounded-lg text-sm focus:ring-fp-primary">
                            <option value="efectivo">Efectivo</option>
                            <option value="transferencia">Transferencia</option>
                            <option value="tarjeta">Tarjeta</option>
                            <option value="otro">Otro</option>
                        </select>
                        @error('metodo_pago') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    @endif

                    <div class="fp-campo">
                        <label for="index-cliente-directo-id" class="block text-sm font-medium text-slate-700 mb-1">Cliente (opcional)</label>
                        <select id="index-cliente-directo-id" wire:model="cliente_directo_id" @disabled($vale)
                                class="w-full rounded-lg text-sm focus:ring-fp-primary">
                            <option value="">— Venta de mostrador —</option>
                            @foreach ($this->clientes as $cliente)
                                <option value="{{ $cliente->id }}">{{ $cliente->nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <button type="button" wire:click="cobrar" wire:loading.attr="disabled"
                            class="fp-btn fp-btn-primario w-full py-2.5">
                        <span wire:loading.remove wire:target="cobrar">
                            @if ($this->porCobrar > 0)
                                Cobrar ${{ number_format($this->porCobrar, 2) }}
                            @else
                                Registrar venta pagada con vale
                            @endif
                        </span>
                        <span wire:loading wire:target="cobrar">Registrando…</span>
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>