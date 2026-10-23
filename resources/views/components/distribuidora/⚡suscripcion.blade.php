<?php

use App\Models\Distribuidora;
use App\Models\Pago;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\MercadoPago\TokenMercadoPagoPlataforma;
use App\Services\Suscripcion\CrearPagoLineaExtraMercadoPagoAction;
use App\Services\Suscripcion\CrearPagoSuscripcionMercadoPagoAction;
use App\Services\Suscripcion\VerificarPagoSuscripcionMercadoPagoAction;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Spatie\Permission\PermissionRegistrar;

/*
| TG-230 (G11) — Pestaña "Suscripción" de Configuración (solo
| admin_distribuidora): el plan, hasta cuándo está pagado, cuánto cuesta el
| siguiente mes y el botón para pagarlo con Mercado Pago (Checkout Pro, a la
| cuenta de FootwearPoint).
|
| Mercado Pago regresa aquí (?pestana=suscripcion&payment_id=...). Igual que
| la página de regreso de G7, la URL solo dice dónde preguntar: lo que decide
| es VerificarPagoSuscripcionMercadoPagoAction.
|
| Las propiedades públicas viajan al navegador: solo datos para mostrar.
*/
new class extends Component {
    public bool $configurado = false;

    public ?string $motivo = null;

    public ?array $suscripcion = null;

    public ?array $desglose = null;

    public ?string $pagadoHastaAlPagar = null;

    public ?array $pendiente = null;

    public ?array $pendienteLineaExtra = null;

    public bool $puedeLineaExtra = false;

    public ?string $motivoLineaExtra = null;

    public bool $confirmarLineaExtra = false;

    public float $precioLineaExtra = 0;

    public int $lineasExtraContratadas = 0;

    public array $historial = [];

    /** El resultado que se está mostrando es de una línea extra, no de la mensualidad. */
    private bool $resultadoDeLineaExtra = false;

    public string $exito = '';

    public string $error = '';

    public string $aviso = '';

    public function mount(): void
    {
        $this->confirmarRegreso();
        $this->cargar();
    }

    public function pagar(CrearPagoSuscripcionMercadoPagoAction $accion): void
    {
        $this->soloAdministrador();
        $this->limpiarMensajes();

        try {
            $resultado = $accion->ejecutar($this->distribuidoraId());
        } catch (\Throwable $e) {
            $this->error = MensajeError::paraUsuario($e, MercadoPagoException::SUSCRIPCION_NO_SE_PUDO_COBRAR);
            $this->cargar();

            return;
        }

        $this->redirect($resultado['init_point']);
    }

    public function pedirLineaExtra(): void
    {
        $this->soloAdministrador();
        $this->limpiarMensajes();
        $this->confirmarLineaExtra = $this->puedeLineaExtra;
    }

    public function cancelarLineaExtra(): void
    {
        $this->confirmarLineaExtra = false;
    }

    public function pagarLineaExtra(CrearPagoLineaExtraMercadoPagoAction $accion): void
    {
        $this->soloAdministrador();
        $this->limpiarMensajes();

        try {
            $resultado = $accion->ejecutar($this->distribuidoraId());
        } catch (\Throwable $e) {
            $this->confirmarLineaExtra = false;
            $this->error = MensajeError::paraUsuario($e, 'No se pudo cobrar la línea extra. Intenta de nuevo en unos minutos.');
            $this->cargar();

            return;
        }

        $this->redirect($resultado['init_point']);
    }

    public function verificar(VerificarPagoSuscripcionMercadoPagoAction $accion): void
    {
        $this->soloAdministrador();
        $this->limpiarMensajes();

        try {
            $this->mostrarResultado($accion->ejecutar($this->distribuidoraId()));
        } catch (\Throwable $e) {
            $this->error = MensajeError::paraUsuario($e, 'No se pudo verificar el pago con Mercado Pago. Intenta de nuevo en unos minutos.');
        }

        $this->cargar();
    }

    public function verificarLineaExtra(VerificarPagoSuscripcionMercadoPagoAction $accion): void
    {
        $this->soloAdministrador();
        $this->limpiarMensajes();
        $this->resultadoDeLineaExtra = true;

        try {
            $this->mostrarResultado($accion->ejecutar($this->distribuidoraId(), null, 'panel', true));
        } catch (\Throwable $e) {
            $this->error = MensajeError::paraUsuario($e, 'No se pudo verificar el pago con Mercado Pago. Intenta de nuevo en unos minutos.');
        }

        $this->cargar();
    }

    /** Al volver de Mercado Pago con payment_id y external_reference de esta distribuidora. */
    private function confirmarRegreso(): void
    {
        $status = request()->query('status', request()->query('collection_status'));
        $pagoMpId = request()->query('payment_id', request()->query('collection_id'));
        $referencia = request()->query('external_reference');

        if (! is_string($referencia) || preg_match('/^FWP-([0-9]{1,10})-([0-9]{1,12})$/', $referencia, $partes) !== 1
            || (int) $partes[1] !== $this->distribuidoraId()) {
            return;
        }

        $pago = Pago::query()
            ->whereKey((int) $partes[2])
            ->where('tipo', 'suscripcion')
            ->where('metodo', 'mercado_pago')
            ->whereNotNull('preferencia_externa')
            ->first();

        $this->resultadoDeLineaExtra = $pago?->referencia === Pago::REFERENCIA_LINEA_EXTRA;
        $que = $this->resultadoDeLineaExtra ? 'línea extra' : 'mensualidad';

        if (in_array($status, ['rejected', 'cancelled', 'null'], true)) {
            $this->aviso = "Mercado Pago no completó el pago de tu {$que}. Puedes intentarlo de nuevo.";

            return;
        }

        if (! is_string($pagoMpId) || preg_match('/^[0-9]{1,20}$/', $pagoMpId) !== 1) {
            return;
        }

        try {
            if ($pago === null) {
                return;
            }

            if ($pago->estado === 'aplicado') {
                if ($pago->referencia_externa === $pagoMpId) {
                    $this->mostrarResultado(VerificarPagoSuscripcionMercadoPagoAction::APLICADO);
                }

                return;
            }

            if ($pago->estado === 'pendiente') {
                $this->mostrarResultado(app(VerificarPagoSuscripcionMercadoPagoAction::class)->verificarPago($pago, $pagoMpId, 'retorno'));
            }
        } catch (\Throwable $e) {
            // Al regresar de pagar no se muestra un error: el aviso de
            // Mercado Pago o el botón "Ya pagué, verificar" lo confirman.
            Log::warning('Mercado Pago: el regreso a Suscripción no pudo confirmar el pago.', [
                'pago_id' => (int) $partes[2],
                'error'   => class_basename($e),
                'mensaje' => $e instanceof \App\Exceptions\MensajeParaUsuario ? $e->getMessage() : null,
            ]);
            $this->aviso = 'Mercado Pago está confirmando tu pago. Si en unos minutos no se refleja, usa "Ya pagué, verificar".';
        }
    }

    private function mostrarResultado(string $resultado): void
    {
        if ($this->resultadoDeLineaExtra) {
            match ($resultado) {
                VerificarPagoSuscripcionMercadoPagoAction::APLICADO => $this->exito = '¡Recibimos el pago de tu línea extra! Se agregó a tu suscripción. Tu periodo ya pagado no cambia.',
                VerificarPagoSuscripcionMercadoPagoAction::PENDIENTE => $this->aviso = 'Mercado Pago todavía no confirma el pago de tu línea extra. Vuelve a verificar en unos minutos.',
                VerificarPagoSuscripcionMercadoPagoAction::RECHAZADO => $this->error = 'Mercado Pago no aprobó el pago de tu línea extra. Puedes intentarlo de nuevo.',
                VerificarPagoSuscripcionMercadoPagoAction::VENCIDO => $this->aviso = 'El enlace de pago de la línea extra venció sin que Mercado Pago confirmara un pago. Genera uno nuevo.',
                default => $this->error = 'El pago de Mercado Pago no coincide con el cobro de tu línea extra. Avísale al equipo de FootwearPoint.',
            };

            return;
        }

        match ($resultado) {
            VerificarPagoSuscripcionMercadoPagoAction::APLICADO => $this->exito = '¡Recibimos el pago de tu mensualidad! Mercado Pago lo confirmó y ya quedó registrado.',
            VerificarPagoSuscripcionMercadoPagoAction::PENDIENTE => $this->aviso = 'Mercado Pago todavía no confirma tu pago. Vuelve a verificar en unos minutos.',
            VerificarPagoSuscripcionMercadoPagoAction::RECHAZADO => $this->error = 'Mercado Pago no aprobó el pago de tu mensualidad. Puedes intentarlo de nuevo.',
            VerificarPagoSuscripcionMercadoPagoAction::VENCIDO => $this->aviso = 'El enlace de pago venció sin que Mercado Pago confirmara un pago. Genera uno nuevo.',
            default => $this->error = 'El pago de Mercado Pago no coincide con tu mensualidad. Avísale al equipo de FootwearPoint.',
        };
    }

    private function cargar(): void
    {
        $this->configurado = app(TokenMercadoPagoPlataforma::class)->configurado();

        $distribuidora = Distribuidora::query()->findOrFail($this->distribuidoraId());
        $suscripcion = CrearPagoSuscripcionMercadoPagoAction::suscripcionPorPagar();

        $this->motivo = CrearPagoSuscripcionMercadoPagoAction::motivoParaNoCobrar($distribuidora, $suscripcion);
        $this->suscripcion = null;
        $this->desglose = null;
        $this->pagadoHastaAlPagar = null;
        $this->puedeLineaExtra = false;
        $this->motivoLineaExtra = $suscripcion === null
            ? 'Para agregar una línea extra tu distribuidora necesita una suscripción activa.'
            : null;
        $this->precioLineaExtra = 0;
        $this->lineasExtraContratadas = 0;
        $this->confirmarLineaExtra = false;

        if ($suscripcion !== null) {
            $vencida = $suscripcion->estado === 'vencida'
                || ($suscripcion->fecha_fin !== null && $suscripcion->fecha_fin->copy()->startOfDay()->lt(today()));

            $this->suscripcion = [
                'plan'    => $suscripcion->plan?->nombre ?? 'Plan',
                'desde'   => $suscripcion->fecha_inicio?->format('d/m/Y'),
                'hasta'   => $suscripcion->fecha_fin?->format('d/m/Y'),
                'vencida' => $vencida,
                'lineas_incluidas' => (int) $suscripcion->lineas_incluidas_contratadas,
                'lineas_extra' => (int) $suscripcion->lineas_extra_contratadas,
            ];
            $this->precioLineaExtra = round((float) $suscripcion->precio_linea_extra_contratado, 2);
            $this->lineasExtraContratadas = (int) $suscripcion->lineas_extra_contratadas;
            $this->puedeLineaExtra = $this->configurado
                && $distribuidora->estado === 'activa'
                && $suscripcion->estado === 'activa'
                && ! $vencida
                && $this->precioLineaExtra > 0;
            $this->motivoLineaExtra = match (true) {
                ! $this->configurado => MercadoPagoException::SUSCRIPCION_NO_DISPONIBLE,
                $distribuidora->estado !== 'activa' => 'Para agregar una línea extra tu distribuidora necesita una suscripción activa.',
                $suscripcion->estado !== 'activa' || $vencida => 'Para agregar una línea extra tu suscripción tiene que estar activa.',
                $this->precioLineaExtra <= 0 => 'Tu plan no cobra las líneas extra.',
                default => null,
            };
            $this->desglose = CrearPagoSuscripcionMercadoPagoAction::monto($suscripcion);
            $this->pagadoHastaAlPagar = CrearPagoSuscripcionMercadoPagoAction::periodoAlPagar($suscripcion)['hasta']->format('d/m/Y');
        }

        $pagos = Pago::query()
            ->where('tipo', 'suscripcion')
            ->latest('id')
            ->limit(6)
            ->get();

        $pendiente = $pagos->first(fn (Pago $p) => $p->esMercadoPagoPendiente()
            && $p->preferencia_externa !== null
            && $p->referencia !== Pago::REFERENCIA_LINEA_EXTRA);
        $this->pendiente = $pendiente === null ? null : [
            'folio' => $pendiente->folio,
            'monto' => (float) $pendiente->monto,
        ];

        $pendienteExtra = $pagos->first(fn (Pago $p) => $p->esMercadoPagoPendiente()
            && $p->preferencia_externa !== null
            && $p->referencia === Pago::REFERENCIA_LINEA_EXTRA);
        $this->pendienteLineaExtra = $pendienteExtra === null ? null : [
            'folio' => $pendienteExtra->folio,
            'monto' => (float) $pendienteExtra->monto,
        ];

        $this->historial = $pagos
            ->filter(fn (Pago $p) => $p->estado === 'aplicado')
            ->map(fn (Pago $p) => [
                'folio' => $p->folio,
                'monto' => (float) $p->monto,
                'fecha' => ($p->fecha_pago ?? $p->created_at)?->format('d/m/Y'),
                'concepto' => $p->referencia === Pago::REFERENCIA_LINEA_EXTRA ? 'Línea extra' : 'Mensualidad',
            ])
            ->values()
            ->all();
    }

    private function soloAdministrador(): void
    {
        // Las acciones de Livewire no pasan por 'tenant.team' ni por
        // 'role:admin_distribuidora' de la ruta: se revisa aquí.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->distribuidoraId());

        abort_unless(auth()->user()?->hasRole('admin_distribuidora'), 403);
    }

    private function distribuidoraId(): int
    {
        $id = Tenant::id();
        abort_if($id === null, 403, 'No se pudo determinar la distribuidora.');

        return $id;
    }

    private function limpiarMensajes(): void
    {
        $this->exito = '';
        $this->error = '';
        $this->aviso = '';
    }
};
?>

