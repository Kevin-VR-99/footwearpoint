<?php

use App\Models\SolicitudCambio;
use App\Models\VentaDirecta;
use App\Services\Cambio\PlazoCambio;
use App\Services\Cambio\SolicitarCambioAction;
use App\Support\MensajeError;
use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * TG-254 (R12, E12-01) — Solicitudes de cambio de producto.
 *
 * Lista las solicitudes de la distribuidora (solo consulta: autorizar,
 * rechazar y emitir el vale es de otra tarea) y permite registrar el cambio
 * de una venta de mostrador, que la app no muestra. Los cambios de pedidos
 * se piden desde el detalle del pedido o desde la app.
 */
new #[Layout('layouts.panel')] #[Title('Cambios — FootwearPoint')] class extends Component {
    use WithPagination;

    public string $estado = '';

    public string $folioVenta = '';
    public ?int $ventaId = null;
    public ?int $cambioLineaId = null;
    public string $cambioMotivo = '';

    public string $mensaje = '';
    public string $errorMsg = '';

    public function mount(): void
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');
    }

    public function updatedEstado(): void
    {
        $this->resetPage();
    }

    public function getSolicitudesProperty()
    {
        return SolicitudCambio::query()
            ->with(['pedidoDetalle.pedido', 'ventaDetalle.ventaDirecta', 'clienteDirecto', 'revendedorAfiliacion.revendedor'])
            ->when($this->estado !== '', fn ($q) => $q->where('estado', $this->estado))
            ->orderByDesc('id')
            ->paginate(25);
    }

    public function buscarVenta(): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';
        $this->ventaId = null;
        $this->cambioLineaId = null;

        $venta = VentaDirecta::query()->where('folio', trim($this->folioVenta))->first();

        if (! $venta) {
            $this->errorMsg = 'No existe una venta con el folio '.trim($this->folioVenta).'.';

            return;
        }

        $this->ventaId = (int) $venta->id;
    }

    public function getVentaProperty(): ?VentaDirecta
    {
        return $this->ventaId ? VentaDirecta::query()->with(['detalle', 'clienteDirecto'])->find($this->ventaId) : null;
    }

    public function getCambiosVentaProperty(): array
    {
        $plazo = app(PlazoCambio::class);

        return $this->venta?->detalle
            ->mapWithKeys(fn ($l) => [$l->id => $plazo->estado($l->setRelation('ventaDirecta', $this->venta))])
            ->all() ?? [];
    }

    public function abrirCambio(int $lineaId): void
    {
        $this->cambioLineaId = $lineaId;
        $this->cambioMotivo = '';
        $this->errorMsg = '';
    }

    public function solicitarCambio(SolicitarCambioAction $accion): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $accion->ejecutar(['venta_detalle_id' => $this->cambioLineaId, 'motivo' => $this->cambioMotivo]);
            $this->cambioLineaId = null;
            $this->cambioMotivo = '';
            $this->mensaje = 'Solicitud de cambio registrada. Si se autoriza, el cliente recibe un vale (no hay devoluciones en efectivo).';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo registrar la solicitud de cambio.');
        }
    }
}; ?>

