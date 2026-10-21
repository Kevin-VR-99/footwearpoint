<?php

use App\Models\ConfiguracionDistribuidora;
use App\Services\MercadoPago\DesvincularMercadoPagoAction;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\MercadoPago\VincularMercadoPagoService;
use App\Support\MensajeError;
use App\Support\Tenant;
use Livewire\Component;

/*
| TG-225 (G6) — Pestaña "Mercado Pago" de Configuración.
|
| Las propiedades públicas viajan al navegador: aquí solo hay datos para
| mostrar (ID de la cuenta, fechas). El token NUNCA se pone en una propiedad.
*/
new class extends Component {
    public bool $configurado = false;

    public bool $modoPrueba = true;

    public bool $conectado = false;

    public bool $ilegible = false;

    public bool $porVencer = false;

    public bool $vencido = false;

    public ?string $cuentaId = null;

    public ?string $conectadoEl = null;

    public ?string $venceEl = null;

    public string $exito = '';

    public string $error = '';

    public function mount(): void
    {
        $this->exito = (string) session('mercado_pago_exito', '');
        $this->error = (string) session('mercado_pago_error', '');

        $this->cargar();
    }

    public function desconectar(DesvincularMercadoPagoAction $accion): void
    {
        $this->exito = '';
        $this->error = '';

        try {
            $accion->ejecutar($this->configuracion());
        } catch (\Throwable $e) {
            $this->error = MensajeError::paraUsuario($e, 'No se pudo desconectar la cuenta de Mercado Pago. Intenta de nuevo.');

            return;
        }

        $this->cargar();
        $this->exito = 'Desconectamos tu cuenta de Mercado Pago de FootwearPoint.';
    }

    private function cargar(): void
    {
        $estado = app(VincularMercadoPagoService::class)->estado($this->configuracion());

        $this->configurado = $estado['configurado'];
        $this->modoPrueba = $estado['modo_prueba'];
        $this->conectado = $estado['conectado'];
        $this->ilegible = $estado['ilegible'];
        $this->porVencer = $estado['por_vencer'];
        $this->vencido = $estado['vencido'];
        $this->cuentaId = $estado['cuenta_id'];
        $this->conectadoEl = $estado['conectado_at']?->format('d/m/Y');
        $this->venceEl = $estado['vence_aprox']?->format('d/m/Y');
    }

    private function configuracion(): ConfiguracionDistribuidora
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        return ConfiguracionDistribuidora::query()->firstOrFail();
    }
};
?>

<div class="space-y-5">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-4">
            <span class="fp-aparecer flex h-14 w-20 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md motion-reduce:transform-none" data-logo-mercado-pago>
                <x-ui.logo-mercado-pago class="h-10 w-auto" />
            </span>
            <div>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold text-slate-900">Mercado Pago</h2>
                @if ($conectado && $vencido)
                    <x-panel.insignia variante="danger" punto data-estado-mp="vencida">Conexión vencida</x-panel.insignia>
                @elseif ($conectado)
                    <x-panel.insignia variante="success" viva data-estado-mp="conectada">Conectada</x-panel.insignia>
                @elseif ($ilegible)
                    <x-panel.insignia variante="warning" punto data-estado-mp="renovar">Por renovar</x-panel.insignia>
                @else
                    <x-panel.insignia variante="neutral" punto data-estado-mp="desconectada">Desconectada</x-panel.insignia>
                @endif
            </div>
            <p class="mt-1 max-w-2xl text-sm text-slate-500">
                Conecta tu propia cuenta de Mercado Pago para cobrar los anticipos y saldos de tus clientes.
                El dinero llega directo a tu cuenta; FootwearPoint no lo concentra.
            </p>
            </div>
        </div>
        @if ($modoPrueba)
            <span class="inline-flex items-center self-start rounded-full bg-fp-badge-warning-bg px-2.5 py-1 text-[11px] font-medium text-fp-badge-warning-fg">
                Modo de prueba (sandbox)
            </span>
        @endif
    </div>

    @if ($exito !== '')
        <div class="fp-emerger rounded-lg border border-fp-badge-success-fg/20 bg-fp-badge-success-bg px-4 py-3 text-sm text-fp-badge-success-fg">
            {{ $exito }}
        </div>
    @endif

    @if ($error !== '')
        <div class="fp-emerger rounded-lg border border-fp-danger/20 bg-fp-danger-soft px-4 py-3 text-sm text-fp-danger">
            {{ $error }}
        </div>
    @endif

    @if ($conectado)
        <div class="fp-tarjeta fp-tarjeta-interactiva fp-aparecer p-4">
            <div class="flex items-center gap-2">
                <span class="fp-insignia-viva inline-flex"><span class="fp-insignia-punto text-fp-badge-success-fg"></span></span>
                <span class="text-sm font-semibold text-slate-900">Cuenta conectada</span>
            </div>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-slate-500">ID de la cuenta</dt>
                    <dd class="font-medium text-slate-900">{{ $cuentaId }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Conectada el</dt>
                    <dd class="font-medium text-slate-900">{{ $conectadoEl }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">La conexión vence aprox. el</dt>
                    <dd class="font-medium text-slate-900">{{ $venceEl }}</dd>
                </div>
            </dl>
        </div>

        @if ($vencido)
            <div class="fp-emerger rounded-lg border border-fp-danger/20 bg-fp-danger-soft px-4 py-3 text-sm text-fp-danger">
                La conexión con Mercado Pago venció. Vuelve a conectar tu cuenta para seguir cobrando.
            </div>
        @elseif ($porVencer)
            <div class="rounded-lg bg-fp-badge-warning-bg px-4 py-3 text-sm text-fp-badge-warning-fg">
                Tu conexión con Mercado Pago vence pronto. Vuelve a conectar tu cuenta para seguir cobrando sin interrupciones.
            </div>
        @endif
    @elseif ($ilegible)
        <div class="rounded-lg bg-fp-badge-warning-bg px-4 py-3 text-sm text-fp-badge-warning-fg">
            Hay que renovar la conexión con Mercado Pago. Vuelve a conectar tu cuenta.
        </div>
    @else
        <div class="fp-desvanecer flex items-center gap-3 rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-600"><svg class="fp-vacio-icono h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
            Todavía no has conectado una cuenta de Mercado Pago.
        </div>
    @endif

    @if (! $configurado)
        <div class="rounded-lg bg-fp-badge-warning-bg px-4 py-3 text-sm text-fp-badge-warning-fg">
            {{ MercadoPagoException::NO_CONFIGURADO }}
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        @if ($configurado)
            <a href="{{ route('mercado-pago.conectar') }}"
                class="fp-btn fp-btn-primario inline-flex items-center gap-2" data-conectar-mp>
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
                {{ $conectado || $ilegible ? 'Volver a conectar' : 'Conectar con Mercado Pago' }}
            </a>
        @endif

        @if ($conectado || $ilegible)
            <button type="button" wire:click="desconectar"
                wire:confirm="¿Desconectar tu cuenta de Mercado Pago? Ya no podrás cobrar con Mercado Pago hasta que la vuelvas a conectar."
                wire:loading.attr="disabled"
                class="fp-btn fp-btn-peligro">
                Desconectar
            </button>
        @endif
    </div>

    <p class="text-xs text-slate-500">
        La conexión se hace en la página oficial de Mercado Pago. FootwearPoint no ve ni guarda tu contraseña,
        datos de tarjetas ni datos bancarios. Si desconectas, también puedes quitar el permiso desde la
        configuración de tu cuenta de Mercado Pago.
    </p>
</div>
