<?php

use App\Models\Distribuidora;
use App\Models\Pago;
use App\Models\Suscripcion;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\MercadoPago\TokenMercadoPagoPlataforma;
use App\Services\Suscripcion\CrearPagoSuscripcionMercadoPagoAction;
use App\Services\Suscripcion\VerificarPagoSuscripcionMercadoPagoAction;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\PermissionRegistrar;

/*
| TG-220 — Cobro antes de acceder. Pantalla a la que CobroInicialPendiente
| manda al personal de una distribuidora aprobada que no ha pagado su primer
| mes: su plan, el monto y el botón para pagar con Mercado Pago (la misma
| CrearPagoSuscripcionMercadoPagoAction de Configuración > Suscripción).
|
| Mercado Pago regresa aquí; como en la pestaña Suscripción, la URL solo dice
| dónde preguntar: lo que decide es VerificarPagoSuscripcionMercadoPagoAction.
| Ya pagado, se manda al panel normal.
*/
new #[Layout('layouts.guest')] #[Title('Paga tu primer mes — FootwearPoint')] class extends Component {
    public bool $configurado = false;

    public bool $esAdministrador = false;

    public string $distribuidora = '';

    public ?array $plan = null;

    public ?array $desglose = null;

    public ?array $pendiente = null;

    public string $error = '';

    public string $aviso = '';

    public function mount()
    {
        if ($this->confirmarRegreso() || $this->yaPagado()) {
            session()->flash('status', '¡Recibimos tu pago! Tu suscripción ya está activa.');

            return $this->redirect(route('dashboard'));
        }

        $this->cargar();
    }

    public function pagar(CrearPagoSuscripcionMercadoPagoAction $accion)
    {
        $this->soloAdministrador();
        $this->limpiar();

        try {
            $resultado = $accion->ejecutar($this->distribuidoraId());
        } catch (\Throwable $e) {
            $this->error = MensajeError::paraUsuario($e, MercadoPagoException::SUSCRIPCION_NO_SE_PUDO_COBRAR);
            $this->cargar();

            return null;
        }

        return $this->redirect($resultado['init_point']);
    }

    public function verificar(VerificarPagoSuscripcionMercadoPagoAction $accion)
    {
        $this->soloAdministrador();
        $this->limpiar();

        try {
            $resultado = $accion->ejecutar($this->distribuidoraId());
        } catch (\Throwable $e) {
            $this->error = MensajeError::paraUsuario($e, 'No se pudo verificar el pago con Mercado Pago. Intenta de nuevo en unos minutos.');
            $this->cargar();

            return null;
        }

        if ($resultado === VerificarPagoSuscripcionMercadoPagoAction::APLICADO) {
            session()->flash('status', '¡Recibimos tu pago! Tu suscripción ya está activa.');

            return $this->redirect(route('dashboard'));
        }

        $this->mostrar($resultado);
        $this->cargar();

        return null;
    }

    /** True si al volver de Mercado Pago el pago quedó aplicado. */
    private function confirmarRegreso(): bool
    {
        $pagoMpId = request()->query('payment_id', request()->query('collection_id'));
        $status = request()->query('status', request()->query('collection_status'));
        $referencia = request()->query('external_reference');

        if (! is_string($referencia) || preg_match('/^FWP-([0-9]{1,10})-([0-9]{1,12})$/', $referencia, $partes) !== 1
            || (int) $partes[1] !== $this->distribuidoraId()) {
            return false;
        }

        if (in_array($status, ['rejected', 'cancelled', 'null'], true)) {
            $this->aviso = 'Mercado Pago no completó tu pago. Puedes intentarlo de nuevo.';

            return false;
        }

        if (! is_string($pagoMpId) || preg_match('/^[0-9]{1,20}$/', $pagoMpId) !== 1) {
            return false;
        }

        $pago = Pago::query()
            ->whereKey((int) $partes[2])
            ->where('tipo', 'suscripcion')
            ->where('metodo', 'mercado_pago')
            ->whereNotNull('preferencia_externa')
            ->first();

        if ($pago === null) {
            return false;
        }

        if ($pago->estado === 'aplicado') {
            return true;
        }

        try {
            $resultado = app(VerificarPagoSuscripcionMercadoPagoAction::class)->verificarPago($pago, $pagoMpId, 'retorno');
        } catch (\Throwable $e) {
            Log::warning('Mercado Pago: el regreso al pago inicial no pudo confirmar el pago.', [
                'pago_id' => (int) $partes[2],
                'error'   => class_basename($e),
            ]);
            $this->aviso = 'Mercado Pago está confirmando tu pago. Si en unos minutos no se refleja, usa "Ya pagué, verificar".';

            return false;
        }

        if ($resultado === VerificarPagoSuscripcionMercadoPagoAction::APLICADO) {
            return true;
        }

        $this->mostrar($resultado);

        return false;
    }

    private function mostrar(string $resultado): void
    {
        match ($resultado) {
            VerificarPagoSuscripcionMercadoPagoAction::PENDIENTE => $this->aviso = 'Mercado Pago todavía no confirma tu pago. Vuelve a verificar en unos minutos.',
            VerificarPagoSuscripcionMercadoPagoAction::RECHAZADO => $this->error = 'Mercado Pago no aprobó tu pago. Puedes intentarlo de nuevo.',
            VerificarPagoSuscripcionMercadoPagoAction::VENCIDO => $this->aviso = 'El enlace de pago venció sin que Mercado Pago confirmara un pago. Genera uno nuevo.',
            default => $this->error = 'El pago de Mercado Pago no coincide con tu primer mes. Avísale al equipo de FootwearPoint.',
        };
    }

    private function yaPagado(): bool
    {
        return Suscripcion::pendientePrimerPago($this->distribuidoraId()) === null;
    }

    private function cargar(): void
    {
        $id = $this->distribuidoraId();
        $this->configurado = app(TokenMercadoPagoPlataforma::class)->configurado();
        $this->distribuidora = (string) Distribuidora::query()->findOrFail($id)->nombre_comercial;

        app(PermissionRegistrar::class)->setPermissionsTeamId($id);
        $this->esAdministrador = (bool) auth()->user()?->hasRole('admin_distribuidora');

        $suscripcion = Suscripcion::pendientePrimerPago($id);

        $this->plan = $suscripcion === null ? null : [
            'nombre'             => $suscripcion->plan?->nombre ?? 'Plan',
            'lineas_incluidas'   => (int) $suscripcion->lineas_incluidas_contratadas,
            'precio_linea_extra' => round((float) $suscripcion->precio_linea_extra_contratado, 2),
        ];
        $this->desglose = $suscripcion === null ? null : CrearPagoSuscripcionMercadoPagoAction::monto($suscripcion);

        $pendiente = Pago::query()
            ->where('tipo', 'suscripcion')
            ->where('estado', 'pendiente')
            ->whereNotNull('preferencia_externa')
            ->where(fn ($q) => $q->whereNull('referencia')->orWhere('referencia', '!=', Pago::REFERENCIA_LINEA_EXTRA))
            ->latest('id')
            ->first();

        $this->pendiente = $pendiente === null ? null : ['folio' => $pendiente->folio, 'monto' => (float) $pendiente->monto];
    }

    private function soloAdministrador(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->distribuidoraId());

        abort_unless(auth()->user()?->hasRole('admin_distribuidora'), 403);
    }

    private function distribuidoraId(): int
    {
        $id = Tenant::id();
        abort_if($id === null, 403, 'No se pudo determinar la distribuidora.');

        return $id;
    }

    private function limpiar(): void
    {
        $this->error = '';
        $this->aviso = '';
    }
};
?>

