<?php

use App\Models\CicloCompra;
use App\Services\Auth\PermisoModuloService;
use App\Services\Reporte\DemandaPorTemporadaMarcaAction;
use App\Services\Reporte\MontosPendientesAction;
use App\Services\Reporte\PedidosPorRevendedorAction;
use App\Services\Reporte\ResumenOperativoAction;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Reportes — FootwearPoint')] class extends Component {
    public string $desde = '';
    public string $hasta = '';
    public string $estado = '';
    public string $cicloId = '';

    /** TG-251: error legible del reporte por cliente mayorista. */
    public string $errorMayoristas = '';

    /** TG-252: error legible del reporte de montos pendientes. */
    public string $errorPendientes = '';

    /** TG-253: filtros propios del reporte de demanda y su error legible. */
    public string $demandaCampanaId = '';
    public string $demandaMarcaId = '';
    public string $errorDemanda = '';

    public function mount()
    {
        if (! Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }

        // TG-246: los montos son de 'reportes.ver_financieros'. Antes solo se
        // ocultaba el enlace del menú y por URL cualquier empleado los veía.
        if (! app(PermisoModuloService::class)->puede(Auth::user(), PermisoModuloService::REPORTES_FINANCIEROS)) {
            abort(403, MensajeError::SIN_PERMISO);
        }
    }

    public function getCiclosProperty()
    {
        return CicloCompra::query()->orderByDesc('fecha_apertura')->get(['id', 'nombre']);
    }

    public function getResumenProperty(): array
    {
        return app(ResumenOperativoAction::class)->ejecutar(
            $this->desde !== '' ? $this->desde : null,
            $this->hasta !== '' ? $this->hasta : null,
            $this->estado !== '' ? $this->estado : null,
            $this->cicloId !== '' ? (int) $this->cicloId : null,
        );
    }

    /**
     * TG-251 (E14-05): pedidos y montos por cliente mayorista. Usa las fechas
     * (por fecha de colocación) y el ciclo de la pantalla; el estado no aplica.
     */
    public function getPorClienteMayoristaProperty(): array
    {
        $this->errorMayoristas = '';

        try {
            return app(PedidosPorRevendedorAction::class)->ejecutar(
                $this->desde !== '' ? $this->desde : null,
                $this->hasta !== '' ? $this->hasta : null,
                $this->cicloId !== '' ? (int) $this->cicloId : null,
            );
        } catch (\Throwable $e) {
            $this->errorMayoristas = MensajeError::paraUsuario($e, 'No se pudo calcular el reporte por cliente mayorista.');

            return ['filas' => [], 'totales' => ['clientes' => 0, 'pedidos' => 0, 'monto' => 0, 'pagado' => 0, 'pendiente' => 0]];
        }
    }

    /**
     * TG-252 (E14-03): lo que falta por cobrar al día de hoy y los vales
     * vigentes. Solo usa el filtro de ciclo (no fechas ni estado).
     */
    public function getMontosPendientesProperty(): array
    {
        $this->errorPendientes = '';

        try {
            return app(MontosPendientesAction::class)->ejecutar($this->cicloId !== '' ? (int) $this->cicloId : null);
        } catch (\Throwable $e) {
            $this->errorPendientes = MensajeError::paraUsuario($e, 'No se pudieron calcular los montos pendientes.');
            $vacio = ['filas' => [], 'totales' => ['clientes' => 0, 'pedidos' => 0, 'monto' => 0, 'pagado' => 0, 'pendiente' => 0, 'anticipo_pendiente' => 0]];

            return ['clientes_directos' => $vacio, 'revendedores' => $vacio, 'vales_vigentes' => ['filas' => [], 'totales' => ['vales' => 0, 'saldo' => 0]]];
        }
    }

    public function getTemporadasDemandaProperty()
    {
        return app(DemandaPorTemporadaMarcaAction::class)->temporadasParaFiltro();
    }

    public function getMarcasDemandaProperty()
    {
        return app(DemandaPorTemporadaMarcaAction::class)->marcasParaFiltro();
    }

    /**
     * TG-253 (E14-04): demanda por temporada y marca. Usa las fechas y el
     * ciclo de la pantalla, más sus filtros de temporada y marca.
     */
    public function getDemandaProperty(): array
    {
        $this->errorDemanda = '';

        try {
            return app(DemandaPorTemporadaMarcaAction::class)->ejecutar(
                $this->demandaCampanaId !== '' ? (int) $this->demandaCampanaId : null,
                $this->demandaMarcaId !== '' ? (int) $this->demandaMarcaId : null,
                $this->desde !== '' ? $this->desde : null,
                $this->hasta !== '' ? $this->hasta : null,
                $this->cicloId !== '' ? (int) $this->cicloId : null,
            );
        } catch (\Throwable $e) {
            $this->errorDemanda = MensajeError::paraUsuario($e, 'No se pudo calcular la demanda.');

            return ['por_marca' => [], 'productos' => [], 'por_temporada' => [], 'totales' => ['piezas' => 0, 'pedidos' => 0, 'monto' => 0]];
        }
    }
};
?>