<div class="space-y-4">
    <x-panel.encabezado titulo="Cambios"
        subtitulo="Solicitudes de cambio de producto dentro del plazo. Si se autorizan, el cliente recibe un vale: no hay devoluciones en efectivo." />

    @if ($mensaje)
        <x-panel.alerta tipo="exito">{{ $mensaje }}</x-panel.alerta>
    @endif
    @if ($errorMsg)
        <x-panel.alerta tipo="error">{{ $errorMsg }}</x-panel.alerta>
    @endif

    <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
        <p class="text-sm font-medium text-slate-800">Cambio de una venta de mostrador</p>
        <p class="text-xs text-slate-500">Los cambios de pedidos se piden desde el detalle del pedido o desde la app del cliente.</p>
        <form wire:submit="buscarVenta" class="mt-3 flex gap-2">
            <input type="text" wire:model="folioVenta" placeholder="Folio de la venta (VD-...)" class="w-64 rounded-lg border-slate-300 text-sm">
            <button type="submit" class="rounded-lg border border-fp-primary px-3 text-sm text-fp-primary">Buscar</button>
        </form>

        @if ($this->venta)
            <div class="mt-4">
                <p class="text-sm text-slate-700">
                    Venta {{ $this->venta->folio }} · {{ $this->venta->fecha_venta?->format('d/m/Y') }} ·
                    {{ $this->venta->clienteDirecto?->nombre ?? 'Sin cliente' }}
                </p>
                <table class="mt-2 min-w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($this->venta->detalle as $l)
                            @php($cambio = $this->cambiosVenta[$l->id] ?? null)
                            <tr wire:key="vd-{{ $l->id }}">
                                <td class="py-2">{{ $l->producto_nombre }} <span class="text-xs text-slate-500">{{ $l->talla }} · {{ $l->color }} ×{{ $l->cantidad }}</span></td>
                                <td class="py-2 text-right">
                                    @if ($cambio && $cambio['solicitud'])
                                        <span class="text-xs text-slate-600">Solicitud {{ str_replace('_', ' ', $cambio['solicitud']['estado']) }}</span>
                                    @elseif ($cambio && $cambio['puede_solicitar'])
                                        @if ($cambioLineaId === $l->id)
                                            <div class="ml-auto max-w-sm space-y-2 text-left">
                                                <textarea wire:model="cambioMotivo" rows="2" maxlength="300" placeholder="Motivo del cambio" class="w-full rounded-lg border-slate-300 text-sm"></textarea>
                                                <button type="button" wire:click="solicitarCambio" class="rounded-lg bg-fp-primary px-3 py-1.5 text-xs text-white">Enviar solicitud</button>
                                                <button type="button" wire:click="$set('cambioLineaId', null)" class="text-xs text-slate-600">Cancelar</button>
                                            </div>
                                        @else
                                            <button type="button" wire:click="abrirCambio({{ $l->id }})" class="text-sm font-medium text-fp-primary">Solicitar cambio</button>
                                            <span class="block text-xs text-slate-500">Hasta {{ \Illuminate\Support\Carbon::parse($cambio['fecha_limite'])->format('d/m/Y') }}</span>
                                        @endif
                                    @else
                                        <span class="text-xs text-slate-400">Fuera de plazo</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="flex items-center gap-3">
        <label class="text-sm text-slate-600">Estado</label>
        <select wire:model.live="estado" class="rounded-lg border-slate-300 text-sm">
            <option value="">Todos</option>
            @foreach (['solicitada', 'autorizada', 'rechazada', 'enviada_fabrica', 'aceptada_fabrica', 'vale_emitido', 'cerrada'] as $e)
                <option value="{{ $e }}">{{ ucfirst(str_replace('_', ' ', $e)) }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto rounded-2xl border border-slate-200/80 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                <tr>
                    <th class="px-4 py-2">Solicitud</th>
                    <th class="px-4 py-2">Cliente</th>
                    <th class="px-4 py-2">Producto</th>
                    <th class="px-4 py-2">Origen</th>
                    <th class="px-4 py-2">Motivo</th>
                    <th class="px-4 py-2">Plazo</th>
                    <th class="px-4 py-2">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->solicitudes as $s)
                    @php($linea = $s->pedidoDetalle ?? $s->ventaDetalle)
                    <tr wire:key="sol-{{ $s->id }}">
                        <td class="px-4 py-3">#{{ $s->id }}<span class="block text-xs text-slate-500">{{ $s->fecha_solicitud?->format('d/m/Y') }}</span></td>
                        <td class="px-4 py-3">
                            {{ $s->clienteDirecto?->nombre ?? $s->revendedorAfiliacion?->revendedor?->nombre ?? '—' }}
                            <span class="block text-xs text-slate-500">{{ $s->cliente_directo_id ? 'Cliente' : 'Cliente mayorista' }}</span>
                        </td>
                        <td class="px-4 py-3">{{ $linea?->producto_nombre }}<span class="block text-xs text-slate-500">{{ $linea?->talla }} · {{ $linea?->color }} ×{{ $linea?->cantidad }}</span></td>
                        <td class="px-4 py-3">
                            @if ($s->pedidoDetalle)
                                <a href="{{ route('pedidos.show', $s->pedidoDetalle->pedido_id) }}" class="text-fp-primary hover:underline">Pedido {{ $s->pedidoDetalle->pedido?->folio }}</a>
                            @else
                                Venta {{ $s->ventaDetalle?->ventaDirecta?->folio }}
                            @endif
                        </td>
                        <td class="px-4 py-3 max-w-xs">{{ $s->motivo }}</td>
                        <td class="px-4 py-3 text-xs">Pedida antes del {{ $s->fecha_limite_solicitud?->format('d/m/Y') }}</td>
                        <td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ ucfirst(str_replace('_', ' ', $s->estado)) }}</span></td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="7" mensaje="No hay solicitudes de cambio" />
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->solicitudes->links() }}
</div>
