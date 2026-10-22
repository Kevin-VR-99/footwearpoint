<?php

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

    /** Recordatorio de cobro: deudores marcados ("tipo:id") y los que van en la vista previa. */
    public array $seleccionDeudores = [];
    public array $porEnviar = [];

    /** Filtros favoritos: nombre para guardar y el que se está renombrando. */
    public string $nombreFavorito = '';
    public ?int $renombrandoFavoritoId = null;
    public string $nombreRenombrar = '';

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


    /** Campos de filtro que se guardan en un favorito. */
    private const CAMPOS_FAVORITO = ['desde', 'hasta', 'estado', 'cicloId', 'demandaCampanaId', 'demandaMarcaId'];

    private const MAX_FAVORITOS = 20;

    private function filtrosActuales(): array
    {
        return collect(self::CAMPOS_FAVORITO)->mapWithKeys(fn ($c) => [$c => (string) $this->{$c}])->all();
    }

    private function favorito(int $id): \App\Models\FiltroFavorito
    {
        return \App\Models\FiltroFavorito::query()->delUsuario((int) Auth::id())->findOrFail($id);
    }

    public function getFavoritosProperty()
    {
        return \App\Models\FiltroFavorito::query()->delUsuario((int) Auth::id())->orderBy('nombre')->get();
    }

    public function guardarFavorito(): void
    {
        $this->nombreFavorito = trim($this->nombreFavorito);
        $this->validate(['nombreFavorito' => ['required', 'string', 'max:60']], [], ['nombreFavorito' => 'nombre']);

        $existentes = \App\Models\FiltroFavorito::query()->delUsuario((int) Auth::id());
        if ((clone $existentes)->where('nombre', $this->nombreFavorito)->exists()) {
            $this->addError('nombreFavorito', 'Ya tienes un favorito con ese nombre.');

            return;
        }
        if ((clone $existentes)->count() >= self::MAX_FAVORITOS) {
            $this->addError('nombreFavorito', 'Puedes guardar hasta '.self::MAX_FAVORITOS.' favoritos.');

            return;
        }

        \App\Models\FiltroFavorito::create([
            'usuario_id' => Auth::id(), 'pantalla' => 'reportes',
            'nombre' => $this->nombreFavorito, 'filtros' => $this->filtrosActuales(),
        ]);
        $this->dispatch('guardado', mensaje: 'Filtro «'.$this->nombreFavorito.'» guardado.');
        $this->nombreFavorito = '';
    }

    public function aplicarFavorito(int $id): void
    {
        $filtros = $this->favorito($id)->filtros ?? [];
        foreach (self::CAMPOS_FAVORITO as $campo) {
            $this->{$campo} = isset($filtros[$campo]) && is_scalar($filtros[$campo]) ? (string) $filtros[$campo] : '';
        }
    }

    public function renombrarFavorito(int $id): void
    {
        $this->renombrandoFavoritoId = $this->favorito($id)->id;
        $this->nombreRenombrar = $this->favorito($id)->nombre;
        $this->resetErrorBag('nombreRenombrar');
    }

    public function cancelarRenombre(): void
    {
        $this->reset('renombrandoFavoritoId', 'nombreRenombrar');
    }

    public function guardarRenombre(): void
    {
        if ($this->renombrandoFavoritoId === null) {
            return;
        }
        $favorito = $this->favorito($this->renombrandoFavoritoId);
        $this->nombreRenombrar = trim($this->nombreRenombrar);
        $this->validate(['nombreRenombrar' => ['required', 'string', 'max:60']], [], ['nombreRenombrar' => 'nombre']);

        $repetido = \App\Models\FiltroFavorito::query()->delUsuario((int) Auth::id())
            ->where('nombre', $this->nombreRenombrar)->whereKeyNot($favorito->id)->exists();
        if ($repetido) {
            $this->addError('nombreRenombrar', 'Ya tienes un favorito con ese nombre.');

            return;
        }

        $favorito->update(['nombre' => $this->nombreRenombrar]);
        $this->cancelarRenombre();
    }

    public function eliminarFavorito(int $id): void
    {
        $favorito = $this->favorito($id);
        $nombre = $favorito->nombre;
        $favorito->delete();
        if ($this->renombrandoFavoritoId === $id) {
            $this->cancelarRenombre();
        }
        $this->dispatch('guardado', mensaje: 'Favorito «'.$nombre.'» eliminado.');
    }

    private function autorizarCobro(): void
    {
        abort_unless(app(PermisoModuloService::class)->puede(Auth::user(), PermisoModuloService::REPORTES_FINANCIEROS), 403, MensajeError::SIN_PERMISO);
    }

    /** @return array<string, array{disponible:bool,motivo:?string,email:?string}> */
    public function getEstadoRecordatoriosProperty(): array
    {
        $servicio = app(\App\Services\Cobranza\RecordatorioCobroService::class);
        $estado = [];
        foreach (['clientes_directos' => 'cliente_directo', 'revendedores' => 'revendedor'] as $seccion => $tipo) {
            foreach ($this->montosPendientes[$seccion]['filas'] as $fila) {
                $d = $servicio->datos($tipo, (int) $fila['id']);
                $estado[$tipo.':'.$fila['id']] = ['disponible' => $d['disponible'], 'motivo' => $d['motivo'], 'email' => $d['email']];
            }
        }

        return $estado;
    }

    /** Vista previa para un deudor. */
    public function previsualizarRecordatorio(string $clave): void
    {
        $this->autorizarCobro();
        $this->porEnviar = [$clave];
    }

    /** Vista previa del envío masivo a los marcados. */
    public function previsualizarSeleccion(): void
    {
        $this->autorizarCobro();
        $this->porEnviar = array_values(array_unique($this->seleccionDeudores));
    }

    public function cerrarVistaPrevia(): void
    {
        $this->porEnviar = [];
    }

    /** @return array<int, array> datos de cada deudor de la vista previa */
    public function getVistaPreviaProperty(): array
    {
        $servicio = app(\App\Services\Cobranza\RecordatorioCobroService::class);

        return collect($this->porEnviar)
            ->map(function (string $clave) use ($servicio) {
                [$tipo, $id] = array_pad(explode(':', $clave, 2), 2, null);
                if (! in_array($tipo, \App\Services\Cobranza\RecordatorioCobroService::TIPOS, true) || ! ctype_digit((string) $id)) {
                    return null;
                }
                try {
                    return $servicio->datos($tipo, (int) $id);
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return null; // De otra distribuidora o ya no existe.
                }
            })
            ->filter()
            ->values()
            ->all();
    }

    public function enviarRecordatorios(): void
    {
        $this->autorizarCobro();
        $servicio = app(\App\Services\Cobranza\RecordatorioCobroService::class);
        $enviados = 0;
        $omitidos = [];

        foreach ($this->vistaPrevia as $d) {
            try {
                $servicio->enviar($d['tipo'], $d['id']);
                $enviados++;
            } catch (\App\Exceptions\OperacionInvalidaException $e) {
                $omitidos[] = $e->getMessage();
            }
        }

        $this->porEnviar = [];
        $this->seleccionDeudores = [];

        if ($enviados > 0) {
            $this->dispatch('guardado', mensaje: $enviados === 1 ? 'Recordatorio en camino.' : "{$enviados} recordatorios en camino.");
        }
        if ($omitidos !== []) {
            $this->dispatch('error-negocio', mensaje: 'No se enviaron: '.implode(' ', $omitidos));
        }
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
        // Caché por distribuidora; se invalida al crear o cambiar un ciclo.
        return app(\App\Support\Cache\CatalogoCache::class)->ciclos();
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
    /**
     * Periodo anterior de la misma duración que el rango Desde–Hasta. Sin ambas
     * fechas no hay "misma duración" que comparar y las tarjetas muestran "—".
     *
     * @return array{desde: string, hasta: string}|null
     */
    public function getPeriodoAnteriorProperty(): ?array
    {
        if ($this->desde === '' || $this->hasta === '') {
            return null;
        }

        try {
            $desde = \Illuminate\Support\Carbon::parse($this->desde)->startOfDay();
            $hasta = \Illuminate\Support\Carbon::parse($this->hasta)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
        if ($hasta->lt($desde)) {
            return null;
        }

        $dias = (int) $desde->diffInDays($hasta) + 1;

        return [
            'desde' => $desde->copy()->subDays($dias)->toDateString(),
            'hasta' => $desde->copy()->subDay()->toDateString(),
        ];
    }

    /** Valores de las tarjetas en el periodo anterior (mismos filtros de estado y ciclo). */
    public function getComparacionProperty(): ?array
    {
        $periodo = $this->periodoAnterior;
        if ($periodo === null) {
            return null;
        }

        $estado = $this->estado !== '' ? $this->estado : null;
        $ciclo = $this->cicloId !== '' ? (int) $this->cicloId : null;

        try {
            $cuenta = app(EstadoDeCuentaAction::class)->ejecutar($periodo['desde'], $periodo['hasta'], $estado, $ciclo);
            $resumen = app(ResumenOperativoAction::class)->ejecutar($periodo['desde'], $periodo['hasta'], $estado, $ciclo);
            $demanda = app(DemandaPorTemporadaMarcaAction::class)->ejecutar(
                $this->demandaCampanaId !== '' ? (int) $this->demandaCampanaId : null,
                $this->demandaMarcaId !== '' ? (int) $this->demandaMarcaId : null,
                $periodo['desde'], $periodo['hasta'], $ciclo,
            );
        } catch (\Throwable) {
            return null;
        }

        return [
            'etiqueta' => 'vs '.\Illuminate\Support\Carbon::parse($periodo['desde'])->format('d/m').'–'.\Illuminate\Support\Carbon::parse($periodo['hasta'])->format('d/m'),
            'abonos' => (float) $cuenta['abonos'],
            'pedidos' => (float) $resumen['pedidos']['total'],
            'ventas' => (float) $resumen['ventas_directas']['total'],
            'piezas' => (float) $demanda['totales']['piezas'],
        ];
    }

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
        $comparacion = $this->comparacion;
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

    @if (session('exportacion_en_cola'))
        <div class="no-imprimir mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800" role="status" data-aviso-exportacion>
            {{ session('exportacion_en_cola') }} <a href="{{ route('notificaciones.index') }}" class="fp-enlace font-medium" wire:navigate>Ver notificaciones</a>
        </div>
    @endif

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
        {{-- Filtros favoritos del usuario: aplicar con un clic, renombrar, eliminar y guardar los actuales. --}}
        @php
            $actuales = collect(['desde', 'hasta', 'estado', 'cicloId', 'demandaCampanaId', 'demandaMarcaId'])->mapWithKeys(fn ($c) => [$c => (string) $this->{$c}])->all();
        @endphp
        <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 px-4 py-3" data-favoritos x-data="{ guardando: false }">
            <span class="inline-flex items-center gap-1 text-xs font-medium text-slate-500">
                <svg class="h-3.5 w-3.5 text-fp-primary" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.07 3.3a1 1 0 00.95.69h3.47c.97 0 1.37 1.24.59 1.81l-2.81 2.04a1 1 0 00-.36 1.12l1.07 3.3c.3.92-.75 1.69-1.54 1.12l-2.8-2.04a1 1 0 00-1.18 0l-2.8 2.04c-.8.57-1.84-.2-1.55-1.12l1.07-3.3a1 1 0 00-.36-1.12L2.97 8.73c-.78-.57-.38-1.81.59-1.81h3.47a1 1 0 00.95-.69z"/></svg>
                Favoritos
            </span>
            @forelse ($this->favoritos as $fav)
                @php
                    $activo = array_merge(array_fill_keys(array_keys($actuales), ''), array_map('strval', array_filter((array) $fav->filtros, 'is_scalar'))) == $actuales;
                @endphp
                @if ($renombrandoFavoritoId === $fav->id)
                    <form wire:submit="guardarRenombre" class="inline-flex items-center gap-1 fp-aparecer" data-renombrar-favorito="{{ $fav->id }}">
                        <label for="renombrar-favorito" class="sr-only">Nuevo nombre</label>
                        <input id="renombrar-favorito" type="text" wire:model="nombreRenombrar" maxlength="60" class="h-8 w-40 rounded-lg text-xs" x-init="$el.focus()" @keydown.escape="$wire.cancelarRenombre()" />
                        <button type="submit" class="fp-accion fp-accion-primaria text-xs">Guardar</button>
                        <button type="button" wire:click="cancelarRenombre" class="fp-accion fp-accion-neutra text-xs">Cancelar</button>
                        @error('nombreRenombrar') <span class="text-xs text-fp-danger">{{ $message }}</span> @enderror
                    </form>
                @else
                    <span class="group inline-flex items-center overflow-hidden rounded-full border text-xs transition-colors motion-reduce:transition-none {{ $activo ? 'border-fp-primary bg-fp-badge-info-bg text-fp-badge-info-fg' : 'border-slate-200 bg-white text-slate-700 hover:border-fp-primary/50' }}" data-favorito="{{ $fav->nombre }}">
                        <button type="button" wire:click="aplicarFavorito({{ $fav->id }})" class="px-3 py-1 font-medium" aria-pressed="{{ $activo ? 'true' : 'false' }}" title="Aplicar «{{ $fav->nombre }}»">{{ $fav->nombre }}</button>
                        <button type="button" wire:click="renombrarFavorito({{ $fav->id }})" class="px-1.5 py-1 text-slate-400 hover:text-fp-primary" aria-label="Renombrar {{ $fav->nombre }}">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.2 5.2l3.6 3.6M4 20l4.5-1 10.3-10.3a2.5 2.5 0 00-3.5-3.5L5 15.5z"/></svg>
                        </button>
                        <button type="button" wire:click="eliminarFavorito({{ $fav->id }})" wire:confirm="¿Eliminar el favorito «{{ $fav->nombre }}»?" class="py-1 pl-0.5 pr-2 text-slate-400 hover:text-fp-danger" aria-label="Eliminar {{ $fav->nombre }}">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
                        </button>
                    </span>
                @endif
            @empty
                <span class="text-xs text-fp-text-muted">Aún no guardas combinaciones de filtros.</span>
            @endforelse

            <div class="ml-auto flex items-center gap-2">
                <button type="button" x-show="!guardando" @click="guardando = true; $nextTick(() => $refs.nombreFav.focus())" class="fp-accion fp-accion-primaria text-xs" data-guardar-favorito>
                    + Guardar filtros actuales
                </button>
                <form x-show="guardando" x-cloak x-transition.opacity wire:submit="guardarFavorito" @guardado.window="guardando = false" class="flex items-center gap-1">
                    <label for="nombre-favorito" class="sr-only">Nombre del favorito</label>
                    <input id="nombre-favorito" x-ref="nombreFav" type="text" wire:model="nombreFavorito" maxlength="60" placeholder="Nombre, p. ej. «Mes actual»" class="h-8 w-48 rounded-lg text-xs" @keydown.escape="guardando = false" />
                    <button type="submit" class="fp-btn fp-btn-primario px-3 py-1 text-xs">Guardar</button>
                    <button type="button" @click="guardando = false" class="fp-accion fp-accion-neutra text-xs">Cancelar</button>
                </form>
            </div>
            @error('nombreFavorito') <p class="w-full text-right text-xs text-fp-danger" x-init="guardando = true">{{ $message }}</p> @enderror
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
            tono="primario" icono="M12 8c-2 0-3 .9-3 2s1 2 3 2 3 .9 3 2-1 2-3 2m0-8V6m0 10v2M4 12a8 8 0 1016 0 8 8 0 00-16 0z" >
            <x-ui.variacion data-kpi="abonos" :actual="$cuenta['abonos']" :anterior="$comparacion['abonos'] ?? null" :etiqueta="($comparacion['etiqueta'] ?? 'vs periodo anterior').' (abonos)'" />
        </x-panel.metrica>
        <x-panel.metrica titulo="Pedidos" :valor="$resumen['pedidos']['total']" :detalle="$dinero($resumen['pedidos']['monto_total'])"
            tono="neutral" icono="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" >
            <x-ui.variacion data-kpi="pedidos" :actual="$resumen['pedidos']['total']" :anterior="$comparacion['pedidos'] ?? null" :etiqueta="$comparacion['etiqueta'] ?? 'vs periodo anterior'" />
        </x-panel.metrica>
        <x-panel.metrica titulo="Ventas directas" :valor="$resumen['ventas_directas']['total']" :detalle="$dinero($resumen['ventas_directas']['monto_total'])"
            tono="exito" icono="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" >
            <x-ui.variacion data-kpi="ventas" :actual="$resumen['ventas_directas']['total']" :anterior="$comparacion['ventas'] ?? null" :etiqueta="$comparacion['etiqueta'] ?? 'vs periodo anterior'" />
        </x-panel.metrica>
        <x-panel.metrica titulo="Por cobrar" :valor="$dinero($porCobrar)" :detalle="($pendientes['clientes_directos']['totales']['clientes'] + $pendientes['revendedores']['totales']['clientes']).' clientes con saldo'"
            :tono="$porCobrar > 0 ? 'aviso' : 'exito'" icono="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" >
            <x-ui.variacion :actual="0" etiqueta="corte al día de hoy" />
        </x-panel.metrica>
        <x-panel.metrica titulo="Vales activos" :valor="$resumen['vales']['activos']" :detalle="'Saldo '.$dinero($resumen['vales']['saldo_activo'])"
            tono="neutral" icono="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" >
            <x-ui.variacion :actual="0" etiqueta="corte al día de hoy" />
        </x-panel.metrica>
        <x-panel.metrica titulo="Piezas pedidas" :valor="$demanda['totales']['piezas']" :detalle="$demanda['totales']['pedidos'].' pedidos · '.$dinero($demanda['totales']['monto'])"
            tono="primario" icono="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" >
            <x-ui.variacion data-kpi="piezas" :actual="$demanda['totales']['piezas']" :anterior="$comparacion['piezas'] ?? null" :etiqueta="$comparacion['etiqueta'] ?? 'vs periodo anterior'" />
        </x-panel.metrica>
    </div>
    @if ($comparacion === null)
        <p class="mb-4 text-[11px] text-fp-text-muted" data-sin-comparacion>Elige fecha Desde y Hasta para comparar contra el periodo anterior de la misma duración.</p>
    @endif

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
                <x-slot:acciones>
                    <button type="button" wire:click="previsualizarSeleccion" class="fp-btn fp-btn-primario fp-btn-sm" data-envio-masivo
                        @disabled($seleccionDeudores === [])>
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                        Recordar a seleccionados ({{ count($seleccionDeudores) }})
                    </button>
                </x-slot:acciones>
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
                        <th class="no-imprimir px-4 py-2 text-right">Recordatorio</th>
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
                            @php
                                $claveDeudor = 'cliente_directo:'.$fila['id'];
                                $estadoDeudor = $this->estadoRecordatorios[$claveDeudor] ?? ['disponible' => false, 'motivo' => null];
                            @endphp
                            <td class="no-imprimir px-4 py-3 text-right whitespace-nowrap" data-recordatorio="{{ $claveDeudor }}">
                                <div class="inline-flex items-center gap-2">
                                    <label class="inline-flex items-center" title="{{ $estadoDeudor['motivo'] ?? 'Incluir en el envío masivo' }}">
                                        <span class="sr-only">Seleccionar a {{ $fila['nombre'] }}</span>
                                        <input type="checkbox" wire:model.live="seleccionDeudores" value="{{ $claveDeudor }}" class="rounded text-fp-primary disabled:opacity-40" @disabled(! $estadoDeudor['disponible'])>
                                    </label>
                                    <button type="button" wire:click="previsualizarRecordatorio('{{ $claveDeudor }}')" class="fp-accion fp-accion-primaria disabled:cursor-not-allowed disabled:opacity-50"
                                        @disabled(! $estadoDeudor['disponible']) @if (! $estadoDeudor['disponible']) title="{{ $estadoDeudor['motivo'] }}" aria-describedby="motivo-{{ $loop->index }}-cd" @endif>
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                                        Recordar
                                    </button>
                                </div>
                                @if (! $estadoDeudor['disponible'] && $estadoDeudor['motivo'])
                                    <p id="motivo-{{ $loop->index }}-cd" class="mt-1 max-w-[14rem] whitespace-normal text-right text-[11px] text-slate-500">{{ $estadoDeudor['motivo'] }}</p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-panel.vacio colspan="7" mensaje="Ningún cliente tiene saldo pendiente" />
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
                            <td class="no-imprimir"></td>
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
                        <th class="no-imprimir px-4 py-2 text-right">Recordatorio</th>
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
                            @php
                                $claveDeudor = 'revendedor:'.$fila['id'];
                                $estadoDeudor = $this->estadoRecordatorios[$claveDeudor] ?? ['disponible' => false, 'motivo' => null];
                            @endphp
                            <td class="no-imprimir px-4 py-3 text-right whitespace-nowrap" data-recordatorio="{{ $claveDeudor }}">
                                <div class="inline-flex items-center gap-2">
                                    <label class="inline-flex items-center" title="{{ $estadoDeudor['motivo'] ?? 'Incluir en el envío masivo' }}">
                                        <span class="sr-only">Seleccionar a {{ $fila['nombre'] }}</span>
                                        <input type="checkbox" wire:model.live="seleccionDeudores" value="{{ $claveDeudor }}" class="rounded text-fp-primary disabled:opacity-40" @disabled(! $estadoDeudor['disponible'])>
                                    </label>
                                    <button type="button" wire:click="previsualizarRecordatorio('{{ $claveDeudor }}')" class="fp-accion fp-accion-primaria disabled:cursor-not-allowed disabled:opacity-50"
                                        @disabled(! $estadoDeudor['disponible']) @if (! $estadoDeudor['disponible']) title="{{ $estadoDeudor['motivo'] }}" aria-describedby="motivo-{{ $loop->index }}-rv" @endif>
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                                        Recordar
                                    </button>
                                </div>
                                @if (! $estadoDeudor['disponible'] && $estadoDeudor['motivo'])
                                    <p id="motivo-{{ $loop->index }}-rv" class="mt-1 max-w-[14rem] whitespace-normal text-right text-[11px] text-slate-500">{{ $estadoDeudor['motivo'] }}</p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-panel.vacio colspan="6" mensaje="Ningún cliente mayorista tiene saldo pendiente" />
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
                            <td class="no-imprimir"></td>
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
    {{-- Vista previa y confirmación del recordatorio de cobro. --}}
    @if ($porEnviar !== [])
        @php
            $previa = $this->vistaPrevia;
            $listos = array_values(array_filter($previa, fn ($d) => $d['disponible']));
        @endphp
        <x-panel.modal titulo="Recordatorio de cobro" :subtitulo="count($listos).' de '.count($previa).' destinatario(s) recibirán el correo.'"
            ancho="max-w-2xl" cerrar="cerrarVistaPrevia" formulario="enviarRecordatorios" data-vista-previa-recordatorio>
            <ul class="mb-4 space-y-1.5 text-sm">
                @foreach ($previa as $d)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-fp-page px-3 py-2">
                        <span class="font-medium text-fp-sidebar">{{ $d['nombre'] }} <span class="font-normal text-slate-500">{{ $d['email'] ?? 'sin correo' }}</span></span>
                        @if ($d['disponible'])
                            <x-panel.insignia variante="success" punto>${{ number_format($d['saldo'], 2) }}</x-panel.insignia>
                        @else
                            <x-panel.insignia variante="warning" punto>{{ $d['motivo'] }}</x-panel.insignia>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($listos !== [])
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Vista previa{{ count($listos) > 1 ? ' (del primero; cada quien recibe su saldo)' : '' }}</p>
                <div class="max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700" data-texto-recordatorio>
                    @include('emails.partials.recordatorio-cobro-cuerpo', ['d' => $listos[0]])
                </div>
            @endif
            <x-slot:pie>
                <button type="button" wire:click="cerrarVistaPrevia" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button type="submit" class="fp-btn fp-btn-primario" @disabled($listos === [])>Enviar {{ count($listos) === 1 ? 'recordatorio' : count($listos).' recordatorios' }}</button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
</div>
