<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\ClienteDirecto;
use App\Models\Color;
use App\Models\ProductoCampana;
use App\Models\StockLocal;
use App\Models\Talla;
use App\Services\VentaDirecta\RegistrarVentaDirectaService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Punto de Venta — FootwearPoint')] class extends Component
{
    /** Tasa documentada en el Plan de Tareas. El modelo no tiene columna de tasa. */
    private const TASA_IVA = 0.16;

    public string $busqueda = '';
    public string $cliente_directo_id = '';
    public string $metodo_pago = 'efectivo';

    /**
     * Carrito en memoria. El precio se guarda solo para mostrarlo: el que se
     * cobra lo vuelve a resolver el servidor.
     */
    public array $lineas = [];

    public string $mensaje = '';
    public string $aviso = '';
    public string $errorMsg = '';

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
            ->with('variante')
            ->where('cantidad_disponible', '>', 0)
            ->get()
            ->filter(fn ($e) => $e->variante !== null);

        if ($existencias->isEmpty()) {
            return collect();
        }

        $variantes = $existencias->pluck('variante');

        $publicaciones = ProductoCampana::query()
            ->where('publicado', true)
            ->whereIn('producto_id', $variantes->pluck('producto_id')->unique()->all())
            ->get()
            ->groupBy('producto_id');

        // tallas y colores son catálogos GLOBALES: no llevan distribuidora_id.
        $tallas = Talla::query()
            ->whereIn('id', $variantes->pluck('talla_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        $colores = Color::query()
            ->whereIn('id', $variantes->pluck('color_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        $termino = mb_strtolower(trim($this->busqueda));

        return $existencias
            ->flatMap(function ($existencia) use ($publicaciones, $tallas, $colores) {
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
                    'codigo_catalogo' => (string) $publicacion->codigo_catalogo,
                    'nombre' => (string) ($variante->producto?->nombre ?? ''),
                    'talla' => $textoTalla,
                    'color' => $textoColor,
                    'precio' => round((float) $publicacion->precio_minorista_sugerido, 2),
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
            );

            // Se arma el mensaje ANTES de limpiar y se reasigna después:
            // limpiar() borra mensaje, aviso y errorMsg.
            $confirmacion = 'Venta ' . $resultado->venta->folio . ' registrada por $'
                . number_format((float) $resultado->venta->total, 2)
                . '. Pago ' . $resultado->pago->folio . ' (' . $resultado->pago->metodo . ').';

            $this->limpiar();
            $this->mensaje = $confirmacion;
        } catch (OperacionInvalidaException $e) {
            $this->errorMsg = $e->getMessage();
        } catch (\Throwable $e) {
            $this->errorMsg = 'No se pudo registrar la venta. Revisa las existencias e intenta de nuevo.';
        }
    }
};
?>

<div>
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-slate-900">Punto de Venta</h2>
        <p class="text-sm text-slate-500 mt-1">
            Venta de contado con entrega inmediata. Solo se ofrece lo que hay en existencia.
        </p>
    </div>

    @if ($mensaje)
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 text-green-800 px-4 py-3 text-sm">
            {{ $mensaje }}
        </div>
    @endif

    @if ($aviso)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3 text-sm">
            {{ $aviso }}
        </div>
    @endif

    @if ($errorMsg)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">
            {{ $errorMsg }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

        {{-- Buscador de productos --}}
        <div class="lg:col-span-3 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100">
                <input type="search" wire:model.live.debounce.300ms="busqueda"
                       class="w-full rounded-lg border-slate-300 text-sm focus:border-marca-primario focus:ring-marca-primario"
                       placeholder="Buscar por SKU, código de catálogo, modelo, talla o color…">
            </div>

            <div class="overflow-x-auto max-h-[28rem] overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 sticky top-0">
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
                            <tr class="hover:bg-slate-50" wire:key="disp-{{ $fila['clave'] }}">
                                <td class="px-4 py-3 font-mono text-xs">{{ $fila['sku'] }}</td>
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
                                            class="rounded-lg bg-marca-primario text-white px-3 py-1.5 text-xs font-medium hover:bg-blue-700">
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
        <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 shadow-sm p-5 h-fit">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-slate-800">Venta en curso</h3>
                @if ($lineas !== [])
                    <button type="button" wire:click="limpiar"
                            class="text-xs text-slate-500 hover:text-red-600">Vaciar</button>
                @endif
            </div>

            @if ($lineas === [])
                <p class="text-sm text-slate-500 py-6 text-center">
                    Agrega productos desde la lista de la izquierda.
                </p>
            @else
                <div class="space-y-3 mb-4">
                    @foreach ($lineas as $clave => $linea)
                        <div class="flex items-start gap-3 pb-3 border-b border-slate-100 last:border-0"
                             wire:key="linea-{{ $clave }}">
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

                            {{-- wire:model.live: lo que el servidor corrija se ve aquí de inmediato. --}}
                            <input type="number" min="1" max="{{ $linea['disponible'] }}"
                                   wire:model.live="lineas.{{ $clave }}.cantidad"
                                   class="w-16 rounded-lg border-slate-300 text-sm text-center focus:border-marca-primario focus:ring-marca-primario">

                            <div class="text-right w-20">
                                <p class="text-sm font-medium text-slate-900">
                                    ${{ number_format($linea['precio'] * $linea['cantidad'], 2) }}
                                </p>
                                <button type="button" wire:click="quitar('{{ $clave }}')"
                                        class="text-xs text-slate-400 hover:text-red-600">Quitar</button>
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
                </div>

                <div class="mt-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Método de pago</label>
                        <select wire:model="metodo_pago"
                                class="w-full rounded-lg border-slate-300 text-sm focus:border-marca-primario focus:ring-marca-primario">
                            <option value="efectivo">Efectivo</option>
                            <option value="transferencia">Transferencia</option>
                            <option value="tarjeta">Tarjeta</option>
                            <option value="otro">Otro</option>
                        </select>
                        @error('metodo_pago') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Cliente (opcional)</label>
                        <select wire:model="cliente_directo_id"
                                class="w-full rounded-lg border-slate-300 text-sm focus:border-marca-primario focus:ring-marca-primario">
                            <option value="">— Venta de mostrador —</option>
                            @foreach ($this->clientes as $cliente)
                                <option value="{{ $cliente->id }}">{{ $cliente->nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <button type="button" wire:click="cobrar" wire:loading.attr="disabled"
                            class="w-full rounded-lg bg-marca-primario text-white px-4 py-2.5 text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="cobrar">
                            Cobrar ${{ number_format($this->total, 2) }}
                        </span>
                        <span wire:loading wire:target="cobrar">Registrando…</span>
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>