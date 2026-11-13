<?php

use App\Models\Distribuidora;
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

    {{-- Los indicadores y las gráficas son A34 y A35. Este hueco no es un
         olvido: deja claro en pantalla que el apartado existe y que lo que
         falta son los números, no el apartado. --}}
    <x-panel.tarjeta class="p-8 text-center" data-metricas-pendientes>
        <p class="text-sm font-medium text-slate-700">Los indicadores llegan en la siguiente entrega.</p>
        <p class="mt-1 text-sm text-fp-text-muted">
            Este apartado ya tiene su acceso, sus filtros y sus permisos. Faltan las cifras y las gráficas.
        </p>
    </x-panel.tarjeta>
</div>
