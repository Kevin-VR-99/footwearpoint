<?php

use App\Models\VentaDirecta;
use App\Services\Auth\PermisoModuloService;
use App\Services\Catalogo\ImagenDeProducto;
use App\Services\Reembolso\ReembolsoMercadoPagoService;
use App\Services\VentaDirecta\AnularVentaDirectaAction;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ventas de mostrador: historial con su detalle, comprobante y cancelación.
 *
 * Cancelar regresa las piezas al stock, asienta la devolución del dinero por
 * el mismo método y se refleja en el corte de caja y en los reportes
 * (AnularVentaDirectaAction). Solo con el permiso «Cancelar ventas».
 */
new #[Layout('layouts.panel')] #[Title('Ventas de mostrador — FootwearPoint')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $buscar = '';

    #[Url]
    public string $estado = '';

    #[Url]
    public string $desde = '';

    #[Url]
    public string $hasta = '';

    #[Locked]
    public ?int $abiertaId = null;

    #[Locked]
    public ?int $cancelandoId = null;

    public string $motivo = '';

    /** Dentro de Venta local no repite el título ni el botón de nueva venta. */
    public bool $embebido = false;

    public string $mensaje = '';

    public string $errorMsg = '';

    public function mount(): void
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'estado', 'desde', 'hasta'], true)) {
            $this->resetPage();
        }
    }

    public function getPuedeCancelarProperty(): bool
    {
        return app(PermisoModuloService::class)->puede(Auth::user(), PermisoModuloService::VENTAS_CANCELAR);
    }

    public function getVentasProperty()
    {
        $termino = trim($this->buscar);

        return VentaDirecta::query()
            ->with(['clienteDirecto:id,nombre', 'registradaPor.usuario:id,nombre', 'anuladaPor.usuario:id,nombre', 'detalle', 'pagos', 'reembolsosMercadoPago'])
            ->when($termino !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('folio', 'like', '%'.$termino.'%')
                ->orWhereHas('clienteDirecto', fn ($c) => $c->where('nombre', 'like', '%'.$termino.'%'))))
            ->when(in_array($this->estado, ['completada', 'anulada'], true), fn ($q) => $q->where('estado', $this->estado))
            ->when($this->desde !== '', fn ($q) => $q->whereDate('fecha_venta', '>=', $this->desde))
            ->when($this->hasta !== '', fn ($q) => $q->whereDate('fecha_venta', '<=', $this->hasta))
            ->orderByDesc('fecha_venta')
            ->orderByDesc('id')
            ->paginate(20);
    }

    /** @return array<int, string> producto_campana_id => url de la venta abierta */
    public function getFotosProperty(): array
    {
        $venta = $this->ventas->getCollection()->firstWhere('id', $this->abiertaId);

        if ($venta === null) {
            return [];
        }

        return app(ImagenDeProducto::class)->porProductoCampana($venta->detalle->pluck('producto_campana_id'));
    }

    public function alternar(int $id): void
    {
        $this->abiertaId = $this->abiertaId === $id ? null : $id;
    }

    public function abrirCancelar(int $id): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        if (! $this->puedeCancelar) {
            $this->errorMsg = MensajeError::SIN_PERMISO;

            return;
        }

        $this->cancelandoId = VentaDirecta::query()->whereKey($id)->where('estado', 'completada')->value('id');
        $this->motivo = '';
        $this->resetErrorBag();
    }

    public function cerrarCancelar(): void
    {
        $this->cancelandoId = null;
        $this->motivo = '';
    }

    public function getVentaACancelarProperty(): ?VentaDirecta
    {
        return $this->cancelandoId ? VentaDirecta::query()->with(['detalle', 'pagos'])->find($this->cancelandoId) : null;
    }

    /** Vuelve a mandar a Mercado Pago un reembolso automático que falló. */
    public function reintentarReembolsoMp(int $id, ReembolsoMercadoPagoService $servicio): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $servicio->reintentar($id, Auth::user());
            $this->mensaje = 'Reembolso enviado de nuevo a Mercado Pago.';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo reintentar el reembolso.');
        }
    }

    public function cancelar(AnularVentaDirectaAction $accion): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        if ($this->cancelandoId === null) {
            return;
        }

        try {
            $venta = $accion->ejecutar($this->cancelandoId, $this->motivo);
            $this->mensaje = 'Venta '.$venta->folio.' cancelada. Las piezas regresaron al stock y quedó asentada la devolución del dinero.';
            $this->cerrarCancelar();
        } catch (ValidationException $e) {
            $this->addError('motivo', collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo cancelar la venta.');
            $this->cerrarCancelar();
        }
    }
}; ?>

