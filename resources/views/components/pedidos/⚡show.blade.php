<?php

use App\Models\Pedido;
use App\Services\Catalogo\ImagenDeProducto;
use App\Services\Cambio\PlazoCambio;
use App\Services\Cambio\SolicitarCambioAction;
use App\Services\Pedido\EntregaPedidoAction;
use App\Services\Pedido\EnviarPedidoAction;
use App\Services\Pedido\MarcarNoSurtidoAction;
use App\Services\Pedido\ResolverNoSurtidoAction;
use App\Services\Pago\VerificarPagoMercadoPagoAction;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Detalle pedido — FootwearPoint')] class extends Component {
    public int $pedidoId;
    public string $mensaje = '';
    public string $errorMsg = '';

    public string $pagoTipo = 'saldo_pedido';
    public string $pagoMetodo = 'efectivo';
    public string $pagoMonto = '';
    public string $pagoReferencia = '';

    // TG-226 (bugfix): número de pago de Mercado Pago (opcional) para verificar.
    public string $pagoMpId = '';

    // TG-254: solicitud de cambio de una línea (el personal la pide por el cliente).
    public ?int $cambioLineaId = null;
    public string $cambioMotivo = '';

    /* TG-241 (A12): la fabrica confirmo algo y al final no lo surtio. */
    public ?int $noSurtidoLineaId = null;
    public string $noSurtidoMotivo = '';

    public function mount(int $id)
    {
        if (! Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }

        $this->pedidoId = $id;

        if ($this->pedido->tipo === 'cliente_directo' && $this->resumen['anticipo_pendiente'] > 0) {
            $this->pagoTipo = 'anticipo';
        } else {
            $this->pagoTipo = 'saldo_pedido';
        }
    }

    public function getPedidoProperty()
    {
        return Pedido::query()
            ->with(['clienteDirecto', 'revendedorAfiliacion.revendedor', 'detalle', 'pagos'])
            ->findOrFail($this->pedidoId);
    }

    /** @return array<int, string> producto_campana_id => url */
    public function getFotosProperty(): array
    {
        return app(ImagenDeProducto::class)->porProductoCampana(
            $this->pedido->detalle->pluck('producto_campana_id'),
        );
    }

    public function getResumenProperty(): array
    {
        return app(RegistrarPagoPedidoAction::class)->resumen($this->pedido);
    }

    public function enviar(EnviarPedidoAction $accion)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $accion->ejecutar($this->pedido);
            unset($this->pedido);
            unset($this->resumen);
            $this->mensaje = 'Pedido enviado correctamente.';
        } catch (ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo enviar.';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo enviar el pedido. Intenta de nuevo.');
        }
    }

    public function marcarListo(EntregaPedidoAction $accion)
    {
        $this->pasoDeEntrega(fn () => $accion->marcarListo($this->pedido), 'Pedido listo para entrega. Se le avisó al cliente.');
    }

    public function marcarEntregado(EntregaPedidoAction $accion)
    {
        $this->pasoDeEntrega(fn () => $accion->marcarEntregado($this->pedido), 'Pedido entregado.');
    }

    protected function pasoDeEntrega(callable $paso, string $exito): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $paso();
            unset($this->pedido);
            unset($this->resumen);
            $this->mensaje = $exito;
        } catch (ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo cambiar el estado.';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo cambiar el estado del pedido. Intenta de nuevo.');
        }
    }

    /**
     * TG-226 (G7): el personal le pregunta a Mercado Pago si ya se pagó un
     * anticipo (o, desde TG-227, un saldo) pendiente, mientras llega el aviso
     * automático de G9.
     */
    public function verificarMercadoPago(VerificarPagoMercadoPagoAction $accion)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        $pagoMpId = trim($this->pagoMpId);

        if ($pagoMpId !== '' && preg_match('/^[0-9]{1,20}$/', $pagoMpId) !== 1) {
            $this->errorMsg = 'El número de pago de Mercado Pago solo lleva dígitos.';

            return;
        }

        // TG-227 (G8): el pendiente puede ser del anticipo o del saldo;
        // TG-229 (G10): o el pago del cliente mayorista.
        $tipoPendiente = $this->pedido->pagos
            ->filter(fn ($p) => $p->esMercadoPagoPendiente() && $p->preferencia_externa !== null)
            ->sortByDesc('id')
            ->first()?->tipo;
        $deQue = match ($tipoPendiente) {
            'saldo_pedido'     => 'este saldo',
            'total_revendedor' => 'este pago',
            default            => 'este anticipo',
        };

        try {
            $resultado = $accion->ejecutar($this->pedido, $pagoMpId === '' ? null : $pagoMpId, 'panel');
            unset($this->pedido);
            unset($this->resumen);

            $this->mensaje = match ($resultado) {
                VerificarPagoMercadoPagoAction::APLICADO => 'Mercado Pago confirmó el pago. Ya quedó aplicado.',
                VerificarPagoMercadoPagoAction::RECHAZADO => 'Mercado Pago rechazó el intento de pago. El cliente puede intentarlo de nuevo.',
                VerificarPagoMercadoPagoAction::VENCIDO => 'El enlace de pago venció sin pagarse.',
                VerificarPagoMercadoPagoAction::NO_CUADRA => 'Mercado Pago tiene un pago que no coincide con '.$deQue.' (referencia, monto, moneda o cuenta) y no se aplicó. Revísalo en tu cuenta de Mercado Pago antes de registrar algo a mano.',
                default => 'Mercado Pago todavía no confirma el pago.',
            };

            if ($resultado === VerificarPagoMercadoPagoAction::APLICADO) {
                $this->pagoMpId = '';
            }
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo verificar el pago con Mercado Pago. Intenta de nuevo.');
        }
    }

    public function registrarPago(RegistrarPagoPedidoAction $accion)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        if ($this->pedido->tipo === 'cliente_directo' && $this->resumen['anticipo_pendiente'] <= 0) {
            $this->pagoTipo = 'saldo_pedido';
        } elseif ($this->pedido->tipo !== 'cliente_directo') {
            $this->pagoTipo = 'saldo_pedido';
        }

        $this->validate([
            'pagoTipo' => 'required|in:anticipo,saldo_pedido',
            'pagoMetodo' => 'required|in:efectivo,transferencia,tarjeta,otro',
            'pagoMonto' => 'required|numeric|min:0.01',
            'pagoReferencia' => 'nullable|string|max:190',
        ]);

        try {
            $accion->ejecutar($this->pedido, [
                'tipo' => $this->pagoTipo,
                'metodo' => $this->pagoMetodo,
                'monto' => $this->pagoMonto,
                'referencia' => $this->pagoReferencia !== '' ? $this->pagoReferencia : null,
            ]);
            unset($this->pedido);
            unset($this->resumen);
            $this->mensaje = 'Pago registrado.';
            $this->pagoReferencia = '';
        } catch (ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo registrar el pago.';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo registrar el pago. Intenta de nuevo.');
        }
    }

    /** TG-266: qué líneas están "bajo pedido" (estado actual de la talla). */
    public function getBajoPedidoProperty(): array
    {
        return \App\Models\DisponibilidadVarianteCampana::bajoPedidoPorLinea($this->pedido->detalle);
    }

    /** TG-254: si cada línea puede pedir cambio y hasta cuándo. */
    public function getCambiosProperty(): array
    {
        $plazo = app(PlazoCambio::class);

        return $this->pedido->detalle
            ->mapWithKeys(fn ($l) => [$l->id => $plazo->estado($l->setRelation('pedido', $this->pedido))])
            ->all();
    }

    public function abrirCambio(int $lineaId): void
    {
        $this->cambioLineaId = $lineaId;
        $this->cambioMotivo = '';
        $this->mensaje = '';
        $this->errorMsg = '';
    }

    public function cancelarCambio(): void
    {
        $this->cambioLineaId = null;
        $this->cambioMotivo = '';
    }

    // ------------------------------------------------------------------
    // TG-241 (A12): producto no surtido por fabrica
    // ------------------------------------------------------------------

    /** True si el pedido esta en un momento donde esto tiene sentido. */
    public function getPuedeMarcarNoSurtidoProperty(): bool
    {
        return in_array($this->pedido->estado, MarcarNoSurtidoAction::ESTADOS_QUE_LO_PERMITEN, true);
    }

    /** Cuanto se le puede devolver por cada renglon, para enseñarlo antes. */
    public function getReembolsosProperty(): array
    {
        $accion = app(ResolverNoSurtidoAction::class);
        $montos = [];

        foreach ($this->pedido->detalle as $linea) {
            if ($linea->estado_surtido === 'no_surtido') {
                $montos[$linea->id] = $accion->montoADevolver($linea);
            }
        }

        return $montos;
    }

    public function abrirNoSurtido(int $lineaId): void
    {
        $this->noSurtidoLineaId = $lineaId;
        $this->noSurtidoMotivo = '';
        $this->mensaje = '';
        $this->errorMsg = '';
    }

    public function cancelarNoSurtido(): void
    {
        $this->noSurtidoLineaId = null;
        $this->noSurtidoMotivo = '';
    }

    public function marcarNoSurtido(MarcarNoSurtidoAction $accion): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $linea = $this->pedido->detalle()->findOrFail($this->noSurtidoLineaId);
            $accion->ejecutar($linea, $this->noSurtidoMotivo);

            // Sin esto la fila sigue viendose igual hasta recargar: el pedido
            // es una propiedad calculada y se guarda en memoria por peticion.
            unset($this->pedido);

            $this->cancelarNoSurtido();
            $this->mensaje = 'Marcado como no surtido. Al cliente le llegó el aviso para que elija qué prefiere.';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo marcar como no surtido.');
        }
    }

    /** Lo que la persona dijo por telefono, anotado desde el mostrador. */
    public function registrarDecision(int $lineaId, string $resolucion, ResolverNoSurtidoAction $accion): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $linea = $this->pedido->detalle()->findOrFail($lineaId);
            $accion->registrarDecision($linea, $resolucion);

            unset($this->pedido);

            $this->mensaje = $resolucion === 'sustitucion'
                ? 'Anotado: se le va a cambiar por otro producto.'
                : 'Anotado: hay que devolverle su anticipo. Registra la entrega cuando se lo des.';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo registrar la decisión.');
        }
    }

    /** El efectivo que de verdad se entrego. Es la unica salida de dinero del sistema. */
    public function entregarReembolso(int $lineaId, ResolverNoSurtidoAction $accion): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $linea = $this->pedido->detalle()->findOrFail($lineaId);
            $pago = $accion->entregarReembolso($linea);

            unset($this->pedido);

            $this->mensaje = 'Reembolso registrado: $'.number_format((float) $pago->monto, 2).' (folio '.$pago->folio.').';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo registrar el reembolso.');
        }
    }

    public function solicitarCambio(SolicitarCambioAction $accion): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        try {
            $accion->ejecutar(['pedido_detalle_id' => $this->cambioLineaId, 'motivo' => $this->cambioMotivo]);
            $this->cancelarCambio();
            $this->mensaje = 'Solicitud de cambio registrada. Si se autoriza, el cliente recibe un vale (no hay devoluciones en efectivo).';
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo registrar la solicitud de cambio.');
        }
    }
};
?>

