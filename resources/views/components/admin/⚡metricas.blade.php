<?php

use App\Models\Distribuidora;
use App\Services\Actividad\ConsolidarActividad;
use App\Services\Metricas\IndicadoresDeMetricas;
use App\Models\PlanSuscripcion;
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

    /** TG-394 (A34) — Los numeros, con los filtros ya aplicados. */
    public function getIndicadoresProperty(): array
    {
        return app(IndicadoresDeMetricas::class)->calcular(
            $this->rango['desde'],
            $this->rango['hasta'],
            $this->distribuidoraElegida?->id,
            $this->planElegido?->id,
        );
    }

    /** Que quiere decir cada numero. Se enseña en pantalla, lo pide A34. */
    public function getDefinicionesProperty(): array
    {
        return IndicadoresDeMetricas::definiciones();
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

<div class="space-y-6">
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

    @php($n = $this->indicadores)
    @php($d = $this->definiciones)

    {{-- TG-394 (A34). Cada tarjeta lleva su definicion debajo: sin eso, dos
         personas leen el mismo numero y entienden cosas distintas. --}}
    <div class="fp-escalonado grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" data-indicadores-metricas>
        <x-panel.tarjeta class="p-5" data-indicador="usuarios-activos">
            <p class="text-sm font-medium text-slate-700">Usuarios activos</p>
            <p class="mt-1 text-3xl font-bold text-fp-sidebar tabular-nums">{{ number_format($n['usuarios_activos']) }}</p>
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
            <p class="mt-1 text-3xl font-bold text-fp-sidebar tabular-nums">${{ number_format($n['ingresos_suscripcion'], 2) }}</p>
            <p class="mt-2 text-xs text-fp-text-muted">{{ $d['ingresos_suscripcion'] }}</p>
        </x-panel.tarjeta>

        <x-panel.tarjeta class="p-5" data-indicador="ventas-tienda">
            <p class="text-sm font-medium text-slate-700">Ventas de las tiendas</p>
            <p class="mt-1 text-3xl font-bold text-fp-sidebar tabular-nums">${{ number_format($n['ventas_tienda'], 2) }}</p>
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

    {{-- Lo dice la tarjeta y vale la pena que se lea: son dos bolsillos
         distintos y sumarlos daria un numero que no significa nada. --}}
    <x-panel.alerta tipo="info" data-dinero-separado>
        Los <strong>ingresos de suscripción</strong> son de FootwearPoint; las <strong>ventas de las tiendas</strong>
        son de cada distribuidora. Son dinero distinto y por eso nunca se suman.
    </x-panel.alerta>

    <x-panel.tarjeta class="p-8 text-center" data-metricas-pendientes>
        <p class="text-sm font-medium text-slate-700">Las gráficas llegan en la siguiente entrega.</p>
        <p class="mt-1 text-sm text-fp-text-muted">Los indicadores ya están; faltan las series y sus tablas.</p>
    </x-panel.tarjeta>
</div>
