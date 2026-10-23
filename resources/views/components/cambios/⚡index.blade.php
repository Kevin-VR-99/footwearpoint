<?php

use App\Models\SolicitudCambio;
use App\Models\VentaDirecta;
use App\Services\Cambio\PlazoCambio;
use App\Services\Catalogo\ImagenDeProducto;
use App\Services\Cambio\SolicitarCambioAction;
use App\Support\MensajeError;
use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * TG-254 (R12, E12-01) — Solicitudes de cambio de producto.
 *
 * Lista las solicitudes de la distribuidora, permite moverlas por su flujo
 * (autorizar, rechazar, fábrica, vale, cerrar; CambiarEstadoSolicitudCambioAction,
 * que también le avisa al cliente) y registrar el cambio
 * de una venta de mostrador, que la app no muestra. Los cambios de pedidos
 * se piden desde el detalle del pedido o desde la app.
 */
new #[Layout('layouts.panel')] #[Title('Cambios — FootwearPoint')] class extends Component {
    use WithPagination;

    public string $estado = '';

    public string $folioVenta = '';
    #[Locked]
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

    /** @return array<int, string> producto_campana_id => url */
    public function getFotosSolicitudesProperty(): array
    {
        $ids = $this->solicitudes->getCollection()->map(
            fn ($s) => ($s->pedidoDetalle ?? $s->ventaDetalle)?->producto_campana_id,
        );

        return app(ImagenDeProducto::class)->porProductoCampana($ids);
    }

    /** @return array<int, string> producto_campana_id => url */
    public function getFotosVentaProperty(): array
    {
        return app(ImagenDeProducto::class)->porProductoCampana(
            $this->venta?->detalle->pluck('producto_campana_id') ?? [],
        );
    }

    public function buscarVenta(): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';
        $this->ventaId = null;
        $this->cambioLineaId = null;

        if (trim($this->folioVenta) === '') {
            $this->errorMsg = 'Escribe el folio de la venta que quieres cambiar.';

            return;
        }

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

    #[Locked]
    public ?int $resolviendoId = null;
    #[Locked]
    public string $estadoDestino = '';
    public string $notaResolucion = '';
    public ?string $montoVale = null;

    public function getPuedeResolverProperty(): bool
    {
        return app(\App\Services\Auth\PermisoModuloService::class)->puede(auth()->user(), \App\Services\Auth\PermisoModuloService::CAMBIOS_RESOLVER);
    }

    public function prepararTransicion(int $id, string $estado): void
    {
        $solicitud = SolicitudCambio::query()->findOrFail($id);
        abort_unless(in_array($estado, $solicitud->siguientesEstados(), true), 422);
        $this->resolviendoId = $id;
        $this->estadoDestino = $estado;
        $this->notaResolucion = '';
        $linea = $solicitud->pedidoDetalle ?? $solicitud->ventaDetalle;
        $this->montoVale = $estado === 'vale_emitido' ? number_format((float) ($linea?->subtotal ?? 0), 2, '.', '') : null;
        $this->errorMsg = '';
    }

    public function cancelarTransicion(): void
    {
        $this->resolviendoId = null;
        $this->estadoDestino = '';
    }

    public function aplicarTransicion(\App\Services\Cambio\CambiarEstadoSolicitudCambioAction $accion): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $monto = $this->estadoDestino === 'vale_emitido' && is_numeric($this->montoVale) ? (float) $this->montoVale : null;
            $s = $accion->ejecutar((int) $this->resolviendoId, $this->estadoDestino, $this->notaResolucion, $monto);
            $this->mensaje = 'Solicitud #'.$s->id.': '.mb_strtolower(SolicitudCambio::etiqueta($s->estado)).'. Le avisamos al cliente.';
            $this->cancelarTransicion();
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo actualizar la solicitud.');
        }
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
        subtitulo="Solicitudes de cambio de producto dentro del plazo. Cada paso le llega al cliente en la app y por correo. Si se autorizan, el cliente recibe un vale: no hay devoluciones en efectivo." />

    @if ($mensaje)
        <x-panel.alerta tipo="exito">{{ $mensaje }}</x-panel.alerta>
    @endif
    @if ($errorMsg)
        <x-panel.alerta tipo="error">{{ $errorMsg }}</x-panel.alerta>
    @endif

    <div class="fp-tarjeta p-4 fp-formulario fp-aparecer">
        <p class="text-sm font-medium text-slate-800">Cambio de una venta de mostrador</p>
        <p class="text-xs text-slate-500">Los cambios de pedidos se piden desde el detalle del pedido o desde la app del cliente.</p>
        <form wire:submit="buscarVenta" class="mt-3 flex gap-2">
            <x-ui.buscador wire:model="folioVenta" placeholder="Buscar por folio de la venta (VD-…)" etiqueta="Buscar venta por folio" class="w-full sm:w-80" />
            <button type="submit" class="fp-btn fp-btn-primario rounded-xl">Buscar</button>
        </form>

        @if ($this->venta)
            <div class="mt-4">
                <p class="text-sm text-slate-700">
                    Venta {{ $this->venta->folio }} · {{ $this->venta->fecha_venta?->format('d/m/Y') }} ·
                    {{ $this->venta->clienteDirecto?->nombre ?? 'Sin cliente' }}
                </p>
                <table class="fp-tabla mt-2 min-w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($this->venta->detalle as $l)
                            @php($cambio = $this->cambiosVenta[$l->id] ?? null)
                            <tr wire:key="vd-{{ $l->id }}">
                                <td class="py-2">
                                    <div class="flex items-center gap-2">
                                        <x-ui.imagen-ampliable :url="$this->fotosVenta[$l->producto_campana_id] ?? null" :alt="$l->producto_nombre" />
                                        <span>{{ $l->producto_nombre }} <span class="text-xs text-slate-500">{{ $l->talla }} · {{ $l->color }} ×{{ $l->cantidad }}</span></span>
                                    </div>
                                </td>
                                <td class="py-2 text-right">
                                    @if ($cambio && $cambio['solicitud'])
                                        <span class="text-xs text-slate-600">Solicitud {{ str_replace('_', ' ', $cambio['solicitud']['estado']) }}</span>
                                    @elseif ($cambio && $cambio['puede_solicitar'])
                                        @if ($cambioLineaId === $l->id)
                                            <div class="ml-auto max-w-sm space-y-2 text-left">
                                                <textarea wire:model="cambioMotivo" rows="2" maxlength="300" placeholder="Motivo del cambio" class="w-full rounded-lg text-sm"></textarea>
                                                <button type="button" wire:click="solicitarCambio" class="fp-btn fp-btn-primario fp-btn-sm">Enviar solicitud</button>
                                                <button type="button" wire:click="$set('cambioLineaId', null)" class="fp-btn fp-btn-fantasma fp-btn-sm">Cancelar</button>
                                            </div>
                                        @else
                                            <button type="button" wire:click="abrirCambio({{ $l->id }})" class="fp-enlace text-sm font-medium">Solicitar cambio</button>
                                            <span class="block text-xs text-slate-500">Hasta {{ \Illuminate\Support\Carbon::parse($cambio['fecha_limite'])->format('d/m/Y') }}</span>
                                        @endif
                                    @else
                                        <span class="text-xs text-slate-500">Fuera de plazo</span>
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
        <label for="index-estado" class="text-sm text-slate-600">Estado</label>
        <select id="index-estado" wire:model.live="estado" class="rounded-lg text-sm">
            <option value="">Todos</option>
            @foreach (['solicitada', 'autorizada', 'rechazada', 'enviada_fabrica', 'aceptada_fabrica', 'vale_emitido', 'cerrada'] as $e)
                <option value="{{ $e }}">{{ ucfirst(str_replace('_', ' ', $e)) }}</option>
            @endforeach
        </select>
    </div>

    <div class="relative overflow-x-auto fp-tarjeta fp-aparecer">
        <x-ui.cargando objetivo="estado,gotoPage,nextPage,previousPage" />
        <table class="fp-tabla min-w-full text-sm">
            <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-slate-600">
                <tr>
                    <th class="px-4 py-2">Solicitud</th>
                    <th class="px-4 py-2">Cliente</th>
                    <th class="px-4 py-2">Producto</th>
                    <th class="px-4 py-2">Origen</th>
                    <th class="px-4 py-2">Motivo</th>
                    <th class="px-4 py-2">Plazo</th>
                    <th class="px-4 py-2">Estado</th>
                    <th class="px-4 py-2 text-right">Acciones</th>
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
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <x-ui.imagen-ampliable :url="$linea ? ($this->fotosSolicitudes[$linea->producto_campana_id] ?? null) : null" :alt="$linea?->producto_nombre ?? 'Producto'" />
                                <div>{{ $linea?->producto_nombre }}<span class="block text-xs text-slate-500">{{ $linea?->talla }} · {{ $linea?->color }} ×{{ $linea?->cantidad }}</span></div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($s->pedidoDetalle)
                                <a href="{{ route('pedidos.show', $s->pedidoDetalle->pedido_id) }}" class="fp-enlace">Pedido {{ $s->pedidoDetalle->pedido?->folio }}</a>
                            @else
                                Venta {{ $s->ventaDetalle?->ventaDirecta?->folio }}
                            @endif
                        </td>
                        <td class="px-4 py-3 max-w-xs">{{ $s->motivo }}</td>
                        <td class="px-4 py-3 text-xs">Pedida antes del {{ $s->fecha_limite_solicitud?->format('d/m/Y') }}</td>
                        <td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ \App\Models\SolicitudCambio::etiqueta($s->estado) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            @if ($this->puedeResolver && $resolviendoId === $s->id)
                                <div class="ml-auto max-w-xs space-y-2 text-left fp-aparecer" data-resolver-cambio="{{ $s->id }}">
                                    <p class="text-xs font-medium text-slate-700">Pasar a: {{ \App\Models\SolicitudCambio::etiqueta($estadoDestino) }}</p>
                                    @if ($estadoDestino === 'vale_emitido')
                                        <label class="block text-xs text-slate-600">Monto del vale
                                            <input type="number" step="0.01" min="0.01" wire:model="montoVale" class="mt-1 w-full rounded-lg text-sm">
                                        </label>
                                    @endif
                                    <textarea wire:model="notaResolucion" rows="2" maxlength="500" class="w-full rounded-lg text-sm"
                                        placeholder="{{ $estadoDestino === 'rechazada' ? 'Motivo del rechazo (obligatorio, lo ve el cliente)' : 'Nota para el cliente (opcional)' }}"></textarea>
                                    <div class="flex gap-2">
                                        <button type="button" wire:click="aplicarTransicion" wire:loading.attr="disabled" class="fp-btn fp-btn-primario fp-btn-sm">Confirmar</button>
                                        <button type="button" wire:click="cancelarTransicion" class="fp-btn fp-btn-fantasma fp-btn-sm">Cancelar</button>
                                    </div>
                                </div>
                            @elseif ($this->puedeResolver)
                                <div class="flex flex-wrap justify-end gap-x-3 gap-y-1">
                                    @foreach ($s->siguientesEstados() as $siguiente)
                                        <button type="button" wire:click="prepararTransicion({{ $s->id }}, '{{ $siguiente }}')"
                                            class="text-xs font-medium transition-opacity hover:opacity-75 {{ $siguiente === 'rechazada' ? 'text-fp-danger' : 'text-fp-primary' }}">
                                            {{ match ($siguiente) { 'autorizada' => 'Autorizar', 'rechazada' => 'Rechazar', 'enviada_fabrica' => 'Enviar a fábrica', 'aceptada_fabrica' => 'Fábrica aceptó', 'vale_emitido' => 'Emitir vale', 'cerrada' => 'Cerrar', default => $siguiente } }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="8" mensaje="No hay solicitudes de cambio" />
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->solicitudes->links() }}
</div>
