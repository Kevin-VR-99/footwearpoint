<?php

use App\Livewire\Concerns\AltaRapidaDeCliente;
use App\Models\ClienteDirecto;
use App\Models\Pedido;
use App\Models\SolicitudCambio;
use App\Models\Vale;
use App\Models\ValeDevolucionPartida;
use App\Models\VentaDirecta;
use App\Services\Cambio\PlazoCambio;
use App\Services\Cambio\SolicitarCambioAction;
use App\Services\Catalogo\ImagenDeProducto;
use App\Services\Vale\EmitirNotaDevolucionAction;
use App\Support\Centavos;
use App\Support\MensajeError;
use App\Support\TelefonoCliente;
use App\Support\Tenant;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * TG-254 / TG-407 [G53] — Solicitudes de cambio de producto (flujo unificado).
 *
 * Flujo unificado en Cambios: busca ventas por folio VD o teléfono del cliente
 * y pedidos por folio. Si una venta no tiene cliente, permite alta rápida o
 * asignación dentro del mismo flujo. Conserva el historial de cambios anteriores.
 */
new #[Layout('layouts.panel')] #[Title('Cambios — FootwearPoint')] class extends Component {
    use AltaRapidaDeCliente;
    use WithFileUploads;
    use WithPagination;

    public string $estado = '';

    /** Conservado por compatibilidad de tests legacy. */
    public string $espacio = 'pedidos';

    public string $busqueda = '';
    public string $errorBusqueda = '';

    public string $folioPedido = '';
    #[Locked]
    public ?int $pedidoEncontradoId = null;
    public string $errorBusquedaPedido = '';

    /** TG-356 (G33): unidades a devolver por partida (pedido_detalle_id => cantidad). */
    public array $devolver = [];
    #[Locked]
    public string $claveDevolucion = '';
    #[Locked]
    public bool $confirmandoDevolucion = false;
    #[Locked]
    public ?int $notaEmitidaId = null;
    public string $errorDevolucion = '';

    public string $folioVenta = '';
    #[Locked]
    public ?int $ventaId = null;
    public ?int $cambioLineaId = null;
    public string $cambioMotivo = '';
    public string $altaClienteDirectoId = '';

    #[Locked]
    public ?int $clienteSeleccionadoId = null;
    #[Locked]
    public array $ventasClienteIds = [];

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

    public function cambiarEspacio(string $espacio): void
    {
        abort_unless(in_array($espacio, ['pedidos', 'mostrador', 'unificado'], true), 422);
        $this->espacio = $espacio;
        $this->resolviendoId = null;
        $this->estadoDestino = '';
        $this->resetPage();
    }

    public function updatedEspacio(): void
    {
        if (! in_array($this->espacio, ['pedidos', 'mostrador', 'unificado'], true)) {
            $this->espacio = 'pedidos';
        }
        $this->resetPage();
    }

    protected function asignarClienteDirecto(int $id): void
    {
        if (! $this->ventaId) {
            return;
        }

        $venta = VentaDirecta::query()->find($this->ventaId);
        if (! $venta) {
            $this->errorMsg = 'No se encontró la venta.';

            return;
        }

        if ($venta->estado === 'anulada') {
            $this->errorMsg = 'No se puede asignar un cliente a una venta anulada.';

            return;
        }

        if ($venta->cliente_directo_id) {
            return;
        }

        $cliente = ClienteDirecto::query()->where('estado', 'activo')->find($id);
        if (! $cliente) {
            $this->errorMsg = 'El cliente seleccionado no es válido.';

            return;
        }

        $venta->update(['cliente_directo_id' => $cliente->id]);
        unset($this->venta);
    }

    public function asignarCliente(int $id): void
    {
        $this->asignarClienteDirecto($id);
    }

    public function updatedAltaClienteDirectoId(string $id): void
    {
        if (is_numeric($id) && (int) $id > 0) {
            $this->asignarClienteDirecto((int) $id);
            if (! $this->errorMsg) {
                $this->avisoCliente = 'Cliente asignado a la venta.';
            }
        }
    }

    public function getVentasClienteProperty()
    {
        if (empty($this->ventasClienteIds)) {
            return collect();
        }

        return VentaDirecta::query()
            ->with(['detalle', 'clienteDirecto'])
            ->whereIn('id', $this->ventasClienteIds)
            ->where('estado', '!=', 'anulada')
            ->orderByDesc('fecha_venta')
            ->orderByDesc('id')
            ->get();
    }

    public function getClienteEncontradoProperty(): ?ClienteDirecto
    {
        return $this->clienteSeleccionadoId ? ClienteDirecto::query()->find($this->clienteSeleccionadoId) : null;
    }

    public function seleccionarVenta(int $id): void
    {
        $this->ventaId = $id;
        $venta = VentaDirecta::query()->find($id);
        $this->folioVenta = $venta?->folio ?? '';
        unset($this->venta);
    }

    public function getClientesProperty()
    {
        return ClienteDirecto::query()
            ->where('estado', 'activo')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'telefono']);
    }

    public function getClientesFiltradosProperty()
    {
        return $this->clientes
            ->filter(fn (ClienteDirecto $c) => \App\Support\BusquedaDeCliente::coincide($c->nombre, $c->telefono, $this->busquedaCliente))
            ->values();
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

    private function reiniciarDevolucion(): void
    {
        $this->devolver = [];
        $this->confirmandoDevolucion = false;
        $this->errorDevolucion = '';
        $this->claveDevolucion = (string) Str::uuid();
    }

    /** @return array<int, array{disponible:int, elegible:bool, motivo:string}> */
    public function getDevolucionPartidasProperty(): array
    {
        $partidas = $this->partidasPedido;
        if ($partidas->isEmpty()) {
            return [];
        }
        $ids = $partidas->pluck('id');
        $devueltas = ValeDevolucionPartida::query()->whereIn('pedido_detalle_id', $ids)
            ->groupBy('pedido_detalle_id')->selectRaw('pedido_detalle_id, SUM(cantidad) as total')->pluck('total', 'pedido_detalle_id');
        $conSolicitud = SolicitudCambio::query()->whereIn('pedido_detalle_id', $ids)->where('estado', '!=', 'rechazada')->pluck('pedido_detalle_id')->all();
        $estados = \App\Models\PedidoDetalle::query()->whereIn('id', $ids)->pluck('estado_surtido', 'id');

        return $partidas->mapWithKeys(function ($p) use ($devueltas, $conSolicitud, $estados) {
            $disponible = max(0, (int) $p->cantidad - (int) ($devueltas[$p->id] ?? 0));
            $motivo = match (true) {
                in_array($estados[$p->id] ?? null, ['no_surtido', 'parcial'], true) => 'No se surtió completa',
                in_array($p->id, $conSolicitud, false) => 'Tiene solicitud del flujo anterior',
                $disponible === 0 => 'Ya devuelta',
                default => '',
            };

            return [$p->id => ['disponible' => $disponible, 'elegible' => $motivo === '', 'motivo' => $motivo]];
        })->all();
    }

    /** Importe estimado en centavos (precio pagado registrado × unidades). El servidor lo recalcula al emitir. */
    public function getTotalDevolucionCentavosProperty(): int
    {
        $total = 0;
        foreach ($this->partidasPedido as $p) {
            $n = (int) ($this->devolver[$p->id] ?? 0);
            if ($n > 0) {
                $total += Centavos::desdeDecimal($p->getRawOriginal('precio_unitario')) * $n;
            }
        }

        return $total;
    }

    public function prepararDevolucion(): void
    {
        $this->errorDevolucion = '';
        abort_unless($this->puedeResolver, 403);
        $hay = collect($this->devolver)->filter(fn ($n) => is_numeric($n) && (int) $n > 0)->isNotEmpty();
        if (! $hay) {
            $this->errorDevolucion = 'Elige al menos un producto y la cantidad a devolver.';

            return;
        }
        $this->confirmandoDevolucion = true;
    }

    public function cancelarDevolucion(): void
    {
        $this->confirmandoDevolucion = false;
    }

    public function confirmarDevolucion(EmitirNotaDevolucionAction $accion): void
    {
        $this->errorDevolucion = '';
        abort_unless($this->puedeResolver && $this->pedidoEncontradoId, 403);

        try {
            $nota = $accion->ejecutar((int) $this->pedidoEncontradoId, array_map(fn ($n) => $n === '' || $n === null ? 0 : $n, $this->devolver), $this->claveDevolucion);
            $this->notaEmitidaId = (int) $nota->id;
            $this->reiniciarDevolucion();
            unset($this->devolucionPartidas);
        } catch (\Throwable $e) {
            $this->confirmandoDevolucion = false;
            $this->errorDevolucion = MensajeError::paraUsuario($e, 'No se pudo emitir la nota de devolución.');
        }
    }

    public function getNotaEmitidaProperty(): ?Vale
    {
        return $this->notaEmitidaId ? Vale::query()->with('partidasDevolucion')->find($this->notaEmitidaId) : null;
    }

    public function getPedidoEncontradoProperty(): ?Pedido
    {
        return $this->pedidoEncontradoId ? Pedido::query()->find($this->pedidoEncontradoId) : null;
    }

    /** TG-355: partidas históricas, solo informativas y solo de un pedido entregado. */
    public function getPartidasPedidoProperty()
    {
        $pedido = $this->pedidoEncontrado;

        return $pedido && $pedido->estado === 'entregado'
            ? $pedido->detalle()->orderBy('id')->get(['id', 'producto_nombre', 'modelo', 'talla', 'color', 'cantidad', 'precio_unitario'])
            : collect();
    }

    public function buscar(): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';
        $this->errorBusqueda = '';
        $this->errorBusquedaPedido = '';
        $this->pedidoEncontradoId = null;
        $this->ventaId = null;
        $this->cambioLineaId = null;
        $this->clienteSeleccionadoId = null;
        $this->ventasClienteIds = [];
        unset($this->pedidoEncontrado);
        unset($this->venta);
        unset($this->ventasCliente);

        $termino = trim($this->busqueda);

        if ($termino === '') {
            $this->errorBusqueda = 'Escribe un folio (PED-/VD-) o un teléfono de 10 dígitos.';
            $this->errorMsg = $this->errorBusqueda;

            return;
        }

        // 1. Folio de Pedido (PED-...)
        if (str_starts_with(strtoupper($termino), 'PED-')) {
            $pedido = Pedido::query()->where('folio', $termino)->first();
            if (! $pedido) {
                $this->errorBusqueda = 'No se encontró un pedido con ese folio.';
                $this->errorBusquedaPedido = $this->errorBusqueda;
                $this->errorMsg = $this->errorBusqueda;

                return;
            }

            $this->pedidoEncontradoId = (int) $pedido->id;
            $this->folioPedido = $pedido->folio;
            $this->reiniciarDevolucion();
            $this->notaEmitidaId = null;

            return;
        }

        // 2. Folio de Venta (VD-...)
        if (str_starts_with(strtoupper($termino), 'VD-')) {
            $venta = VentaDirecta::query()->where('folio', $termino)->first();
            if (! $venta) {
                $this->errorBusqueda = 'No existe una venta con el folio '.$termino.'.';
                $this->errorMsg = $this->errorBusqueda;

                return;
            }

            if ($venta->estado === 'anulada') {
                $this->errorBusqueda = 'Esa venta está anulada y no admite cambios.';
                $this->errorMsg = $this->errorBusqueda;

                return;
            }

            $this->ventaId = (int) $venta->id;
            $this->folioVenta = $venta->folio;

            return;
        }

        // 3. Teléfono de 10 dígitos
        $tel = TelefonoCliente::normalizar($termino);
        if ($tel !== null) {
            $cliente = ClienteDirecto::query()->where('telefono', $tel)->first();
            if (! $cliente) {
                $this->errorBusqueda = 'No se encontró ningún cliente con ese teléfono.';
                $this->errorMsg = $this->errorBusqueda;

                return;
            }

            $ventas = VentaDirecta::query()
                ->where('cliente_directo_id', $cliente->id)
                ->where('estado', '!=', 'anulada')
                ->orderByDesc('fecha_venta')
                ->orderByDesc('id')
                ->get();

            if ($ventas->isEmpty()) {
                $this->errorBusqueda = 'Ese cliente no tiene ventas registradas.';
                $this->errorMsg = $this->errorBusqueda;

                return;
            }

            $this->clienteSeleccionadoId = (int) $cliente->id;
            $this->ventasClienteIds = $ventas->pluck('id')->all();
            $this->ventaId = (int) $ventas->first()->id;
            $this->folioVenta = $ventas->first()->folio;

            return;
        }

        // 4. Si no es folio PED-/VD- ni teléfono de 10 dígitos
        $this->errorBusqueda = 'Escribe un folio (PED-/VD-) o un teléfono de 10 dígitos.';
        $this->errorMsg = $this->errorBusqueda;
    }

    public function buscarPedido(): void
    {
        $this->busqueda = $this->folioPedido;
        $this->pedidoEncontradoId = null;
        $this->errorBusquedaPedido = '';
        unset($this->pedidoEncontrado);

        $folio = trim($this->folioPedido);
        if ($folio === '') {
            $this->errorBusquedaPedido = 'Escribe el folio del pedido que quieres buscar.';
            $this->errorBusqueda = $this->errorBusquedaPedido;

            return;
        }

        $pedido = Pedido::query()->where('folio', $folio)->first();
        if (! $pedido) {
            $this->errorBusquedaPedido = 'No se encontró un pedido con ese folio.';
            $this->errorBusqueda = $this->errorBusquedaPedido;

            return;
        }

        $this->pedidoEncontradoId = (int) $pedido->id;
        $this->reiniciarDevolucion();
        $this->notaEmitidaId = null;
    }

    public function buscarVenta(): void
    {
        $termino = trim($this->folioVenta);
        $this->busqueda = $termino;
        if ($termino === '') {
            $this->errorMsg = 'Escribe el folio de la venta que quieres cambiar.';
            $this->errorBusqueda = $this->errorMsg;
            $this->ventaId = null;

            return;
        }

        $tel = TelefonoCliente::normalizar($termino);
        if ($tel !== null) {
            $this->buscar();

            return;
        }

        $venta = VentaDirecta::query()->where('folio', $termino)->first();
        if (! $venta) {
            $this->errorMsg = 'No existe una venta con el folio '.$termino.'.';
            $this->errorBusqueda = $this->errorMsg;
            $this->ventaId = null;

            return;
        }

        if ($venta->estado === 'anulada') {
            $this->errorMsg = 'Esa venta está anulada y no admite cambios.';
            $this->errorBusqueda = $this->errorMsg;
            $this->ventaId = null;

            return;
        }

        $this->ventaId = (int) $venta->id;
        $this->folioVenta = $venta->folio;
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

    {{-- Flujo unificado: Buscador por folio (PED-/VD-) o teléfono del cliente --}}
    <div class="fp-tarjeta p-4 fp-formulario fp-aparecer">
        <p class="text-sm font-medium text-slate-800">Folio (PED-/VD-) o teléfono del cliente</p>
        <p class="text-xs text-slate-500">Buscar pedido por folio (PED-…), venta de mostrador (VD-…) o teléfono del cliente de 10 dígitos.</p>
        <form wire:submit="buscar" class="mt-3 flex gap-2">
            <x-ui.buscador wire:model="busqueda" placeholder="Folio (PED-/VD-) o teléfono del cliente"
                etiqueta="Buscar pedido por folio" class="w-full sm:w-96" />
            <button type="submit" class="fp-btn fp-btn-primario rounded-xl"
                wire:loading.attr="disabled" wire:target="buscar">Buscar</button>
        </form>
        @if ($errorBusqueda)
            <x-panel.alerta tipo="error" class="mt-3">{{ $errorBusqueda }}</x-panel.alerta>
        @endif
        @if ($this->notaEmitida)
            <x-panel.alerta tipo="exito" class="mt-3">
                <span data-nota-emitida="{{ $this->notaEmitida->id }}">Nota de devolución {{ $this->notaEmitida->folio }} por ${{ number_format((float) $this->notaEmitida->monto_original, 2) }}.
                Vence el {{ $this->notaEmitida->fecha_vencimiento?->timezone('America/Mexico_City')->format('d/m/Y H:i') }} (hora de Ciudad de México).</span>
            </x-panel.alerta>
        @endif
    </div>

    @if ($this->ventasCliente->isNotEmpty())
        <div class="fp-tarjeta p-4 fp-aparecer" data-ventas-cliente>
            <p class="text-sm font-medium text-slate-800">
                Ventas registradas de {{ $this->clienteEncontrado?->nombre ?? 'cliente' }}
                <span class="text-xs font-normal text-slate-500">({{ $this->ventasCliente->count() }} {{ $this->ventasCliente->count() === 1 ? 'venta encontrada' : 'ventas encontradas' }})</span>
            </p>
            <p class="text-xs text-slate-500 mt-1">Elige la venta para tramitar el cambio:</p>
            <div class="mt-3 divide-y divide-slate-100">
                @foreach ($this->ventasCliente as $vc)
                    <div class="py-2.5 flex items-center justify-between gap-4 {{ $this->ventaId === $vc->id ? 'bg-indigo-50/60 -mx-2 px-2 rounded-lg' : '' }}">
                        <div class="text-sm">
                            <span class="font-medium text-slate-800">{{ $vc->folio }}</span>
                            <span class="text-xs text-slate-500 ml-2">{{ $vc->fecha_venta?->format('d/m/Y') }}</span>
                            <span class="text-xs text-slate-600 ml-2">Total: ${{ number_format((float) $vc->total, 2) }}</span>
                            <span class="text-xs text-slate-500 ml-2">({{ $vc->detalle->count() }} {{ $vc->detalle->count() === 1 ? 'producto' : 'productos' }})</span>
                        </div>
                        <div>
                            @if ($this->ventaId === $vc->id)
                                <span class="text-xs font-semibold text-indigo-700 bg-indigo-100 px-2 py-1 rounded">Seleccionada</span>
                            @else
                                <button type="button" wire:click="seleccionarVenta({{ $vc->id }})" class="fp-btn fp-btn-secundario fp-btn-sm">
                                    Seleccionar venta
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="fp-tarjeta p-4 fp-formulario fp-aparecer" data-espacio-panel="pedidos">
            <p class="text-sm font-medium text-slate-800">Detalle de pedido</p>
            @if ($errorBusquedaPedido)
                <x-panel.alerta tipo="error" class="mt-3">{{ $errorBusquedaPedido }}</x-panel.alerta>
            @endif
            @if (! $this->pedidoEncontrado)
                <p class="mt-2 text-xs text-slate-500">Busca un pedido por folio (PED-…) arriba para consultar devoluciones.</p>
            @endif
        @if ($this->notaEmitida)
            <x-panel.alerta tipo="exito" class="mt-3">
                <span data-nota-emitida="{{ $this->notaEmitida->id }}">Nota de devolución {{ $this->notaEmitida->folio }} por ${{ number_format((float) $this->notaEmitida->monto_original, 2) }}.
                Vence el {{ $this->notaEmitida->fecha_vencimiento?->timezone('America/Mexico_City')->format('d/m/Y H:i') }} (hora de Ciudad de México).</span>
            </x-panel.alerta>
        @endif
        @if ($this->pedidoEncontrado)
            <div class="mt-3 text-sm" role="status">
                <p class="font-medium text-slate-800">Pedido {{ $this->pedidoEncontrado->folio }}</p>
                <x-ui.insignia-estado :estado="$this->pedidoEncontrado->estado" />
                <a href="{{ route('pedidos.show', $this->pedidoEncontrado->id) }}"
                    class="fp-enlace ml-2">Ver detalle del pedido</a>
            </div>
            @if ($this->pedidoEncontrado->estado === 'entregado')
                <table class="fp-tabla mt-3 min-w-full text-sm" data-partidas-pedido="{{ $this->pedidoEncontrado->id }}">
                    <thead class="text-left text-[11px] uppercase tracking-wide text-slate-600">
                        <tr><th class="py-1">Producto</th><th class="py-1">Modelo</th><th class="py-1">Talla</th><th class="py-1">Color</th><th class="py-1 text-right">Cantidad</th>@if ($this->puedeResolver)<th class="py-1 text-right">Devolver</th>@endif</tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($this->partidasPedido as $p)
                            <tr wire:key="partida-{{ $p->id }}" data-partida="{{ $p->id }}">
                                <td class="py-2">{{ $p->producto_nombre }}</td>
                                <td class="py-2">{{ $p->modelo ?? '—' }}</td>
                                <td class="py-2">{{ $p->talla }}</td>
                                <td class="py-2">{{ $p->color }}</td>
                                <td class="py-2 text-right">{{ $p->cantidad }}</td>
                                @if ($this->puedeResolver)
                                    @php($dv = $this->devolucionPartidas[$p->id] ?? null)
                                    <td class="py-2 text-right">
                                        @if ($dv && $dv['elegible'])
                                            <label class="sr-only" for="devolver-{{ $p->id }}">Unidades a devolver de {{ $p->producto_nombre }}</label>
                                            <input id="devolver-{{ $p->id }}" type="number" min="0" max="{{ $dv['disponible'] }}" step="1"
                                                wire:model.live="devolver.{{ $p->id }}" @disabled($confirmandoDevolucion)
                                                class="w-20 rounded-lg text-right text-sm" data-devolver="{{ $p->id }}">
                                            <span class="block text-xs text-slate-500">de {{ $dv['disponible'] }}</span>
                                        @else
                                            <span class="text-xs text-slate-500">{{ $dv['motivo'] ?? '' }}</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-2 text-slate-500">El pedido no tiene partidas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-slate-500">Partidas del pedido entregado.</p>
                @if ($this->puedeResolver)
                    <div class="mt-3 space-y-2" data-devolucion>
                        @if ($errorDevolucion)
                            <x-panel.alerta tipo="error">{{ $errorDevolucion }}</x-panel.alerta>
                        @endif
                        @if ($confirmandoDevolucion)
                            {{-- A20: antes era un <div role="dialog"> suelto dentro de
                                 la pagina, asi que no atrapaba el foco ni se cerraba con
                                 Escape, y el Enter podia caer en "Confirmar". Emitir una
                                 nota de devolucion no se deshace. --}}
                            <x-ui.confirmar titulo="Confirmar nota de devolución"
                                confirmar="confirmarDevolucion" cancelar="cancelarDevolucion"
                                etiqueta="Confirmar devolución" etiquetaCargando="Emitiendo…"
                                variante="primario" ancho="max-w-lg">
                                Se emitirá una nota de devolución por <strong class="tabular-nums">${{ number_format($this->totalDevolucionCentavos / 100, 2) }}</strong>
                                a nombre de {{ $this->pedidoEncontrado->clienteDirecto?->nombre ?? $this->pedidoEncontrado->revendedorAfiliacion?->revendedor?->nombre ?? 'el cliente del pedido' }}.
                                Vence 90 días después de emitirse. Se usa completa en un solo pedido de {{ \App\Models\Distribuidora::query()->whereKey(\App\Support\Tenant::id())->value('nombre_comercial') ?? 'esta distribuidora' }}; si sobra saldo, la diferencia va a un vale nuevo.
                            </x-ui.confirmar>
                        @endif
                        @unless ($confirmandoDevolucion)
                            <button type="button" wire:click="prepararDevolucion" class="fp-btn fp-btn-primario fp-btn-sm">Devolver productos seleccionados</button>
                        @endunless
                    </div>
                @endif
            @else
                <p class="mt-2 text-xs text-slate-500">Las partidas se muestran cuando el pedido está entregado.</p>
            @endif
        @endif
    </div>

        <div class="fp-tarjeta p-4 fp-formulario fp-aparecer" data-espacio-panel="mostrador" data-espacio="mostrador">
            <p class="text-sm font-medium text-slate-800">Cambio de una venta de mostrador</p>
            @unless ($this->venta)
                <p class="mt-2 text-xs text-slate-500">Busca una venta por folio (VD-…) o teléfono del cliente arriba para tramitar un cambio.</p>
            @endunless

            @if ($this->venta)
                <div class="mt-4">
                    <p class="text-sm text-slate-700">
                        Venta {{ $this->venta->folio }} · {{ $this->venta->fecha_venta?->format('d/m/Y') }} ·
                        <span class="font-medium">{{ $this->venta->clienteDirecto?->nombre ?? 'Sin cliente' }}</span>
                    </p>

                    @if (! $this->venta->cliente_directo_id)
                        <div class="mt-3 p-3 rounded-xl border border-amber-200 bg-amber-50/60" data-alta-cliente-venta>
                            <p class="text-xs font-semibold text-amber-900 mb-1">Esta venta se registró sin cliente</p>
                            <p class="text-xs text-amber-800 mb-2">Para tramitar un cambio, la venta debe estar a nombre de un cliente. Puedes registrarlo con alta rápida o seleccionarlo si ya existe.</p>
                            @include('cliente.selector-alta', [
                                'seleccion' => 'altaClienteDirectoId',
                                'opciones' => $this->clientesFiltrados,
                                'vacio' => '— Seleccionar cliente existente —',
                                'etiqueta' => 'Asignar cliente a la venta',
                            ])
                        </div>
                    @endif

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
    </div>

    <p class="text-sm font-medium text-slate-800">Solicitudes de cambio</p>

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
        <x-ui.cargando objetivo="estado,espacio,cambiarEspacio,gotoPage,nextPage,previousPage" />
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