<div>
    <x-panel.encabezado :titulo="$this->pedido->folio" :volver="route('pedidos.index')" volver-texto="← Pedidos" class="mb-6">
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-ui.insignia-estado :estado="$this->pedido->estado" />
                <span class="text-sm text-slate-500">
                    {{ $this->pedido->tipo === 'cliente_directo' ? 'Cliente' : 'Cliente mayorista' }}:
                    {{ $this->pedido->clienteDirecto?->nombre ?? ($this->pedido->revendedorAfiliacion?->revendedor?->nombre ?? '—') }}
                </span>
            </div>

        <x-slot:acciones>
        @if ($this->pedido->estado === 'borrador')
            <button type="button" wire:click="enviar"
                class="fp-btn fp-btn-oscuro">
                Enviar pedido
            </button>
        @endif

        @if (in_array($this->pedido->estado, ['recibido_distribuidora', 'listo_entrega'], true))
            <div class="flex flex-wrap gap-2">
                @if ($this->pedido->estado === 'recibido_distribuidora')
                    <button type="button" wire:click="marcarListo"
                        wire:confirm="¿Marcar listo para entrega? Se le avisará al cliente para que pase a recoger."
                        class="fp-btn fp-btn-secundario">
                        Marcar listo para entrega
                    </button>
                @endif
                <button type="button" wire:click="marcarEntregado"
                    wire:confirm="¿Confirmar que el cliente se llevó su pedido?"
                    @disabled($this->resumen['saldo'] > 0)
                    @if ($this->resumen['saldo'] > 0) title="Cobra el saldo antes de entregar" @endif
                    class="fp-btn fp-btn-oscuro disabled:cursor-not-allowed disabled:opacity-50">
                    Marcar entregado
                </button>
            </div>
        @endif
        </x-slot:acciones>
    </x-panel.encabezado>

    @if ($this->pedido->estado === 'borrador')
        <x-panel.alerta tipo="aviso" class="mb-4">
            Este pedido sigue en borrador.
            <a href="{{ route('pedidos.create') }}?continuar={{ $this->pedido->id }}"
                class="font-medium fp-enlace">Continuar editando</a>
        </x-panel.alerta>
    @endif

    @if (in_array($this->pedido->estado, ['recibido_distribuidora', 'listo_entrega'], true))
        <x-panel.alerta tipo="info" class="mb-4">
            La mercancía ya está en sucursal.
            @if ($this->resumen['saldo'] > 0)
                Saldo pendiente:
                <span class="font-semibold tabular-nums">${{ number_format($this->resumen['saldo'], 2) }}</span>.
                Cobra el saldo abajo antes de entregar.
            @else
                No hay saldo pendiente.
            @endif
        </x-panel.alerta>
    @endif

    {{-- TG-52 / TG-244 (E13-02): alerta del plazo de recolección (dias_maximos_recoleccion). --}}
    @if ($this->pedido->estado === 'listo_entrega' && $this->pedido->fecha_limite_recoleccion)
        @php
            $limite = $this->pedido->fecha_limite_recoleccion->copy()->timezone('America/Mexico_City');
            $vencido = now()->greaterThan($limite);
            $porVencer = ! $vencido && now()->diffInHours($limite, false) <= 48;
        @endphp
        <x-panel.alerta :tipo="$vencido ? 'error' : ($porVencer ? 'aviso' : 'info')" class="mb-4">
            @if ($vencido)
                El plazo de recolección venció el {{ $limite->format('d/m/Y H:i') }}.
            @elseif ($porVencer)
                El plazo de recolección está por vencer: el cliente tiene hasta el {{ $limite->format('d/m/Y H:i') }}.
            @else
                Tiene hasta el {{ $limite->format('d/m/Y H:i') }} para recogerlo.
            @endif
        </x-panel.alerta>
    @endif

    @if ($mensaje)
        <x-panel.alerta tipo="exito" class="mb-4">
            {{ $mensaje }}
        </x-panel.alerta>
    @endif

    @if ($errorMsg)
        <x-panel.alerta tipo="error" class="mb-4">
            {{ $errorMsg }}
        </x-panel.alerta>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 {{ $this->pedido->tipo === 'cliente_directo' ? 'lg:grid-cols-4' : 'lg:grid-cols-3' }} mb-6">
        <div class="fp-tarjeta p-4 fp-aparecer">
            <p class="text-xs text-slate-500">Total</p>
            <p class="text-lg font-semibold tabular-nums">${{ number_format((float) $this->pedido->total, 2) }}</p>
        </div>
        <div class="fp-tarjeta p-4 fp-aparecer">
            <p class="text-xs text-slate-500">Pagado</p>
            <p class="text-lg font-semibold tabular-nums">${{ number_format($this->resumen['pagado'], 2) }}</p>
            @if ($this->resumen['pagado_con_vales'] > 0)
                <p class="text-xs text-slate-500 tabular-nums">Incluye ${{ number_format($this->resumen['pagado_con_vales'], 2) }} en vales</p>
            @endif
        </div>
        <div class="fp-tarjeta p-4 fp-aparecer">
            <p class="text-xs text-slate-500">Saldo</p>
            <p class="text-lg font-semibold tabular-nums">${{ number_format($this->resumen['saldo'], 2) }}</p>
        </div>
        @if ($this->pedido->tipo === 'cliente_directo')
            <div class="fp-tarjeta p-4 fp-aparecer">
                <p class="text-xs text-slate-500">Anticipo pendiente</p>
                <p class="text-lg font-semibold tabular-nums">${{ number_format($this->resumen['anticipo_pendiente'], 2) }}</p>
            </div>
        @endif
    </div>

    @if ($this->pedido->estado !== 'borrador')
        <div class="mb-6 fp-tarjeta p-4 fp-formulario fp-aparecer">
            <h3 class="font-medium text-slate-800 mb-3">Registrar pago</h3>
            <form wire:submit="registrarPago" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 items-end">
                <label class="text-sm">
                    <span class="block text-slate-500 mb-1">Tipo</span>
                    <select wire:model="pagoTipo" class="w-full rounded-lg text-sm">
                        @if ($this->pedido->tipo === 'cliente_directo' && $this->resumen['anticipo_pendiente'] > 0)
                            <option value="anticipo">Anticipo</option>
                        @endif
                        <option value="saldo_pedido">Saldo / Total</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="block text-slate-500 mb-1">Método</span>
                    <select wire:model="pagoMetodo" class="w-full rounded-lg text-sm">
                        <option value="efectivo">Efectivo</option>
                        <option value="transferencia">Transferencia</option>
                        <option value="tarjeta">Tarjeta</option>
                        <option value="otro">Otro</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="block text-slate-500 mb-1">Monto</span>
                    <input type="number" step="0.01" min="0.01" wire:model="pagoMonto"
                        class="w-full rounded-lg text-sm" placeholder="0.00">
                </label>
                <label class="text-sm">
                    <span class="block text-slate-500 mb-1">Referencia</span>
                    <input type="text" wire:model="pagoReferencia"
                        class="w-full rounded-lg text-sm" maxlength="190">
                </label>
                <div class="sm:col-span-2 lg:col-span-4">
                    <button type="submit"
                        class="fp-btn fp-btn-primario">
                        Registrar pago
                    </button>
                </div>
            </form>
        </div>
    @endif

    @if ($this->pedido->pagos->isNotEmpty())
        <div class="mb-6 fp-tarjeta overflow-hidden fp-aparecer">
            <div class="px-4 py-3 border-b border-slate-100 font-medium text-slate-800">Pagos</div>
            <table class="fp-tabla min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                    <tr>
                        <th class="px-4 py-2 font-medium">Folio</th>
                        <th class="px-4 py-2 font-medium">Tipo</th>
                        <th class="px-4 py-2 font-medium">Método</th>
                        <th class="px-4 py-2 font-medium text-right">Monto</th>
                        <th class="px-4 py-2 font-medium">Fecha</th>
                        <th class="px-4 py-2 font-medium">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($this->pedido->pagos as $p)
                        <tr>
                            <td class="px-4 py-2">{{ $p->folio }}</td>
                            <td class="px-4 py-2">{{ \App\Models\Pago::etiquetaTipo($p->tipo) }}</td>
                            <td class="px-4 py-2">{{ \App\Models\Pago::etiquetaMetodo($p->metodo) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">${{ number_format((float) $p->monto, 2) }}</td>
                            <td class="px-4 py-2">{{ optional($p->fecha_pago)->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2">
                                {{-- TG-226: un pago pendiente todavía no cuenta como pagado. --}}
                                @php
                                    [$varianteEstado, $textoEstado] = match ($p->estado) {
                                        'pendiente' => ['warning', 'Pendiente'],
                                        'fallido' => ['neutral', 'Fallido'],
                                        'revertido' => ['danger', 'Revertido'],
                                        default => ['success', 'Aplicado'],
                                    };
                                @endphp
                                <x-ui.insignia-estado :variante="$varianteEstado" :texto="$textoEstado" />
                                @if ($p->esMercadoPagoPendiente() && $p->preferencia_externa)
                                    <div class="mt-1 flex items-center gap-2">
                                        {{-- Opcional: el número de pago que el cliente ve en su comprobante de Mercado Pago. --}}
                                        <input type="text" inputmode="numeric" wire:model="pagoMpId" maxlength="20"
                                            placeholder="N.º de pago (opcional)" aria-label="Número de pago de Mercado Pago (opcional)"
                                            class="w-40 rounded-lg px-2 py-1 text-xs" />
                                        <button type="button" wire:click="verificarMercadoPago" wire:loading.attr="disabled"
                                            class="text-xs font-medium fp-enlace">
                                            Verificar con Mercado Pago
                                        </button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="fp-tarjeta overflow-hidden fp-aparecer">
        <div class="px-4 py-3 border-b border-slate-100 font-medium text-slate-800">Líneas</div>
        <table class="fp-tabla min-w-full text-sm">
            <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
                <tr>
                    <th class="px-4 py-2 font-medium">Producto</th>
                    <th class="px-4 py-2 font-medium">Talla</th>
                    <th class="px-4 py-2 font-medium">Color</th>
                    <th class="px-4 py-2 font-medium text-right">Cant.</th>
                    <th class="px-4 py-2 font-medium text-right">P. unit.</th>
                    <th class="px-4 py-2 font-medium text-right">Subtotal</th>
                    <th class="px-4 py-2 font-medium">Surtido</th>
                    @if ($this->pedido->estado === 'entregado')
                        <th class="px-4 py-2 font-medium">Cambio</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->pedido->detalle as $l)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <x-ui.miniatura
                                    :url="$this->fotos[$l->producto_campana_id] ?? null"
                                    :alt="$l->producto_nombre" />
                                <div class="min-w-0">
                                    <div class="font-medium text-slate-900">{{ $l->producto_nombre }}</div>
                                    <div class="text-xs text-slate-500">{{ $l->modelo }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            {{ $l->talla }}
                            @if ($this->bajoPedido[$l->id] ?? false)
                                <x-ui.insignia-estado variante="warning" texto="Bajo pedido" class="ml-1" />
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $l->color }}</td>
                        <td class="px-4 py-3 text-right">{{ $l->cantidad }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">${{ number_format((float) $l->precio_unitario, 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">${{ number_format((float) $l->subtotal, 2) }}</td>
                        {{-- TG-241 (A12): lo que la fábrica no surtió. --}}
                        <td class="px-4 py-3">
                            @if ($l->estado_surtido === 'no_surtido')
                                <x-ui.insignia-estado variante="danger" texto="No surtido" />
                                @if ($l->motivo_no_surtido)
                                    <p class="mt-1 text-xs text-slate-500">{{ $l->motivo_no_surtido }}</p>
                                @endif

                                @if ($l->resolucion_no_surtido === 'pendiente')
                                    <p class="mt-2 text-xs font-medium text-slate-700">¿Qué prefiere el cliente?</p>
                                    <div class="mt-1 flex flex-wrap gap-2">
                                        <button type="button" wire:click="registrarDecision({{ $l->id }}, 'sustitucion')"
                                            class="fp-btn fp-btn-secundario fp-btn-sm">
                                            Cambiarlo por otro
                                        </button>
                                        <button type="button" wire:click="registrarDecision({{ $l->id }}, 'reembolso_anticipo')"
                                            class="fp-btn fp-btn-secundario fp-btn-sm">
                                            Devolverle su anticipo
                                        </button>
                                    </div>
                                @elseif ($l->resolucion_no_surtido === 'sustitucion')
                                    <p class="mt-2 text-xs text-slate-600">Se le cambia por otro producto.</p>
                                @else
                                    @php($porDevolver = $this->reembolsos[$l->id] ?? 0)
                                    @if ($porDevolver > 0)
                                        <p class="mt-2 text-xs text-slate-600">
                                            Hay que devolverle <strong>${{ number_format($porDevolver, 2) }}</strong> en efectivo.
                                        </p>
                                        <button type="button" wire:click="entregarReembolso({{ $l->id }})"
                                            wire:confirm="¿Ya le entregaste el efectivo al cliente? Esto lo deja registrado."
                                            class="fp-btn fp-btn-primario fp-btn-sm mt-1">
                                            Registrar que ya se le entregó
                                        </button>
                                    @else
                                        <p class="mt-2 text-xs text-slate-600">Anticipo devuelto.</p>
                                    @endif
                                @endif
                            @elseif ($this->puedeMarcarNoSurtido)
                                @if ($noSurtidoLineaId === $l->id)
                                    <div class="space-y-2">
                                        <textarea wire:model="noSurtidoMotivo" rows="2" maxlength="300"
                                            placeholder="¿Por qué no se surtió?" class="w-full rounded-lg text-sm"></textarea>
                                        <div class="flex gap-2">
                                            <button type="button" wire:click="marcarNoSurtido"
                                                class="fp-btn fp-btn-peligro fp-btn-sm">Marcar no surtido</button>
                                            <button type="button" wire:click="cancelarNoSurtido"
                                                class="fp-btn fp-btn-fantasma fp-btn-sm">Cancelar</button>
                                        </div>
                                    </div>
                                @else
                                    <button type="button" wire:click="abrirNoSurtido({{ $l->id }})"
                                        class="text-xs font-medium text-fp-danger transition-colors hover:text-fp-danger/80 hover:underline">No surtido</button>
                                @endif
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        @if ($this->pedido->estado === 'entregado')
                            @php($cambio = $this->cambios[$l->id] ?? null)
                            <td class="px-4 py-3">
                                @if ($cambio && $cambio['solicitud'])
                                    <span class="text-xs text-slate-600">Solicitud {{ str_replace('_', ' ', $cambio['solicitud']['estado']) }}</span>
                                @elseif ($cambio && $cambio['puede_solicitar'])
                                    @if ($cambioLineaId === $l->id)
                                        <div class="space-y-2">
                                            <textarea wire:model="cambioMotivo" rows="2" maxlength="300" placeholder="Motivo del cambio"
                                                class="w-full rounded-lg text-sm"></textarea>
                                            <div class="flex gap-2">
                                                <button type="button" wire:click="solicitarCambio" class="fp-btn fp-btn-primario fp-btn-sm">Enviar solicitud</button>
                                                <button type="button" wire:click="cancelarCambio" class="fp-btn fp-btn-fantasma fp-btn-sm">Cancelar</button>
                                            </div>
                                        </div>
                                    @else
                                        <button type="button" wire:click="abrirCambio({{ $l->id }})" class="fp-enlace text-sm font-medium">Solicitar cambio</button>
                                        <span class="block text-xs text-slate-500">Hasta {{ \Illuminate\Support\Carbon::parse($cambio['fecha_limite'])->format('d/m/Y') }}</span>
                                    @endif
                                @else
                                    <span class="text-xs text-slate-400">Fuera de plazo</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-slate-500">Sin líneas</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>