<div class="space-y-5">
    <div>
        <h2 class="text-lg font-semibold text-slate-900">Suscripción</h2>
        <p class="mt-1 max-w-2xl text-sm text-slate-500">
            Tu plan de FootwearPoint y el pago de tu mensualidad. Se paga con Mercado Pago, directo a FootwearPoint.
        </p>
    </div>

    @if ($exito !== '')
        <div class="fp-emerger rounded-lg border border-fp-badge-success-fg/20 bg-fp-badge-success-bg px-4 py-3 text-sm text-fp-badge-success-fg">
            {{ $exito }}
        </div>
    @endif

    @if ($aviso !== '')
        <div class="rounded-lg bg-fp-badge-warning-bg px-4 py-3 text-sm text-fp-badge-warning-fg">
            {{ $aviso }}
        </div>
    @endif

    @if ($error !== '')
        <div class="fp-emerger rounded-lg border border-fp-danger/20 bg-fp-danger-soft px-4 py-3 text-sm text-fp-badge-danger-fg">
            {{ $error }}
        </div>
    @endif

    @if ($suscripcion !== null)
        <div class="fp-tarjeta fp-tarjeta-interactiva fp-aparecer p-4">
            <div class="flex items-center gap-2">
                <span class="text-sm font-semibold text-slate-900">Plan {{ $suscripcion['plan'] }}</span>
                @if ($suscripcion['vencida'])
                    <span class="inline-flex rounded-full bg-fp-danger-soft px-2.5 py-0.5 text-[11px] font-medium text-fp-badge-danger-fg">Vencida</span>
                @else
                    <span class="inline-flex rounded-full bg-fp-badge-success-bg px-2.5 py-0.5 text-[11px] font-medium text-fp-badge-success-fg">Activa</span>
                @endif
            </div>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-slate-500">Pagada hasta el</dt>
                    <dd class="font-medium text-slate-900">{{ $suscripcion['hasta'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Líneas incluidas</dt>
                    <dd class="font-medium text-slate-900">{{ $suscripcion['lineas_incluidas'] }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Líneas extra contratadas</dt>
                    <dd class="font-medium text-slate-900">{{ $suscripcion['lineas_extra'] }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Al pagar, queda pagada hasta el</dt>
                    <dd class="font-medium text-slate-900">{{ $pagadoHastaAlPagar ?? '—' }}</dd>
                </div>
            </dl>

            @if ($desglose !== null)
                <div class="mt-4 border-t border-slate-100 pt-3 text-sm">
                    <div class="flex justify-between text-slate-600">
                        <span>Precio base del plan</span>
                        <span>${{ number_format($desglose['base'], 2) }}</span>
                    </div>
                    @if ($desglose['lineas_extra'] > 0)
                        <div class="flex justify-between text-slate-600">
                            <span>{{ $desglose['lineas_extra'] }} {{ $desglose['lineas_extra'] === 1 ? 'línea extra' : 'líneas extra' }} × ${{ number_format($desglose['precio_linea_extra'], 2) }}</span>
                            <span>${{ number_format($desglose['lineas_extra'] * $desglose['precio_linea_extra'], 2) }}</span>
                        </div>
                    @endif
                    <div class="mt-1 flex justify-between font-semibold text-slate-900">
                        <span>Mensualidad</span>
                        <span>${{ number_format($desglose['total'], 2) }} MXN</span>
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if ($motivo !== null)
        <div class="fp-desvanecer rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-600">
            {{ $motivo }}
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        @if ($configurado && $motivo === null)
            <button type="button" wire:click="pagar" wire:loading.attr="disabled"
                class="fp-btn fp-btn-primario">
                Pagar mensualidad con Mercado Pago
            </button>
        @else
            <button type="button" disabled
                title="{{ $configurado ? $motivo : MercadoPagoException::SUSCRIPCION_NO_DISPONIBLE }}"
                class="cursor-not-allowed rounded-lg bg-slate-200 px-4 py-2 text-sm font-medium text-slate-500">
                Pagar mensualidad con Mercado Pago
            </button>
        @endif

        @if ($pendiente !== null && $configurado)
            <button type="button" wire:click="verificar" wire:loading.attr="disabled"
                class="fp-btn fp-btn-secundario">
                Ya pagué, verificar
            </button>
        @endif
    </div>

    @if (! $configurado)
        <div class="rounded-lg bg-fp-badge-warning-bg px-4 py-3 text-sm text-fp-badge-warning-fg">
            {{ MercadoPagoException::SUSCRIPCION_NO_DISPONIBLE }}
        </div>
    @endif

    @if ($pendiente !== null)
        <p class="text-xs text-slate-500">
            Tienes un pago de ${{ number_format($pendiente['monto'], 2) }} (folio {{ $pendiente['folio'] }}) esperando la confirmación de Mercado Pago.
        </p>
    @endif

    <div class="fp-tarjeta fp-tarjeta-interactiva fp-aparecer p-4">
        <h3 class="text-sm font-semibold text-slate-900">Línea extra</h3>
        <p class="mt-1 max-w-2xl text-sm text-slate-500">
            Si necesitas una línea más, se cobran ${{ number_format($precioLineaExtra, 2) }} MXN ahora, solo por esa línea.
            En la siguiente mensualidad se suma al plan mientras siga activa por encima de las incluidas.
            Si cambias de plan, el precio y el conteo de líneas extra se recalculan.
        </p>
        <p class="mt-2 text-sm text-slate-600">Líneas extra contratadas: {{ $lineasExtraContratadas }}.</p>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            @if ($puedeLineaExtra)
                <button type="button" wire:click="pedirLineaExtra" wire:loading.attr="disabled"
                    class="rounded-lg border border-fp-primary px-4 py-2 text-sm font-medium text-fp-primary transition hover:bg-fp-primary/5">
                    Solicitar línea extra
                </button>
            @else
                <button type="button" disabled title="{{ $motivoLineaExtra }}"
                    class="cursor-not-allowed rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-400">
                    Solicitar línea extra
                </button>
            @endif

            @if ($pendienteLineaExtra !== null && $configurado)
                <button type="button" wire:click="verificarLineaExtra" wire:loading.attr="disabled"
                    class="fp-btn fp-btn-secundario">
                    Ya pagué la línea extra, verificar
                </button>
            @endif
        </div>

        @if ($motivoLineaExtra !== null)
            <p class="mt-2 text-xs text-slate-500">{{ $motivoLineaExtra }}</p>
        @endif

        @if ($pendienteLineaExtra !== null)
            <p class="mt-2 text-xs text-slate-500">
                Tienes un pago de línea extra de ${{ number_format($pendienteLineaExtra['monto'], 2) }} (folio {{ $pendienteLineaExtra['folio'] }}) esperando la confirmación de Mercado Pago.
            </p>
        @endif
    </div>

    @if ($confirmarLineaExtra)
        <div class="fp-desvanecer fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-[2px]" role="dialog" aria-modal="true" aria-labelledby="titulo-linea-extra" wire:click.self="cancelarLineaExtra">
            <div class="fp-emerger w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                <h2 id="titulo-linea-extra" class="text-lg font-semibold text-slate-900">Solicitar línea extra</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Vas a pagar <strong>${{ number_format($precioLineaExtra, 2) }} MXN</strong> ahora, solo por esta línea.
                    Tu periodo ya pagado no cambia.
                </p>
                <p class="mt-2 text-sm text-slate-600">
                    En la siguiente factura se cobra el plan con el que iniciaste más las líneas extra que sigan activas por encima de las incluidas.
                    Si cambias de plan, el precio final y el conteo de líneas extra se recalculan.
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="cancelarLineaExtra" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">Cancelar</button>
                    <button type="button" wire:click="pagarLineaExtra" wire:loading.attr="disabled" class="fp-btn fp-btn-primario">
                        Pagar ${{ number_format($precioLineaExtra, 2) }} y continuar
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($historial !== [])
        <div>
            <h3 class="text-sm font-semibold text-slate-900">Pagos recientes</h3>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                @foreach ($historial as $fila)
                    <li class="flex justify-between py-2 text-slate-600">
                        <span>{{ $fila['fecha'] }} · {{ $fila['concepto'] }} · {{ $fila['folio'] }}</span>
                        <span class="font-medium text-slate-900">${{ number_format($fila['monto'], 2) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="text-xs text-slate-500">
        El pago se hace en la página oficial de Mercado Pago. FootwearPoint no ve ni guarda los datos de tu tarjeta.
        La mensualidad incluye las líneas de tu plan. Cada línea extra se cobra hoy por separado y, si sigue activa por encima del plan, entra en la siguiente factura.
    </p>
</div>
