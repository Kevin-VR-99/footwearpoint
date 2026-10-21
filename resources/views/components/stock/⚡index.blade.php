<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Variante;
use App\Services\Catalogo\ImagenDeProducto;
use App\Services\Stock\StockService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Stock local — FootwearPoint')] class extends Component
{
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
    }

    /**
     * Toda la lógica vive en StockService, el mismo que usa la API
     * (convención 1.5: la vista muestra, el servicio decide).
     */
    public function getExistenciasProperty()
    {
        try {
            $query = app(StockService::class)->consultar();

            if ($this->busqueda !== '') {
                $termino = $this->busqueda;
                $query->whereHas('variante', fn ($q) => $q->where('sku', 'like', "%{$termino}%"));
            }

            if ($this->soloBajo) {
                // Mínimo efectivo: el propio si es > 0; si no, el general.
                $general = $this->minimoGeneral;
                $query->whereRaw('cantidad_disponible < (CASE WHEN stock_minimo > 0 THEN stock_minimo ELSE ? END)', [$general]);
            }

            return $query->with('variante.talla', 'variante.color')->get();
        } catch (OperacionInvalidaException $e) {
            return collect();
        }
    }

    /** @return array<int, string> producto_id => url */
    public function getFotosProperty(): array
    {
        return app(ImagenDeProducto::class)->porProducto(
            $this->existencias->map(fn ($existencia) => $existencia->variante?->producto_id),
        );
    }

    public function getVariantesProperty()
    {
        return Variante::query()
            ->orderBy('sku')
            ->get(['id', 'sku', 'producto_id']);
    }

    public function getMinimoGeneralProperty(): int
    {
        return (int) (\App\Models\ConfiguracionDistribuidora::query()->value('stock_minimo_general') ?? 0);
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
        subtitulo="Entradas de mercancía, existencias por variante y ajustes manuales de la sucursal principal." class="mb-6" />

    @if ($mensaje)
        <x-panel.alerta tipo="exito" class="mb-4">
            {{ $mensaje }}
        </x-panel.alerta>
    @endif

    @if ($errorMsg)
        <x-panel.alerta tipo="error" class="mb-4">
            {{ $errorMsg }}
        </x-panel.alerta>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- Entrada de mercancía --}}
        <div class="fp-tarjeta p-5 fp-formulario fp-aparecer">
            <h3 class="text-sm font-semibold text-slate-800 mb-4">Registrar entrada</h3>

            <form wire:submit="registrarEntrada" class="space-y-4">
                <div class="fp-campo">
                    <label for="index-entrada-variante-id" class="block text-sm font-medium text-slate-700 mb-1">Variante</label>
                    <select id="index-entrada-variante-id" wire:model="entrada_variante_id"
                            class="w-full rounded-lg text-sm focus:ring-fp-primary">
                        <option value="">— Seleccionar variante —</option>
                        @foreach ($this->variantes as $variante)
                            <option value="{{ $variante->id }}">{{ $variante->sku }}</option>
                        @endforeach
                    </select>
                    @error('entrada_variante_id') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="fp-campo">
                    <label for="index-entrada-cantidad" class="block text-sm font-medium text-slate-700 mb-1">Cantidad</label>
                    <input id="index-entrada-cantidad" type="number" min="1" step="1" wire:model="entrada_cantidad"
                           class="w-full rounded-lg text-sm focus:ring-fp-primary"
                           placeholder="10">
                    @error('entrada_cantidad') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="fp-campo">
                    <label for="index-entrada-motivo" class="block text-sm font-medium text-slate-700 mb-1">Motivo (opcional)</label>
                    <input id="index-entrada-motivo" type="text" maxlength="300" wire:model="entrada_motivo"
                           class="w-full rounded-lg text-sm focus:ring-fp-primary"
                           placeholder="Ej. compra a fábrica">
                </div>

                <button type="submit"
                        class="fp-btn fp-btn-primario">
                    Registrar entrada
                </button>
            </form>
        </div>

        {{-- Ajuste manual --}}
        <div class="fp-tarjeta p-5 fp-formulario fp-aparecer">
            <h3 class="text-sm font-semibold text-slate-800 mb-4">Ajuste manual</h3>

            <form wire:submit="registrarAjuste" class="space-y-4">
                <div class="fp-campo">
                    <label for="index-ajuste-variante-id" class="block text-sm font-medium text-slate-700 mb-1">Variante</label>
                    <select id="index-ajuste-variante-id" wire:model="ajuste_variante_id"
                            class="w-full rounded-lg text-sm focus:ring-fp-primary">
                        <option value="">— Seleccionar variante —</option>
                        @foreach ($this->variantes as $variante)
                            <option value="{{ $variante->id }}">{{ $variante->sku }}</option>
                        @endforeach
                    </select>
                    @error('ajuste_variante_id') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="fp-campo">
                        <label for="index-ajuste-tipo" class="block text-sm font-medium text-slate-700 mb-1">Tipo</label>
                        <select id="index-ajuste-tipo" wire:model="ajuste_tipo"
                                class="w-full rounded-lg text-sm focus:ring-fp-primary">
                            <option value="ajuste_negativo">Descontar</option>
                            <option value="ajuste_positivo">Agregar</option>
                        </select>
                    </div>
                    <div class="fp-campo">
                        <label for="index-ajuste-cantidad" class="block text-sm font-medium text-slate-700 mb-1">Cantidad</label>
                        <input id="index-ajuste-cantidad" type="number" min="1" step="1" wire:model="ajuste_cantidad"
                               class="w-full rounded-lg text-sm focus:ring-fp-primary"
                               placeholder="1">
                        @error('ajuste_cantidad') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="fp-campo">
                    <label for="index-ajuste-motivo" class="block text-sm font-medium text-slate-700 mb-1">Motivo</label>
                    <input id="index-ajuste-motivo" type="text" maxlength="300" wire:model="ajuste_motivo"
                           class="w-full rounded-lg text-sm focus:ring-fp-primary"
                           placeholder="Ej. merma, corrección de conteo">
                    @error('ajuste_motivo') <p class="text-xs text-fp-danger mt-1">{{ $message }}</p> @enderror
                </div>

                <button type="submit"
                        class="fp-btn fp-btn-oscuro">
                    Registrar ajuste
                </button>
            </form>
        </div>
    </div>

    {{-- Existencias --}}
    <div class="relative fp-tarjeta overflow-hidden fp-formulario fp-aparecer">
        <x-ui.cargando />
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
                       placeholder="Buscar por SKU…" etiqueta="Buscar existencias por SKU" class="w-full sm:w-72" />
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="fp-tabla min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr>
                        <th class="text-left font-medium px-4 py-3">SKU</th>
                        <th class="text-left font-medium px-4 py-3">Producto</th>
                        <th class="text-right font-medium px-4 py-3">Disponible</th>
                        <th class="text-right font-medium px-4 py-3">Mínimo</th>
                        <th class="text-left font-medium px-4 py-3">Actualizado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->existencias as $existencia)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <x-ui.imagen-ampliable
                                        :url="$this->fotos[$existencia->variante?->producto_id] ?? null"
                                        :alt="$existencia->variante?->producto?->nombre ?? 'Producto'" />
                                    <span class="font-mono text-xs">{{ $existencia->variante?->sku ?? '—' }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-700">
                                {{ $existencia->variante?->producto?->nombre ?? '—' }}
                                @php
                                    $detalleVariante = trim(implode(' · ', array_filter([
                                        $existencia->variante?->talla ? trim($existencia->variante->talla->sistema.' '.$existencia->variante->talla->valor) : null,
                                        $existencia->variante?->nombre_color_comercial ?: $existencia->variante?->color?->nombre,
                                    ])));
                                @endphp
                                @if ($detalleVariante !== '')
                                    <span class="block text-xs text-slate-500">{{ $detalleVariante }}</span>
                                @endif
                            </td>
                            @php
                                $bajo = $existencia->estaBajo($this->minimoGeneral);
                            @endphp
                            <td class="px-4 py-3 text-right font-medium
                                {{ (int) $existencia->cantidad_disponible === 0 || $bajo ? 'text-fp-danger' : 'text-slate-900' }}">
                                <span class="inline-flex items-center justify-end gap-2">
                                    @if ($bajo)
                                        <x-panel.insignia variante="danger" punto data-stock-bajo>Stock bajo</x-panel.insignia>
                                    @endif
                                    {{ (int) $existencia->cantidad_disponible }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-slate-500 whitespace-nowrap">
                                @if ($editandoMinimoId === (int) $existencia->id)
                                    <form wire:submit="guardarMinimo" class="fp-emerger inline-flex items-center gap-1.5" data-editar-minimo>
                                        <label for="minimo-{{ $existencia->id }}" class="sr-only">Mínimo de {{ $existencia->variante?->sku }}</label>
                                        <input id="minimo-{{ $existencia->id }}" type="number" min="0" wire:model="minimoNuevo" class="w-20 rounded-lg py-1 text-right text-sm" autofocus>
                                        <button type="submit" class="fp-accion fp-accion-primaria">Guardar</button>
                                        <button type="button" wire:click="cancelarMinimo" class="fp-accion fp-accion-neutra">Cancelar</button>
                                    </form>
                                    @error('minimoNuevo') <p class="mt-1 text-xs text-fp-danger">{{ $message }}</p> @enderror
                                @else
                                    <span class="inline-flex items-center gap-2">
                                        @if ((int) $existencia->stock_minimo > 0)
                                            <span class="tabular-nums">{{ (int) $existencia->stock_minimo }}</span>
                                        @else
                                            <span class="tabular-nums text-slate-400" title="Usa el mínimo general">{{ $this->minimoGeneral > 0 ? $this->minimoGeneral.' (general)' : '—' }}</span>
                                        @endif
                                        @if ($this->puedeEditarMinimo)
                                            <button type="button" wire:click="editarMinimo({{ $existencia->id }})" class="fp-accion fp-accion-neutra" aria-label="Editar mínimo de {{ $existencia->variante?->sku }}">Editar</button>
                                        @endif
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ optional($existencia->updated_at)->format('d/m/Y H:i') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <x-panel.vacio colspan="5" :mensaje="$soloBajo ? 'Ninguna variante está por debajo de su mínimo.' : 'No hay existencias registradas todavía.'" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>