<div class="space-y-4">
    @if (! $embebido)
        <x-panel.encabezado titulo="Ventas de mostrador"
            subtitulo="Historial de ventas locales. Puedes ver el detalle, reimprimir el comprobante y, con permiso, cancelar una venta." >
            <x-slot:acciones>
                <a href="{{ route('punto-venta.index') }}" wire:navigate class="fp-btn fp-btn-primario">Nueva venta</a>
            </x-slot:acciones>
        </x-panel.encabezado>
    @endif

    @if ($mensaje)
        <x-panel.alerta tipo="exito">{{ $mensaje }}</x-panel.alerta>
    @endif
    @if ($errorMsg)
        <x-panel.alerta tipo="error">{{ $errorMsg }}</x-panel.alerta>
    @endif

    <x-panel.filtros class="fp-tarjeta p-4 fp-aparecer">
        <div class="min-w-[14rem] flex-1">
            <x-ui.buscador wire:model.live.debounce.300ms="buscar" placeholder="Folio o cliente…" etiqueta="Buscar ventas" />
        </div>
        <label class="text-sm">
            <span class="block text-slate-500 mb-1">Estado</span>
            <select wire:model.live="estado" class="rounded-lg text-sm">
                <option value="">Todas</option>
                <option value="completada">Completadas</option>
                <option value="anulada">Canceladas</option>
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-slate-500 mb-1">Desde</span>
            <input type="date" wire:model.live="desde" class="rounded-lg text-sm">
        </label>
        <label class="text-sm">
            <span class="block text-slate-500 mb-1">Hasta</span>
            <input type="date" wire:model.live="hasta" class="rounded-lg text-sm">
        </label>
    </x-panel.filtros>

    <div class="relative">
        <x-ui.cargando objetivo="buscar,estado,desde,hasta,gotoPage,nextPage,previousPage" />
        <x-panel.tabla class="fp-aparecer">
            <x-slot:encabezado>
                <tr>
                    <th class="px-4 py-3 font-medium">Folio</th>
                    <th class="px-4 py-3 font-medium">Fecha</th>
                    <th class="px-4 py-3 font-medium">Cliente</th>
                    <th class="px-4 py-3 font-medium">Cobro</th>
                    <th class="px-4 py-3 font-medium text-right">Total</th>
                    <th class="px-4 py-3 font-medium">Estado</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </x-slot:encabezado>

            @forelse ($this->ventas as $venta)
                <tr wire:key="venta-{{ $venta->id }}" class="transition-colors hover:bg-fp-page/60 {{ $venta->estaAnulada() ? 'opacity-70' : '' }}">
                    <td class="px-4 py-3">
                        <button type="button" wire:click="alternar({{ $venta->id }})" class="font-mono text-xs fp-enlace">{{ $venta->folio }}</button>
                    </td>
                    <td class="px-4 py-3 text-slate-600 whitespace-nowrap">{{ $venta->fecha_venta?->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3">{{ $venta->clienteDirecto?->nombre ?? 'Público general' }}</td>
                    <td class="px-4 py-3 text-xs text-slate-600">
                        {{ $venta->pagos->where('direccion', 'entrada')->map(fn ($p) => \App\Models\Pago::etiquetaMetodo($p->metodo))->unique()->implode(', ') ?: 'Vale' }}
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums font-medium">${{ number_format((float) $venta->total, 2) }}</td>
                    <td class="px-4 py-3">
                        @if ($venta->estaAnulada())
                            <x-ui.insignia-estado variante="danger" texto="Cancelada" />
                        @else
                            <x-ui.insignia-estado variante="success" texto="Completada" />
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('ventas-directas.comprobante', $venta->id) }}" target="_blank" class="fp-btn fp-btn-fantasma fp-btn-sm">Comprobante</a>
                        @if (! $venta->estaAnulada() && $this->puedeCancelar)
                            <button type="button" wire:click="abrirCancelar({{ $venta->id }})" class="fp-btn fp-btn-sm fp-btn-secundario hover:!text-fp-danger" data-cancelar-venta="{{ $venta->id }}">
                                Cancelar
                            </button>
                        @endif
                    </td>
                </tr>
                @if ($abiertaId === $venta->id)
                    <tr wire:key="venta-detalle-{{ $venta->id }}" class="bg-fp-page/40">
                        <td colspan="7" class="px-6 py-4 fp-emerger">
                            <ul class="space-y-1 text-sm">
                                @foreach ($venta->detalle as $linea)
                                    <li class="flex items-center justify-between gap-4">
                                        <span class="flex min-w-0 items-center gap-2">
                                            <x-ui.imagen-ampliable :url="$this->fotos[$linea->producto_campana_id] ?? null" :alt="$linea->producto_nombre" />
                                            <span>{{ $linea->cantidad }} × {{ $linea->producto_nombre }} · talla {{ $linea->talla }} · {{ $linea->color }}</span>
                                        </span>
                                        <span class="tabular-nums">${{ number_format((float) $linea->subtotal, 2) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-2 text-xs text-fp-text-muted">Registró: {{ $venta->registradaPor?->usuario?->nombreVisible() ?? '—' }}</p>
                            @if ($venta->estaAnulada())
                                <p class="mt-1 text-xs text-fp-danger">
                                    Cancelada el {{ $venta->anulada_at?->format('d/m/Y H:i') }} por {{ $venta->anuladaPor?->usuario?->nombreVisible() ?? '—' }}: {{ $venta->motivo_anulacion }}
                                </p>
                                @foreach ($venta->pagos->where('direccion', 'salida') as $reembolso)
                                    <p class="text-xs text-slate-600">Devolución {{ $reembolso->folio }} · {{ \App\Models\Pago::etiquetaMetodo($reembolso->metodo) }} · ${{ number_format((float) $reembolso->monto, 2) }}</p>
                                @endforeach
                                @foreach ($venta->reembolsosMercadoPago as $reembolsoMp)
                                    <x-ui.reembolso-mercado-pago :reembolso="$reembolsoMp" :puede-reintentar="$this->puedeCancelar" />
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @endif
            @empty
                <x-panel.vacio colspan="7" mensaje="No hay ventas con estos filtros." />
            @endforelse
        </x-panel.tabla>
    </div>

    <div>{{ $this->ventas->links() }}</div>

    @if ($this->ventaACancelar)
        @php $vc = $this->ventaACancelar; @endphp
        <x-panel.modal titulo="Cancelar venta {{ $vc->folio }}" subtitulo="Las piezas regresan al stock y se asienta la devolución del dinero con el mismo método." cerrar="cerrarCancelar" formulario="cancelar">
            <div class="space-y-3 text-sm">
                <div class="rounded-lg bg-fp-page p-3">
                    <p class="font-medium">Total ${{ number_format((float) $vc->total, 2) }}</p>
                    @foreach ($vc->pagos->where('direccion', 'entrada') as $cobro)
                        <p class="text-xs text-slate-600">Se devolverán ${{ number_format((float) $cobro->monto, 2) }} en {{ \App\Models\Pago::etiquetaMetodo($cobro->metodo) }}.</p>
                    @endforeach
                    <p class="text-xs text-slate-600">{{ $vc->detalle->sum('cantidad') }} pieza(s) regresan al stock.</p>
                </div>
                <label class="block">
                    <span class="block text-slate-700 font-medium mb-1">Motivo</span>
                    <textarea wire:model="motivo" rows="3" maxlength="300" required class="w-full rounded-lg text-sm" placeholder="Ej. el cliente se arrepintió, cobro duplicado…"></textarea>
                    @error('motivo') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                </label>
            </div>
            <x-slot:pie>
                <button type="button" wire:click="cerrarCancelar" class="fp-btn fp-btn-fantasma">Volver</button>
                <button type="submit" class="fp-btn fp-btn-peligro" wire:loading.attr="disabled" wire:target="cancelar">
                    <span wire:loading.remove wire:target="cancelar">Cancelar venta</span>
                    <span wire:loading wire:target="cancelar">Cancelando…</span>
                </button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
</div>
