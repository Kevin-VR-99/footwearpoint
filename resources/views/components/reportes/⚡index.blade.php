<?php

use App\Models\CicloCompra;
use App\Models\Distribuidora;
use App\Services\Auth\PermisoModuloService;
use App\Services\Reporte\DemandaPorTemporadaMarcaAction;
use App\Services\Reporte\EstadoDeCuentaAction;
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

    /** Pulido UX: un clic deja todos los filtros como al entrar. */
    public function limpiarFiltros(): void
    {
        $this->reset('desde', 'hasta', 'estado', 'cicloId', 'demandaCampanaId', 'demandaMarcaId');
    }

    public function getDistribuidoraProperty(): Distribuidora
    {
        return Distribuidora::query()->findOrFail(Tenant::id());
    }

    public function getEstadoDeCuentaProperty(): array
    {
        return app(EstadoDeCuentaAction::class)->ejecutar(
            $this->desde !== '' ? $this->desde : null,
            $this->hasta !== '' ? $this->hasta : null,
            $this->estado !== '' ? $this->estado : null,
            $this->cicloId !== '' ? (int) $this->cicloId : null,
        );
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
    @php
        $casa = $this->distribuidora;
        $cuenta = $this->estadoDeCuenta;
        $resumen = $this->resumen;
        $mayoristas = $this->porClienteMayorista;
        $pendientes = $this->montosPendientes;
        $demanda = $this->demanda;
        $periodo = match (true) {
            $desde !== '' && $hasta !== '' => \Illuminate\Support\Carbon::parse($desde)->format('d/m/Y').' al '.\Illuminate\Support\Carbon::parse($hasta)->format('d/m/Y'),
            $desde !== '' => 'Desde el '.\Illuminate\Support\Carbon::parse($desde)->format('d/m/Y'),
            $hasta !== '' => 'Hasta el '.\Illuminate\Support\Carbon::parse($hasta)->format('d/m/Y'),
            default => 'Todos los movimientos',
        };
        $filtrosDescarga = array_filter(['desde' => $desde, 'hasta' => $hasta, 'estado' => $estado, 'ciclo' => $cicloId, 'temporada' => $demandaCampanaId, 'marca' => $demandaMarcaId], fn ($v) => $v !== '');
        $filtrosActivos = count($filtrosDescarga);
        $porCobrar = (float) $pendientes['clientes_directos']['totales']['pendiente'] + (float) $pendientes['revendedores']['totales']['pendiente'];
        $dinero = fn ($n) => '$'.number_format((float) $n, 2);
        $estados = ['borrador' => 'Borrador', 'colocado' => 'Colocado', 'recibido_distribuidora' => 'Recibido', 'listo_entrega' => 'Listo para entrega', 'entregado' => 'Entregado'];
        $graficaEstados = array_map(fn ($f) => ['etiqueta' => $estados[$f['estado']] ?? ucfirst(str_replace('_', ' ', $f['estado'])), 'valor' => $f['cantidad']], $resumen['pedidos']['por_estado']);
        $graficaCiclos = array_map(fn ($f) => ['etiqueta' => $f['ciclo'], 'valor' => $f['monto'], 'texto' => $dinero($f['monto'])], $resumen['pedidos']['por_ciclo'] ?? []);
        $graficaMarcas = array_map(fn ($f) => ['etiqueta' => $f['nombre'], 'valor' => $f['piezas'], 'texto' => $f['piezas'].' pzas'], array_slice($demanda['por_marca'], 0, 8));
        $deudores = array_merge(
            array_map(fn ($f) => ['etiqueta' => $f['nombre'], 'valor' => $f['pendiente'], 'texto' => $dinero($f['pendiente'])], $pendientes['clientes_directos']['filas']),
            array_map(fn ($f) => ['etiqueta' => $f['nombre'].' (mayorista)', 'valor' => $f['pendiente'], 'texto' => $dinero($f['pendiente'])], $pendientes['revendedores']['filas']),
        );
        usort($deudores, fn ($a, $b) => $b['valor'] <=> $a['valor']);
        $deudores = array_slice($deudores, 0, 6);
        $pestanas = ['resumen' => 'Estado de cuenta', 'pedidos' => 'Pedidos', 'ventas' => 'Ventas de mostrador', 'cobrar' => 'Por cobrar', 'demanda' => 'Demanda'];
    @endphp

    {{-- Encabezado compacto: quién, qué periodo y descargas con los mismos filtros. --}}
    <header id="estado-cuenta" class="fp-tarjeta fp-aparecer mb-4 p-4 sm:p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                @if ($casa->logotipo_url)
                    <img src="{{ $casa->logotipo_url }}" alt="Logo de {{ $casa->nombre_comercial }}" loading="lazy" decoding="async" width="48" height="48" class="h-12 w-12 shrink-0 rounded-xl border border-slate-200 bg-white object-contain">
                @else
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-fp-sidebar text-lg font-semibold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr($casa->nombre_comercial, 0, 1)) }}</span>
                @endif
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-fp-primary">Reportes · Estado de cuenta</p>
                    <h1 class="truncate text-xl font-bold text-fp-sidebar">{{ $casa->nombre_comercial }}</h1>
                    <p class="truncate text-sm text-slate-500">@if ($casa->rfc) RFC {{ $casa->rfc }} · @endif{{ $periodo }}</p>
                </div>
            </div>
            <div class="no-imprimir grid grid-cols-2 gap-2 sm:flex" data-descargas-reporte>
                <a href="{{ route('reportes.descargar', ['formato' => 'pdf'] + $filtrosDescarga) }}" class="fp-btn fp-btn-oscuro justify-center whitespace-nowrap px-3" data-descargar="pdf">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16" /></svg>
                    Descargar PDF
                </a>
                <a href="{{ route('reportes.descargar', ['formato' => 'xlsx'] + $filtrosDescarga) }}" class="fp-btn fp-btn-secundario justify-center whitespace-nowrap px-3" data-descargar="xlsx">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18v14H3zM3 10h18M9 5v14" /></svg>
                    Descargar Excel
                </a>
            </div>
        </div>
    </header>

    {{-- Filtros compactos y plegables (abiertos en escritorio, cerrados en celular). --}}
    <section class="no-imprimir fp-tarjeta fp-aparecer mb-5" x-data="{ abierto: window.matchMedia('(min-width: 768px)').matches }" data-filtros-reporte>
        <div class="flex items-center justify-between gap-3 px-4 py-3">
            <button type="button" @click="abierto = !abierto" :aria-expanded="abierto.toString()" aria-controls="filtros-reporte"
                class="group inline-flex items-center gap-2 rounded-lg text-sm font-semibold text-fp-sidebar">
                <svg class="h-4 w-4 text-fp-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18l-7 8.5V19l-4 2v-8.5z" /></svg>
                Filtros
                @if ($filtrosActivos > 0)
                    <x-panel.insignia variante="info">{{ $filtrosActivos }} activo{{ $filtrosActivos === 1 ? '' : 's' }}</x-panel.insignia>
                @endif
                <svg class="h-4 w-4 text-slate-400 transition-transform duration-200 motion-reduce:transition-none" :class="abierto && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
            </button>
            <div class="flex items-center gap-3">
                <span wire:loading.delay class="inline-flex items-center gap-1.5 text-xs text-slate-500" role="status">
                    <span class="h-3 w-3 animate-spin rounded-full border-2 border-fp-primary border-r-transparent motion-reduce:animate-none" aria-hidden="true"></span>
                    Actualizando…
                </span>
                @if ($filtrosActivos > 0)
                    <button type="button" wire:click="limpiarFiltros" class="fp-accion fp-accion-neutra" data-limpiar-filtros>Limpiar</button>
                @endif
            </div>
        </div>
        <div id="filtros-reporte" x-show="abierto" x-collapse.duration.200ms x-cloak class="border-t border-slate-100">
            <div class="grid grid-cols-2 gap-3 p-4 md:grid-cols-3 xl:grid-cols-6">
                <div class="fp-campo">
                    <label for="index-desde" class="mb-1 block text-xs font-medium text-slate-500">Desde</label>
                    <input id="index-desde" type="date" wire:model.live="desde" class="w-full rounded-lg text-sm" />
                </div>
                <div class="fp-campo">
                    <label for="index-hasta" class="mb-1 block text-xs font-medium text-slate-500">Hasta</label>
                    <input id="index-hasta" type="date" wire:model.live="hasta" class="w-full rounded-lg text-sm" />
                </div>
                <div class="fp-campo">
                    <label for="index-cicloId" class="mb-1 block text-xs font-medium text-slate-500">Ciclo</label>
                    <select id="index-cicloId" wire:model.live="cicloId" class="w-full rounded-lg text-sm">
                        <option value="">Todos</option>
                        @foreach ($this->ciclos as $ciclo)
                            <option value="{{ $ciclo->id }}">{{ $ciclo->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fp-campo">
                    <label for="index-estado" class="mb-1 block text-xs font-medium text-slate-500">Estado del pedido</label>
                    <select id="index-estado" wire:model.live="estado" class="w-full rounded-lg text-sm">
                        <option value="">Todos</option>
                        @foreach ($estados as $valor => $texto)
                            <option value="{{ $valor }}">{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fp-campo">
                    <label for="index-demandaCampanaId" class="mb-1 block text-xs font-medium text-slate-500">Temporada <span class="font-normal">(demanda)</span></label>
                    <select id="index-demandaCampanaId" wire:model.live="demandaCampanaId" class="w-full rounded-lg text-sm">
                        <option value="">Todas</option>
                        @foreach ($this->temporadasDemanda as $temporada)
                            <option value="{{ $temporada->id }}">{{ $temporada->nombre }} · {{ $temporada->linea?->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fp-campo">
                    <label for="index-demandaMarcaId" class="mb-1 block text-xs font-medium text-slate-500">Marca <span class="font-normal">(demanda)</span></label>
                    <select id="index-demandaMarcaId" wire:model.live="demandaMarcaId" class="w-full rounded-lg text-sm">
                        <option value="">Todas</option>
                        @foreach ($this->marcasDemanda as $marca)
                            <option value="{{ $marca->id }}">{{ $marca->nombre }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </section>

    {{-- KPIs animados. --}}
    <div class="fp-escalonado mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-6" data-kpis-reporte>
        <x-panel.metrica titulo="Saldo" :valor="$dinero($cuenta['saldo'])" :detalle="'Abonos '.$dinero($cuenta['abonos']).' · Cargos '.$dinero($cuenta['cargos'])"
            tono="primario" icono="M12 8c-2 0-3 .9-3 2s1 2 3 2 3 .9 3 2-1 2-3 2m0-8V6m0 10v2M4 12a8 8 0 1016 0 8 8 0 00-16 0z" />
        <x-panel.metrica titulo="Pedidos" :valor="$resumen['pedidos']['total']" :detalle="$dinero($resumen['pedidos']['monto_total'])"
            tono="neutral" icono="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
        <x-panel.metrica titulo="Ventas directas" :valor="$resumen['ventas_directas']['total']" :detalle="$dinero($resumen['ventas_directas']['monto_total'])"
            tono="exito" icono="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
        <x-panel.metrica titulo="Por cobrar" :valor="$dinero($porCobrar)" :detalle="($pendientes['clientes_directos']['totales']['clientes'] + $pendientes['revendedores']['totales']['clientes']).' clientes con saldo'"
            :tono="$porCobrar > 0 ? 'aviso' : 'exito'" icono="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-panel.metrica titulo="Vales activos" :valor="$resumen['vales']['activos']" :detalle="'Saldo '.$dinero($resumen['vales']['saldo_activo'])"
            tono="neutral" icono="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
        <x-panel.metrica titulo="Piezas pedidas" :valor="$demanda['totales']['piezas']" :detalle="$demanda['totales']['pedidos'].' pedidos · '.$dinero($demanda['totales']['monto'])"
            tono="primario" icono="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
    </div>

    {{-- Secciones en pestañas (todas se renderizan; la pestaña solo decide cuál se ve). --}}
    <div x-data="{ pestana: (location.hash || '').replace('#seccion-', '') || 'resumen' }"
        x-init="$watch('pestana', v => history.replaceState(null, '', '#seccion-' + v))">
        <div class="no-imprimir fp-pestanas mb-4 flex flex-nowrap gap-1 overflow-x-auto rounded-2xl border border-slate-200/80 bg-white p-1.5 shadow-sm" role="tablist" aria-label="Secciones del reporte">
            @foreach ($pestanas as $clave => $texto)
                <button type="button" role="tab" id="tab-{{ $clave }}" aria-controls="seccion-{{ $clave }}"
                    :aria-selected="(pestana === '{{ $clave }}').toString()" @click="pestana = '{{ $clave }}'"
                    class="fp-pestana shrink-0 whitespace-nowrap rounded-lg px-3.5 py-2 text-sm font-medium transition"
                    :class="pestana === '{{ $clave }}' ? 'bg-fp-primary text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'">
                    {{ $texto }}
                </button>
            @endforeach
        </div>

        {{-- Estado de cuenta --}}
        <div id="seccion-resumen" role="tabpanel" aria-labelledby="tab-resumen" x-show="pestana === 'resumen'" class="fp-escalonado space-y-5">
            <x-panel.seccion titulo="Movimientos del periodo" nota="Abonos (cobros a clientes), cargos (reembolsos y suscripción) y saldo acumulado.">
        <div class="overflow-x-auto">
        <table class="fp-tabla min-w-full text-sm">
            <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                <tr>
                    <th class="px-4 py-2">Fecha</th>
                    <th class="px-4 py-2">Concepto</th>
                    <th class="px-4 py-2">Folio</th>
                    <th class="px-4 py-2 text-right">Cargo</th>
                    <th class="px-4 py-2 text-right">Abono</th>
                    <th class="px-4 py-2 text-right">Saldo</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($cuenta['movimientos'] as $movimiento)
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $movimiento['fecha'] }}</td>
                        <td class="px-4 py-3">{{ $movimiento['concepto'] }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $movimiento['folio'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $movimiento['cargo'] > 0 ? '$'.number_format($movimiento['cargo'], 2) : '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $movimiento['abono'] > 0 ? '$'.number_format($movimiento['abono'], 2) : '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium">${{ number_format($movimiento['saldo'], 2) }}</td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="6" mensaje="No hay pagos aplicados en este periodo." />
                @endforelse
            </tbody>
        </table>
        </div>
        <p class="px-4 py-3 text-xs text-slate-500">Los abonos son cobros a clientes. Los cargos son reembolsos y la suscripción de FootwearPoint. El saldo es lo cobrado menos lo pagado.</p>
            </x-panel.seccion>
        </div>

        {{-- Pedidos --}}
        <div id="seccion-pedidos" role="tabpanel" aria-labelledby="tab-pedidos" x-show="pestana === 'pedidos'" x-cloak class="fp-escalonado space-y-5">
            <div class="grid gap-5 lg:grid-cols-2">
                <x-panel.seccion titulo="Pedidos por estado">
                    <div class="p-4 sm:p-5"><x-ui.barras :items="$graficaEstados" /></div>
                    <div class="overflow-x-auto border-t border-slate-100">
        <table class="fp-tabla min-w-full text-sm">
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
                </x-panel.seccion>
                <x-panel.seccion titulo="Pedidos por ciclo">
                    <div class="p-4 sm:p-5"><x-ui.barras :items="$graficaCiclos" tono="oscuro" /></div>
                    <div class="overflow-x-auto border-t border-slate-100">
        <table class="fp-tabla min-w-full text-sm">
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
                </x-panel.seccion>
            </div>
            <x-panel.seccion id="movimientos" titulo="Detalle de pedidos" nota="Máximo 100 del periodo.">
        <div class="overflow-x-auto">
            <table class="fp-tabla min-w-full text-sm">
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
            </x-panel.seccion>
        </div>

        {{-- Ventas de mostrador --}}
        <div id="seccion-ventas" role="tabpanel" aria-labelledby="tab-ventas" x-show="pestana === 'ventas'" x-cloak class="fp-escalonado space-y-5">
            <x-panel.seccion titulo="Detalle de ventas (venta local)" nota="Máximo 100 del periodo.">
        <div class="overflow-x-auto">
            <table class="fp-tabla min-w-full text-sm">
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
                                    class="block text-xs font-normal fp-enlace">Ver comprobante</a>
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
            </x-panel.seccion>
        </div>

        {{-- Por cobrar --}}
        <div id="seccion-cobrar" role="tabpanel" aria-labelledby="tab-cobrar" x-show="pestana === 'cobrar'" x-cloak class="fp-escalonado space-y-5">
            <div class="grid gap-5 lg:grid-cols-3">
                <x-panel.seccion titulo="Quién debe más" nota="Saldo pendiente al día de hoy." class="lg:col-span-1">
                    <div class="p-4 sm:p-5"><x-ui.barras :items="$deudores" tono="aviso" vacio="Nadie tiene saldo pendiente" /></div>
                </x-panel.seccion>
                <x-panel.seccion id="por-cobrar" titulo="Pedidos por cliente mayorista" class="lg:col-span-2"
                    nota="Pedidos colocados en el periodo (por fecha de colocación), sin borradores ni pedidos rechazados, descartados, no surtidos o vencidos. No usa el filtro de estado.">
        @if ($errorMayoristas)
            <x-panel.alerta tipo="error" class="m-4">{{ $errorMayoristas }}</x-panel.alerta>
        @endif
        <div class="overflow-x-auto">
            <table class="fp-tabla min-w-full text-sm">
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
                </x-panel.seccion>
            </div>
            <x-panel.seccion titulo="Montos pendientes"
                nota="Al día de hoy: pedidos colocados o entregados que todavía tienen saldo (sin borradores ni pedidos rechazados, descartados, no surtidos o vencidos). Solo usa el filtro de ciclo.">
        @if ($errorPendientes)
            <x-panel.alerta tipo="error" class="m-4">{{ $errorPendientes }}</x-panel.alerta>
        @endif
        <div class="overflow-x-auto border-t border-slate-100">
            <p class="px-4 pt-3 text-sm font-medium text-slate-700">Clientes</p>
            <table class="fp-tabla min-w-full text-sm">
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
            <table class="fp-tabla min-w-full text-sm">
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
            </x-panel.seccion>
            <x-panel.seccion titulo="Vales vigentes" :nota="$pendientes['vales_vigentes']['totales']['vales'].' vales · saldo disponible '.$dinero($pendientes['vales_vigentes']['totales']['saldo'])">
        <div class="overflow-x-auto">
            <table class="fp-tabla min-w-full text-sm">
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
            </x-panel.seccion>
        </div>

        {{-- Demanda --}}
        <div id="seccion-demanda" role="tabpanel" aria-labelledby="tab-demanda" x-show="pestana === 'demanda'" x-cloak class="fp-escalonado space-y-5">
            <x-panel.seccion id="demanda" titulo="Demanda por temporada y marca"
                nota="Piezas pedidas en pedidos colocados (por fecha de colocación), incluidas las no surtidas; sin borradores ni pedidos rechazados, descartados o vencidos. No incluye ventas de mostrador.">
                <x-slot:acciones>
                    <span class="text-sm text-slate-600">Total: <span class="font-semibold">{{ $demanda['totales']['piezas'] }} piezas</span> en {{ $demanda['totales']['pedidos'] }} pedidos · {{ $dinero($demanda['totales']['monto']) }}</span>
                </x-slot:acciones>
                <div class="p-4 sm:p-5"><x-ui.barras :items="$graficaMarcas" vacio="Sin demanda en el periodo" /></div>
        @if ($errorDemanda)
            <x-panel.alerta tipo="error" class="m-4">{{ $errorDemanda }}</x-panel.alerta>
        @endif
        <div class="overflow-x-auto border-t border-slate-100">
            <p class="px-4 pt-3 text-sm font-medium text-slate-700">Marcas</p>
            <table class="fp-tabla min-w-full text-sm">
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
            <table class="fp-tabla min-w-full text-sm">
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
            <table class="fp-tabla min-w-full text-sm">
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
            </x-panel.seccion>
        </div>
    </div>
</div>