@php($mxn = fn ($n) => '$'.number_format((float) $n, 2).' MXN')

<div class="bg-white rounded-xl shadow-lg border border-slate-200 p-8 space-y-5">
    <div class="text-center">
        <h1 class="text-2xl font-bold text-slate-900">Paga tu primer mes</h1>
        <p class="text-sm text-slate-500 mt-1">
            <strong>{{ $distribuidora }}</strong> ya fue aprobada. Para empezar a usar FootwearPoint, paga tu primer mes.
        </p>
    </div>

    @if ($error)
        <div class="rounded-lg bg-red-50 text-red-700 text-sm p-3">{{ $error }}</div>
    @endif
    @if ($aviso)
        <div class="rounded-lg bg-amber-50 text-amber-800 text-sm p-3">{{ $aviso }}</div>
    @endif

    @if ($plan && $desglose)
        <div class="rounded-lg border border-slate-200 p-4 text-sm space-y-1">
            <p class="text-base font-semibold text-slate-900">Plan {{ $plan['nombre'] }}</p>
            <p>Incluye {{ $plan['lineas_incluidas'] }} línea(s). Línea extra: {{ $mxn($plan['precio_linea_extra']) }} al mes.</p>
            <p>Precio mensual: {{ $mxn($desglose['base']) }}</p>
            @if ($desglose['lineas_extra'] > 0)
                <p>Líneas extra activas: {{ $desglose['lineas_extra'] }} × {{ $mxn($desglose['precio_linea_extra']) }}</p>
            @endif
            <p class="pt-2 text-lg font-bold text-slate-900">Total a pagar: {{ $mxn($desglose['total']) }}</p>
            <p class="text-xs text-slate-500">Tu mes empieza a contar el día en que se confirme el pago.</p>
        </div>
    @endif

    @if ($esAdministrador)
        @if (! $configurado)
            <p class="text-sm text-slate-600">{{ \App\Services\MercadoPago\MercadoPagoException::SUSCRIPCION_NO_DISPONIBLE }}</p>
        @else
            <button type="button" wire:click="pagar" wire:loading.attr="disabled"
                    class="w-full rounded-lg bg-[#009EE3] text-white font-medium py-2.5 hover:bg-[#0089C7] transition">
                <span wire:loading.remove wire:target="pagar">Pagar con Mercado Pago</span>
                <span wire:loading wire:target="pagar">Abriendo Mercado Pago...</span>
            </button>

            @if ($pendiente)
                <button type="button" wire:click="verificar" wire:loading.attr="disabled"
                        class="w-full rounded-lg border border-slate-300 text-slate-700 font-medium py-2.5 hover:bg-slate-50 transition">
                    Ya pagué, verificar
                </button>
            @endif
        @endif
    @else
        <p class="text-sm text-slate-600">
            El administrador de tu distribuidora debe pagar el primer mes. En cuanto se confirme el pago podrás entrar al panel.
        </p>
    @endif

    <form method="POST" action="{{ route('logout') }}" class="text-center">
        @csrf
        <button type="submit" class="text-sm text-blue-700 hover:underline">Cerrar sesión</button>
    </form>
</div>
