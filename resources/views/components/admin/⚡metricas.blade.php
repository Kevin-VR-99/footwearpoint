<?php

use App\Models\Distribuidora;
use App\Services\Actividad\ConsolidarActividad;
use App\Services\Metricas\CacheDeMetricas;
use App\Services\Metricas\IndicadoresDeMetricas;
use App\Services\Metricas\PendientesDeMetricas;
use App\Services\Metricas\SeriesDeMetricas;
use App\Models\PlanSuscripcion;
use App\Support\Dinero;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * TG-393 (A33) — Métricas del admin general.
 *
 * Esta tarjeta arma el apartado: la ruta, el menú, los filtros y los
 * permisos. Los números y las gráficas son A34 y A35; aquí lo que tiene que
 * quedar bien es el marco donde van a caber.
 *
 * PERMISOS, DOS VECES A PROPÓSITO. La ruta ya va dentro del grupo con
 * 'role:admin_general', pero el componente vuelve a comprobarlo en mount y en
 * cada cambio de filtro. Livewire no pasa por el middleware de la ruta en sus
 * peticiones siguientes: quien ya tenga la pantalla abierta y deje de ser
 * admin podría seguir pidiendo datos. Es la misma razón del autorizar() del
 * catálogo maestro.
 *
 * LOS FILTROS VAN EN LA DIRECCIÓN para que una vista se pueda compartir o
 * recargar sin perderla. Se guardan como texto y se validan al usarlos: una
 * dirección escrita a mano no debe tronar la pantalla, solo ignorarse.
 */