<div>
    <x-panel.encabezado titulo="Reportes" subtitulo="Resumen operativo de la distribuidora" class="mb-6" />

    <div class="mb-6 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-slate-500 mb-1">Desde</label>
            <input type="date" wire:model.live="desde" class="rounded-lg text-sm" />
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Hasta</label>
            <input type="date" wire:model.live="hasta" class="rounded-lg text-sm" />
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Ciclo</label>
            <select wire:model.live="cicloId" class="rounded-lg text-sm">
                <option value="">Todos</option>
                @foreach ($this->ciclos as $ciclo)
                    <option value="{{ $ciclo->id }}">{{ $ciclo->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Estado del pedido</label>
            <select wire:model.live="estado" class="rounded-lg text-sm">
                <option value="">Todos</option>
                <option value="borrador">Borrador</option>
                <option value="colocado">Colocado</option>
                <option value="recibido_distribuidora">Recibido</option>
                <option value="listo_entrega">Listo para entrega</option>
                <option value="entregado">Entregado</option>
            </select>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 mb-8">
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <p class="text-xs text-slate-500">Pedidos</p>
            <p class="text-2xl font-semibold">{{ $this->resumen['pedidos']['total'] }}</p>
            <p class="text-xs text-slate-500 mt-1">
                ${{ number_format($this->resumen['pedidos']['monto_total'], 2) }}
            </p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <p class="text-xs text-slate-500">Ventas directas</p>
            <p class="text-2xl font-semibold">{{ $this->resumen['ventas_directas']['total'] }}</p>
            <p class="text-xs text-slate-500 mt-1">
                ${{ number_format($this->resumen['ventas_directas']['monto_total'], 2) }}
            </p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
            <p class="text-xs text-slate-500">Vales activos</p>
            <p class="text-2xl font-semibold">{{ $this->resumen['vales']['activos'] }}</p>
            <p class="text-xs text-slate-500 mt-1">
                Saldo ${{ number_format($this->resumen['vales']['saldo_activo'], 2) }}
            </p>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b font-medium text-slate-800">Pedidos por estado</div>
        <table class="min-w-full text-sm">
            <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                <tr>
                    <th class="px-4 py-2">Estado</th>
                    <th class="px-4 py-2 text-right">Cantidad</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->resumen['pedidos']['por_estado'] as $fila)
                    <tr>
                        <td class="px-4 py-3">
                            <x-ui.insignia-estado :estado="$fila['estado']" />
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $fila['cantidad'] }}</td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="2" mensaje="Sin datos" />
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b font-medium text-slate-800">Pedidos por ciclo</div>
        <table class="min-w-full text-sm">
            <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                <tr>
                    <th class="px-4 py-2">Ciclo</th>
                    <th class="px-4 py-2 text-right">Cantidad</th>
                    <th class="px-4 py-2 text-right">Monto</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->resumen['pedidos']['por_ciclo'] ?? [] as $fila)
                    <tr>
                        <td class="px-4 py-3">{{ $fila['ciclo'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $fila['cantidad'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['monto'], 2) }}</td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="3" mensaje="Sin datos" />
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- TG-251 (E14-05): quiénes compran más. --}}
    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
        @php($mayoristas = $this->porClienteMayorista)
        <div class="px-4 py-3 border-b font-medium text-slate-800">
            Pedidos por cliente mayorista
            <span class="block text-xs font-normal text-slate-500">
                Pedidos colocados en el periodo (por fecha de colocación), sin borradores ni pedidos rechazados, descartados, no surtidos o vencidos. No usa el filtro de estado.
            </span>
        </div>
        @if ($errorMayoristas)
            <x-panel.alerta tipo="error" class="m-4">{{ $errorMayoristas }}</x-panel.alerta>
        @endif
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr>
                        <th class="px-4 py-2">Cliente mayorista</th>
                        <th class="px-4 py-2 text-right">Pedidos</th>
                        <th class="px-4 py-2 text-right">Monto</th>
                        <th class="px-4 py-2 text-right">Pagado</th>
                        <th class="px-4 py-2 text-right">Pendiente</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($mayoristas['filas'] as $fila)
                        <tr wire:key="may-{{ $fila['revendedor_distribuidora_id'] }}">
                            <td class="px-4 py-3">{{ $fila['nombre'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $fila['pedidos'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['monto'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['pagado'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['pendiente'], 2) }}</td>
                        </tr>
                    @empty
                        <x-panel.vacio colspan="5" mensaje="Sin pedidos de clientes mayoristas en el periodo" />
                    @endforelse
                </tbody>
                @if ($mayoristas['filas'] !== [])
                    <tfoot class="bg-fp-page font-semibold">
                        <tr>
                            <td class="px-4 py-3">Total ({{ $mayoristas['totales']['clientes'] }} clientes)</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $mayoristas['totales']['pedidos'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($mayoristas['totales']['monto'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($mayoristas['totales']['pagado'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($mayoristas['totales']['pendiente'], 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- TG-252 (E14-03): cuánto falta por cobrar y vales vigentes. --}}
    @php($pendientes = $this->montosPendientes)
    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 font-medium text-slate-800">
            Montos pendientes
            <span class="block text-xs font-normal text-slate-500">
                Al día de hoy: pedidos colocados o entregados que todavía tienen saldo (sin borradores ni pedidos rechazados, descartados, no surtidos o vencidos). Solo usa el filtro de ciclo.
            </span>
        </div>
        @if ($errorPendientes)
            <x-panel.alerta tipo="error" class="m-4">{{ $errorPendientes }}</x-panel.alerta>
        @endif
        <div class="overflow-x-auto border-t border-slate-100">
            <p class="px-4 pt-3 text-sm font-medium text-slate-700">Clientes</p>
            <table class="min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr>
                        <th class="px-4 py-2">Cliente</th>
                        <th class="px-4 py-2 text-right">Pedidos con saldo</th>
                        <th class="px-4 py-2 text-right">Monto</th>
                        <th class="px-4 py-2 text-right">Pagado</th>
                        <th class="px-4 py-2 text-right">Anticipo pendiente</th>
                        <th class="px-4 py-2 text-right">Pendiente</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($pendientes['clientes_directos']['filas'] as $fila)
                        <tr wire:key="pend-clientes_directos-{{ $fila['id'] }}">
                            <td class="px-4 py-3">{{ $fila['nombre'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $fila['pedidos'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['monto'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['pagado'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['anticipo_pendiente'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold">${{ number_format($fila['pendiente'], 2) }}</td>
                        </tr>
                    @empty
                        <x-panel.vacio colspan="6" mensaje="Ningún cliente tiene saldo pendiente" />
                    @endforelse
                </tbody>
                @if ($pendientes['clientes_directos']['filas'] !== [])
                    <tfoot class="bg-fp-page font-semibold">
                        <tr>
                            <td class="px-4 py-3">Total ({{ $pendientes['clientes_directos']['totales']['clientes'] }})</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $pendientes['clientes_directos']['totales']['pedidos'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($pendientes['clientes_directos']['totales']['monto'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($pendientes['clientes_directos']['totales']['pagado'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($pendientes['clientes_directos']['totales']['anticipo_pendiente'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($pendientes['clientes_directos']['totales']['pendiente'], 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <div class="overflow-x-auto border-t border-slate-100">
            <p class="px-4 pt-3 text-sm font-medium text-slate-700">Clientes mayoristas</p>
            <table class="min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr>
                        <th class="px-4 py-2">Cliente mayorista</th>
                        <th class="px-4 py-2 text-right">Pedidos con saldo</th>
                        <th class="px-4 py-2 text-right">Monto</th>
                        <th class="px-4 py-2 text-right">Pagado</th>
                        
                        <th class="px-4 py-2 text-right">Pendiente</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($pendientes['revendedores']['filas'] as $fila)
                        <tr wire:key="pend-revendedores-{{ $fila['id'] }}">
                            <td class="px-4 py-3">{{ $fila['nombre'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $fila['pedidos'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['monto'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['pagado'], 2) }}</td>
                            
                            <td class="px-4 py-3 text-right tabular-nums font-semibold">${{ number_format($fila['pendiente'], 2) }}</td>
                        </tr>
                    @empty
                        <x-panel.vacio colspan="5" mensaje="Ningún cliente mayorista tiene saldo pendiente" />
                    @endforelse
                </tbody>
                @if ($pendientes['revendedores']['filas'] !== [])
                    <tfoot class="bg-fp-page font-semibold">
                        <tr>
                            <td class="px-4 py-3">Total ({{ $pendientes['revendedores']['totales']['clientes'] }})</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $pendientes['revendedores']['totales']['pedidos'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($pendientes['revendedores']['totales']['monto'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($pendientes['revendedores']['totales']['pagado'], 2) }}</td>
                            
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($pendientes['revendedores']['totales']['pendiente'], 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b font-medium text-slate-800">
            Vales vigentes
            <span class="text-xs font-normal text-slate-500">
                ({{ $pendientes['vales_vigentes']['totales']['vales'] }} · saldo disponible ${{ number_format($pendientes['vales_vigentes']['totales']['saldo'], 2) }})
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr>
                        <th class="px-4 py-2">Folio</th>
                        <th class="px-4 py-2">Dueño</th>
                        <th class="px-4 py-2">Vence</th>
                        <th class="px-4 py-2 text-right">Saldo disponible</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($pendientes['vales_vigentes']['filas'] as $vale)
                        <tr wire:key="vale-vig-{{ $vale['id'] }}">
                            <td class="px-4 py-3 font-mono text-xs">{{ $vale['folio'] }}</td>
                            <td class="px-4 py-3">
                                {{ $vale['propietario']['nombre'] }}
                                <span class="block text-xs text-slate-500">{{ $vale['propietario']['tipo'] === 'revendedor' ? 'Cliente mayorista' : 'Cliente' }}</span>
                            </td>
                            <td class="px-4 py-3">{{ $vale['fecha_vencimiento'] ? \Illuminate\Support\Carbon::parse($vale['fecha_vencimiento'])->format('d/m/Y') : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($vale['saldo'], 2) }}</td>
                        </tr>
                    @empty
                        <x-panel.vacio colspan="4" mensaje="No hay vales vigentes" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- TG-253 (E14-04): qué se pide más, para orientar compras a fábrica. --}}
    @php($demanda = $this->demanda)
    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 font-medium text-slate-800">
            Demanda por temporada y marca
            <span class="block text-xs font-normal text-slate-500">
                Piezas pedidas en pedidos colocados (por fecha de colocación), incluidas las no surtidas; sin borradores ni pedidos rechazados, descartados o vencidos. No incluye ventas de mostrador.
            </span>
        </div>
        <div class="flex flex-wrap gap-3 px-4 pb-3">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Temporada</label>
                <select wire:model.live="demandaCampanaId" class="rounded-lg text-sm">
                    <option value="">Todas</option>
                    @foreach ($this->temporadasDemanda as $temporada)
                        <option value="{{ $temporada->id }}">{{ $temporada->nombre }} · {{ $temporada->linea?->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Marca</label>
                <select wire:model.live="demandaMarcaId" class="rounded-lg text-sm">
                    <option value="">Todas</option>
                    @foreach ($this->marcasDemanda as $marca)
                        <option value="{{ $marca->id }}">{{ $marca->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <p class="self-end text-sm text-slate-600">
                Total: <span class="font-semibold">{{ $demanda['totales']['piezas'] }} piezas</span>
                en {{ $demanda['totales']['pedidos'] }} pedidos · ${{ number_format($demanda['totales']['monto'], 2) }}
            </p>
        </div>
        @if ($errorDemanda)
            <x-panel.alerta tipo="error" class="m-4">{{ $errorDemanda }}</x-panel.alerta>
        @endif
        <div class="overflow-x-auto border-t border-slate-100">
            <p class="px-4 pt-3 text-sm font-medium text-slate-700">Marcas</p>
            <table class="min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr><th class="px-4 py-2">Marca</th><th class="px-4 py-2 text-right">Piezas</th><th class="px-4 py-2 text-right">Pedidos</th><th class="px-4 py-2 text-right">Modelos</th><th class="px-4 py-2 text-right">Monto</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($demanda['por_marca'] as $fila)
                        <tr><td class="px-4 py-3">{{ $fila['nombre'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $fila['piezas'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $fila['pedidos'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $fila['modelos'] }}</td><td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['monto'], 2) }}</td></tr>
                    @empty
                        <x-panel.vacio colspan="5" mensaje="Sin demanda en el periodo" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="overflow-x-auto border-t border-slate-100">
            <p class="px-4 pt-3 text-sm font-medium text-slate-700">Productos más solicitados <span class="text-xs font-normal text-slate-500">(los 20 con más piezas)</span></p>
            <table class="min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr><th class="px-4 py-2">Producto</th><th class="px-4 py-2">Marca</th><th class="px-4 py-2">Temporada</th><th class="px-4 py-2 text-right">Piezas</th><th class="px-4 py-2 text-right">Pedidos</th><th class="px-4 py-2 text-right">Monto</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($demanda['productos'] as $fila)
                        <tr><td class="px-4 py-3">{{ $fila['producto'] }}<span class="block text-xs text-slate-500">Modelo {{ $fila['modelo'] }}</span></td><td class="px-4 py-3">{{ $fila['marca'] }}</td><td class="px-4 py-3">{{ $fila['temporada'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $fila['piezas'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $fila['pedidos'] }}</td><td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['monto'], 2) }}</td></tr>
                    @empty
                        <x-panel.vacio colspan="6" mensaje="Sin demanda en el periodo" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="overflow-x-auto border-t border-slate-100">
            <p class="px-4 pt-3 text-sm font-medium text-slate-700">Temporadas</p>
            <table class="min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr><th class="px-4 py-2">Temporada</th><th class="px-4 py-2">Línea</th><th class="px-4 py-2 text-right">Piezas</th><th class="px-4 py-2 text-right">Pedidos</th><th class="px-4 py-2 text-right">Monto</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($demanda['por_temporada'] as $fila)
                        <tr><td class="px-4 py-3">{{ $fila['nombre'] }}</td><td class="px-4 py-3">{{ $fila['linea'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $fila['piezas'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $fila['pedidos'] }}</td><td class="px-4 py-3 text-right tabular-nums">${{ number_format($fila['monto'], 2) }}</td></tr>
                    @empty
                        <x-panel.vacio colspan="5" mensaje="Sin demanda en el periodo" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b font-medium text-slate-800">
            Detalle de pedidos
            <span class="text-xs font-normal text-slate-500">(máx. 100 del periodo)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr>
                        <th class="px-4 py-2">Folio</th>
                        <th class="px-4 py-2">Quién</th>
                        <th class="px-4 py-2">Fecha</th>
                        <th class="px-4 py-2">Estado</th>
                        <th class="px-4 py-2">Descripción</th>
                        <th class="px-4 py-2 text-right">Total</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->resumen['pedidos']['lista'] ?? [] as $pedido)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-medium text-slate-800 whitespace-nowrap">
                                {{ $pedido['folio'] ?? '#' . $pedido['id'] }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-slate-800">{{ $pedido['quien'] }}</div>
                                <div class="text-xs text-slate-400">
                                    {{ $pedido['tipo'] === 'Revendedor' ? 'Cliente mayorista' : $pedido['tipo'] }}
                                </div>
                                @if (!empty($pedido['capturado_por']))
                                    <div class="text-xs text-slate-400">Capturó: {{ $pedido['capturado_por'] }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-slate-600">
                                {{ $pedido['fecha'] ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.insignia-estado :estado="$pedido['estado']" />
                            </td>
                            <td class="px-4 py-3 text-slate-600 max-w-xs">
                                <span class="line-clamp-3" title="{{ $pedido['descripcion'] }}">
                                    {{ $pedido['descripcion'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                ${{ number_format($pedido['total'], 2) }}
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('pedidos.show', $pedido['id']) }}"
                                    class="text-fp-primary text-xs font-medium hover:underline">
                                    Ver pedido
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-panel.vacio colspan="7" mensaje="No hay pedidos en el periodo." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b font-medium text-slate-800">
            Detalle de ventas (punto de venta)
            <span class="text-xs font-normal text-slate-500">(máx. 100 del periodo)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr>
                        <th class="px-4 py-2">Folio</th>
                        <th class="px-4 py-2">Quién</th>
                        <th class="px-4 py-2">Fecha</th>
                        <th class="px-4 py-2">Estado</th>
                        <th class="px-4 py-2">Descripción</th>
                        <th class="px-4 py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->resumen['ventas_directas']['lista'] ?? [] as $venta)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-medium text-slate-800 whitespace-nowrap">
                                {{ $venta['folio'] ?? '#' . $venta['id'] }}
                                <a href="{{ route('ventas-directas.comprobante', $venta['id']) }}" target="_blank"
                                    class="block text-xs font-normal text-fp-primary hover:underline">Ver comprobante</a>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-slate-800">{{ $venta['quien'] }}</div>
                                @if (!empty($venta['sucursal']))
                                    <div class="text-xs text-slate-400">{{ $venta['sucursal'] }}</div>
                                @endif
                                @if (!empty($venta['capturado_por']))
                                    <div class="text-xs text-slate-400">Registró: {{ $venta['capturado_por'] }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-slate-600">
                                {{ $venta['fecha'] ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium
                                    {{ ($venta['estado'] ?? '') === 'completada' ? 'bg-fp-badge-success-bg text-fp-badge-success-fg' : 'bg-fp-badge-neutral-bg text-fp-badge-neutral-fg' }}">
                                    {{ ucfirst($venta['estado'] ?? '—') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 max-w-xs">
                                <span class="line-clamp-3" title="{{ $venta['descripcion'] }}">
                                    {{ $venta['descripcion'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                ${{ number_format($venta['total'], 2) }}
                            </td>
                        </tr>
                    @empty
                        <x-panel.vacio colspan="6" mensaje="No hay ventas directas en el periodo." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>