new #[Layout('layouts.admin')] #[Title('Métricas — FootwearPoint')] class extends Component
{
    /** Cuántos días atrás, como texto porque viene de la dirección. */
    #[Url(as: 'dias', except: '30')]
    public string $dias = '30';

    #[Url(as: 'distribuidora', except: '')]
    public string $distribuidora = '';

    #[Url(as: 'plan', except: '')]
    public string $plan = '';

    public string $errorMsg = '';

    /** TG-396 (A36): difiere las consultas al primer ciclo Livewire para enseñar skeleton. */
    public bool $cargando = true;

    /** Los periodos que se ofrecen, en días. */
    public const PERIODOS = [
        '7' => 'Últimos 7 días',
        '30' => 'Últimos 30 días',
        '90' => 'Últimos 90 días',
        '365' => 'Último año',
    ];

    public function mount(): void
    {
        $this->autorizar();
    }

    public function cargar(): void
    {
        $this->autorizar();
        $this->cargando = false;
    }

    public function updatedDias(): void
    {
        $this->autorizar();
    }

    public function updatedDistribuidora(): void
    {
        $this->autorizar();
    }

    public function updatedPlan(): void
    {
        $this->autorizar();
    }

    /**
     * Solo el admin general.
     *
     * Se llama en mount y en cada acción: las peticiones de Livewire no
     * vuelven a pasar por el middleware de la ruta.
     */
    private function autorizar(): void
    {
        abort_unless(Tenant::esAdminGeneral(), 403, MensajeError::SIN_PERMISO);
    }

    public function limpiarFiltros(): void
    {
        $this->autorizar();

        $this->dias = '30';
        $this->distribuidora = '';
        $this->plan = '';
    }

    /** El periodo pedido, o 30 días si la dirección trae cualquier cosa. */
    public function getDiasElegidosProperty(): int
    {
        return array_key_exists($this->dias, self::PERIODOS) ? (int) $this->dias : 30;
    }

    /**
     * El rango de fechas, en la zona de México.
     *
     * Todo lo que se mide se reporta en hora de CDMX (es la misma regla del
     * registro de actividad, A43): si se usara UTC, lo que pasó a las 7 de la
     * noche aparecería al día siguiente.
     */
    public function getRangoProperty(): array
    {
        $fin = Carbon::now('America/Mexico_City')->endOfDay();

        return [
            'desde' => $fin->copy()->subDays($this->diasElegidos - 1)->startOfDay(),
            'hasta' => $fin,
        ];
    }

    /** La distribuidora del filtro, o null si no hay o no existe. */
    public function getDistribuidoraElegidaProperty(): ?Distribuidora
    {
        if (! ctype_digit($this->distribuidora)) {
            return null;
        }

        return Distribuidora::withoutGlobalScopes()->find((int) $this->distribuidora);
    }

    public function getPlanElegidoProperty(): ?PlanSuscripcion
    {
        if (! ctype_digit($this->plan)) {
            return null;
        }

        return PlanSuscripcion::find((int) $this->plan);
    }

    public function getHayFiltrosProperty(): bool
    {
        return $this->dias !== '30' || $this->distribuidora !== '' || $this->plan !== '';
    }

    /**
     * TG-406 (A43) — Por que avisar que el registro de actividad no esta al
     * dia, o null si todo bien.
     *
     * Va aqui porque un cron que dejo de correr no avisa solo: lo unico que
     * se nota desde fuera es que los numeros se quedan viejos, y eso se
     * descubre tarde y mal.
     */
    public function getAlertaActividadProperty(): ?string
    {
        return app(ConsolidarActividad::class)->motivoDeAlerta();
    }

    /**
     * TG-397 (A37) — Cuándo se calculó cada parte que se está enseñando.
     *
     * No es público a propósito: lo llena cacheado() en esta misma petición y
     * no viaja al navegador.
     *
     * @var array<string, string>
     */
    protected array $calculadas = [];

    /**
     * TG-397 (A37) — Lo calcula una vez y lo guarda unos minutos.
     *
     * La llave lleva el periodo y los filtros (CacheDeMetricas). Si $calcular
     * truena, la excepción sale tal cual: las series la atrapan y ofrecen
     * reintentar, y nada roto se queda guardado.
     */
    private function cacheado(string $que, \Closure $calcular): array
    {
        $resultado = app(CacheDeMetricas::class)->recordar(
            $que,
            $this->rango['desde'],
            $this->rango['hasta'],
            $this->distribuidoraElegida?->id,
            $this->planElegido?->id,
            $calcular,
        );

        $this->calculadas[$que] = $resultado['calculado_at'];

        return $resultado['datos'];
    }

    /** TG-394 (A34) — Los numeros, con los filtros ya aplicados. */
    public function getIndicadoresProperty(): array
    {
        return $this->cacheado('indicadores', fn () => app(IndicadoresDeMetricas::class)->calcular(
            $this->rango['desde'],
            $this->rango['hasta'],
            $this->distribuidoraElegida?->id,
            $this->planElegido?->id,
        ));
    }

    /** Que quiere decir cada numero. Se enseña en pantalla, lo pide A34. */
    public function getDefinicionesProperty(): array
    {
        return IndicadoresDeMetricas::definiciones();
    }

    /**
     * TG-395 (A35) — Las mismas cifras repartidas en el tiempo.
     *
     * Devuelve null si la consulta se cae, y la pantalla lo dice con un boton
     * para reintentar. Es lo que pide la tarjeta, y tiene razon de ser: las
     * series agrupan en la base de datos y una sola consulta en mal estado
     * dejaria la pantalla entera en blanco, incluidos los indicadores, que si
     * funcionan.
     */
    public function getSeriesProperty(): ?array
    {
        try {
            return $this->cacheado('series', fn () => app(SeriesDeMetricas::class)->calcular(
                $this->rango['desde'],
                $this->rango['hasta'],
                $this->distribuidoraElegida?->id,
                $this->planElegido?->id,
            ));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Volver a pedir las series.
     *
     * No hace falta limpiar nada: las propiedades calculadas se vuelven a
     * evaluar en cada peticion, asi que basta con que haya una.
     */
    public function reintentarSeries(): void
    {
        $this->autorizar();
    }

    /**
     * TG-397 (A37) — Recalcular sin esperar a que caduque lo guardado.
     *
     * Solo borra lo de la vista que se está viendo (mismo periodo y filtros);
     * el render que sigue lo vuelve a calcular.
     */
    public function actualizar(): void
    {
        $this->autorizar();

        app(CacheDeMetricas::class)->olvidar(
            ['indicadores', 'series'],
            $this->rango['desde'],
            $this->rango['hasta'],
            $this->distribuidoraElegida?->id,
            $this->planElegido?->id,
        );
    }

    /**
     * TG-397 (A37) — La hora (CDMX) del cálculo más viejo que se enseña.
     *
     * El más viejo y no el más nuevo: si los indicadores vienen de hace
     * cuatro minutos y las series de ahora, lo honesto es decir «hace cuatro
     * minutos». Se pide al final de la plantilla, cuando ya se calculó todo.
     */
    public function getUltimoCalculoProperty(): ?string
    {
        if ($this->calculadas === []) {
            return null;
        }

        return Carbon::parse(min($this->calculadas))
            ->setTimezone('America/Mexico_City')
            ->format('H:i');
    }

    /** TG-397 (A37) — Lo que todavía no se mide (fase 2), tal cual se enseña. */
    public function getPendientesProperty(): array
    {
        return PendientesDeMetricas::lista();
    }

    /**
     * Pesos con la moneda escrita, que es lo que pide la tarjeta.
     *
     * Atajo para la plantilla, que no hereda los use de aqui arriba. Lo que
     * escribe el importe es Dinero, el mismo que usan las graficas.
     */
    public function pesos(float $monto): string
    {
        return Dinero::mxn($monto);
    }

    public function getDistribuidorasProperty()
    {
        return Distribuidora::withoutGlobalScopes()
            ->orderBy('nombre_comercial')
            ->get(['id', 'nombre_comercial']);
    }

    public function getPlanesProperty()
    {
        return PlanSuscripcion::orderBy('precio_base_mensual')->get(['id', 'nombre']);
    }
}; ?>

<div class="space-y-6" wire:init="cargar">
    <x-panel.encabezado eyebrow="Admin general" titulo="Métricas" nivel="h1"
        subtitulo="Actividad y uso de la plataforma. Los datos se cuentan en hora de Ciudad de México." />

    @if ($errorMsg)
        <x-panel.alerta tipo="peligro">{{ $errorMsg }}</x-panel.alerta>
    @endif

    @if ($this->alertaActividad)
        <x-panel.alerta tipo="aviso" data-alerta-actividad>
            <strong>El registro de actividad no está al día.</strong>
            {{ $this->alertaActividad }}
        </x-panel.alerta>
    @endif

    <x-panel.tarjeta class="p-4 sm:p-5" data-filtros-metricas>
        <div class="flex flex-wrap items-end gap-3">
            <x-ui.campo etiqueta="Periodo" para="metricas-dias" class="w-full sm:w-52">
                <x-ui.selector id="metricas-dias" wire:model.live="dias">
                    @foreach ($this::PERIODOS as $valor => $texto)
                        <option value="{{ $valor }}">{{ $texto }}</option>
                    @endforeach
                </x-ui.selector>
            </x-ui.campo>

            <x-ui.campo etiqueta="Distribuidora" para="metricas-distribuidora" class="w-full sm:w-64">
                <x-ui.selector id="metricas-distribuidora" wire:model.live="distribuidora" vacio="Todas">
                    @foreach ($this->distribuidoras as $d)
                        <option value="{{ $d->id }}">{{ $d->nombre_comercial }}</option>
                    @endforeach
                </x-ui.selector>
            </x-ui.campo>

            <x-ui.campo etiqueta="Plan" para="metricas-plan" class="w-full sm:w-52">
                <x-ui.selector id="metricas-plan" wire:model.live="plan" vacio="Todos">
                    @foreach ($this->planes as $p)
                        <option value="{{ $p->id }}">{{ $p->nombre }}</option>
                    @endforeach
                </x-ui.selector>
            </x-ui.campo>

            @if ($this->hayFiltros)
                <button type="button" wire:click="limpiarFiltros" class="fp-btn fp-btn-fantasma">
                    Limpiar filtros
                </button>
            @endif
        </div>

        {{-- locale('es') por llamada: APP_LOCALE esta en 'en' y sin esto el
             mes sale en ingles. Es lo que ya hace el dashboard; cambiar el
             locale global tocaria tambien los mensajes de validacion. --}}
        <p class="mt-3 text-xs text-fp-text-muted" data-rango-metricas>
            {{ $this->rango['desde']->locale('es')->translatedFormat('d \d\e F \d\e Y') }}
            —
            {{ $this->rango['hasta']->locale('es')->translatedFormat('d \d\e F \d\e Y') }}
            @if ($this->distribuidoraElegida)
                · {{ $this->distribuidoraElegida->nombre_comercial }}
            @endif
            @if ($this->planElegido)
                · Plan {{ $this->planElegido->nombre }}
            @endif
        </p>
    </x-panel.tarjeta>

    @php($d = $this->definiciones)

    @if ($cargando)
        <x-admin.metricas-esqueleto tipo="indicadores" />
    @else
        @php($n = $this->indicadores)

        <div wire:loading wire:target="dias,distribuidora,plan,limpiarFiltros" class="w-full">
            <x-admin.metricas-esqueleto tipo="indicadores" />
        </div>

        {{-- TG-394 (A34). Cada tarjeta lleva su definicion debajo: sin eso, dos
             personas leen el mismo numero y entienden cosas distintas. --}}
        <div wire:loading.remove wire:target="dias,distribuidora,plan,limpiarFiltros"
            class="fp-escalonado grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" data-indicadores-metricas>
            <x-panel.tarjeta class="p-5" data-indicador="usuarios-activos">
                <p class="text-sm font-medium text-slate-700">Usuarios activos</p>
                <p class="mt-1 whitespace-nowrap text-xl font-bold text-fp-sidebar tabular-nums sm:text-2xl 2xl:text-3xl">
                    <x-ui.cifra :valor="(float) $n['usuarios_activos']" formato="numero" :texto="number_format($n['usuarios_activos'])" />
                </p>
                <p class="mt-2 text-xs text-fp-text-muted">{{ $d['usuarios_activos'] }}</p>
                @if ($n['periodo_recortado'])
                    {{-- El detalle solo se guarda 90 dias (A43). Decirlo evita que
                         este numero se lea como si cubriera todo el periodo. --}}
                    <p class="mt-2 text-xs font-medium text-fp-badge-warning-fg" data-periodo-recortado>
                        Solo desde el {{ \Illuminate\Support\Carbon::parse($n['usuarios_desde'])->locale('es')->translatedFormat('d \d\e F') }}:
                        el detalle de actividad se guarda {{ \App\Models\ActividadDiaria::DIAS_QUE_SE_GUARDA }} días.
                    </p>
                @endif
            </x-panel.tarjeta>

            <x-panel.tarjeta class="p-5" data-indicador="ingresos-suscripcion">
                <p class="text-sm font-medium text-slate-700">Ingresos de suscripción</p>
                <p class="mt-1 whitespace-nowrap text-xl font-bold text-fp-sidebar tabular-nums sm:text-2xl 2xl:text-3xl">
                    <x-ui.cifra :valor="(float) $n['ingresos_suscripcion']" formato="dinero" :texto="$this->pesos($n['ingresos_suscripcion'])" />
                </p>
                <p class="mt-2 text-xs text-fp-text-muted">{{ $d['ingresos_suscripcion'] }}</p>
            </x-panel.tarjeta>

            <x-panel.tarjeta class="p-5" data-indicador="ventas-tienda">
                <p class="text-sm font-medium text-slate-700">Ventas de las tiendas</p>
                <p class="mt-1 whitespace-nowrap text-xl font-bold text-fp-sidebar tabular-nums sm:text-2xl 2xl:text-3xl">
                    <x-ui.cifra :valor="(float) $n['ventas_tienda']" formato="dinero" :texto="$this->pesos($n['ventas_tienda'])" />
                </p>
                <p class="mt-2 text-xs text-fp-text-muted">{{ $d['ventas_tienda'] }}</p>
            </x-panel.tarjeta>

            <x-panel.tarjeta class="p-5" data-indicador="plan-mas-contratado">
                <p class="text-sm font-medium text-slate-700">Plan más contratado</p>
                <p class="mt-1 text-2xl font-bold text-fp-sidebar">{{ $n['plan_mas_contratado'] ?: '—' }}</p>
                <p class="text-xs text-fp-text-muted">
                    {{ $n['plan_mas_contratado_cuantas'] > 0
                        ? $n['plan_mas_contratado_cuantas'].' '.\Illuminate\Support\Str::plural('distribuidora', $n['plan_mas_contratado_cuantas'])
                        : 'Sin suscripciones activas' }}
                </p>
                <p class="mt-2 text-xs text-fp-text-muted">{{ $d['plan_mas_contratado'] }}</p>
            </x-panel.tarjeta>
        </div>
    @endif

    {{-- Lo dice la tarjeta y vale la pena que se lea: son dos bolsillos
         distintos y sumarlos daria un numero que no significa nada. --}}
    <x-panel.alerta tipo="info" data-dinero-separado>
        Los <strong>ingresos de suscripción</strong> son de FootwearPoint; las <strong>ventas de las tiendas</strong>
        son de cada distribuidora. Son dinero distinto y por eso nunca se suman.
    </x-panel.alerta>

    {{-- TG-395 (A35). Las series usan EL MISMO rango y los MISMOS filtros que
         los indicadores de arriba, por eso cada total de aqui coincide con su
         tarjeta: las consultas salen del mismo sitio (ConsultasDeMetricas). --}}
    @if ($cargando)
        <x-admin.metricas-esqueleto tipo="series" />
    @else
        @php($s = $this->series)

        <div wire:loading wire:target="dias,distribuidora,plan,limpiarFiltros,reintentarSeries" class="w-full">
            <x-admin.metricas-esqueleto tipo="series" />
        </div>

        <div wire:loading.remove wire:target="dias,distribuidora,plan,limpiarFiltros,reintentarSeries" class="space-y-4">
            @if ($s === null)
                {{-- Estado de error con reintento, lo pide la tarjeta. Los indicadores
                     de arriba siguen en pie: solo se cayeron las series. --}}
                <x-panel.tarjeta class="p-6 text-center" data-series-error>
                    <p class="text-sm font-medium text-slate-700">No se pudieron armar las gráficas.</p>
                    <p class="mx-auto mt-1 max-w-prose text-sm text-fp-text-muted">
                        Los indicadores de arriba sí están. Si vuelve a pasar, avísale a soporte: quedó registrado.
                    </p>
                    <button type="button" wire:click="reintentarSeries" class="fp-btn fp-btn-primario mt-4">
                        Reintentar
                    </button>
                </x-panel.tarjeta>
            @else
                <div class="space-y-2">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-fp-text-muted">
                        Cómo se movió en el tiempo
                    </h2>
                    <p class="text-xs text-fp-text-muted" data-agrupacion-series>
                        Un tramo por {{ $s['agrupacion'] }}, {{ $s['tramos'] }} en total.
                        El último va en curso porque el periodo termina hoy.
                        Cada gráfica trae su tabla con los valores exactos.
                    </p>
                </div>

                <div class="fp-datos-cascada grid grid-cols-1 gap-4 xl:grid-cols-3" data-series-metricas>
                    {{-- La nota de los usuarios no es un detalle: sin ella, cualquiera
                         suma las barras, no le cuadra con el indicador de arriba y
                         piensa que la pantalla miente. --}}
                    <x-ui.serie titulo="Usuarios activos" columna="Usuarios" tono="primario"
                        :puntos="$s['series']['usuarios_activos']['puntos']"
                        :total="$s['series']['usuarios_activos']['total']"
                        :tendencia="$s['series']['usuarios_activos']['tendencia']"
                        :agrupacion="$s['agrupacion']"
                        :descripcion="$d['usuarios_activos']"
                        nota="Las barras no se suman: quien entró en dos tramos es una sola persona en el total."
                        vacio="Nadie entró al panel ni a la app en este periodo." />

                    <x-ui.serie titulo="Ingresos de suscripción" columna="Ingresos" tono="exito" formato="dinero"
                        :puntos="$s['series']['ingresos_suscripcion']['puntos']"
                        :total="$s['series']['ingresos_suscripcion']['total']"
                        :tendencia="$s['series']['ingresos_suscripcion']['tendencia']"
                        :agrupacion="$s['agrupacion']"
                        :descripcion="$d['ingresos_suscripcion']"
                        vacio="No se aplicó ningún pago de suscripción en este periodo." />

                    <x-ui.serie titulo="Ventas de las tiendas" columna="Ventas" tono="oscuro" formato="dinero"
                        :puntos="$s['series']['ventas_tienda']['puntos']"
                        :total="$s['series']['ventas_tienda']['total']"
                        :tendencia="$s['series']['ventas_tienda']['tendencia']"
                        :agrupacion="$s['agrupacion']"
                        :descripcion="$d['ventas_tienda']"
                        nota="El filtro de plan no aplica aquí: el plan es el contrato con FootwearPoint, no con los clientes de la tienda."
                        vacio="Las tiendas no registraron ventas en este periodo." />
                </div>
            @endif
        </div>
    @endif

    {{-- TG-397 (A37). Las cifras se guardan unos minutos para no repetir las
         consultas en cada visita; decirlo evita que un numero guardado se lea
         como si fuera de este instante. --}}
    @if (! $cargando && $this->ultimoCalculo)
        <div class="flex flex-wrap items-center gap-3 text-xs text-fp-text-muted" data-ultimo-calculo>
            <p>
                Cifras calculadas a las {{ $this->ultimoCalculo }} (hora de Ciudad de México).
                Se guardan {{ intdiv(\App\Services\Metricas\CacheDeMetricas::SEGUNDOS, 60) }} minutos para no repetir las consultas en cada visita.
            </p>
            <button type="button" wire:click="actualizar" wire:loading.attr="disabled" wire:target="actualizar"
                class="fp-btn fp-btn-fantasma" data-actualizar-metricas>
                <span wire:loading.remove wire:target="actualizar">Actualizar ahora</span>
                <span wire:loading wire:target="actualizar">Actualizando…</span>
            </button>
        </div>
    @endif

    {{-- TG-397 (A37). Lo que no se puede medir se dice, no se rellena. La
         misma lista vive en docs/metricas-fase2.md. Solo texto fijo: nada de
         llaves, tokens ni datos del servidor. Queda oculto mientras
         metricas_fase2_visible sea false. --}}
    @if (config('footwearpoint.metricas_fase2_visible'))
        <x-panel.tarjeta class="p-5" data-pendientes-metricas>
            <details>
                <summary class="cursor-pointer text-sm font-semibold text-slate-700">
                    Lo que todavía no se mide (fase 2)
                </summary>
                <p class="mt-2 max-w-prose text-xs text-fp-text-muted">
                    Estas métricas no aparecen arriba porque hoy no hay de dónde sacarlas sin inventar el número.
                </p>
                <ul class="mt-3 space-y-3 text-sm">
                    @foreach ($this->pendientes as $p)
                        <li data-pendiente="{{ $p['clave'] }}">
                            <p class="font-medium text-slate-700">{{ $p['titulo'] }}</p>
                            <p class="text-xs text-fp-text-muted">{{ $p['motivo'] }}</p>
                            <p class="text-xs text-fp-text-muted"><span class="font-medium">Qué haría falta:</span> {{ $p['falta'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </details>
        </x-panel.tarjeta>
    @endif
</div>
