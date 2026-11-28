<?php

use App\Models\ConfiguracionDistribuidora;
use App\Services\Distribuidora\GuardarDatosBancariosAction;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component {
    public string $banco = '';

    public string $clabe = '';

    public string $titular = '';

    public bool $aceptaTransferencias = true;

    public string $errorMsg = '';

    public function mount(): void
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');

        $config = ConfiguracionDistribuidora::query()->first();

        if ($config === null) {
            return;
        }

        $this->banco = (string) ($config->banco ?? '');
        $this->clabe = (string) ($config->clabe ?? '');
        $this->titular = (string) ($config->titular_cuenta ?? '');
        $this->aceptaTransferencias = (bool) ($config->acepta_transferencias ?? true);
    }

    public function guardar(): void
    {
        $this->errorMsg = '';

        try {
            app(GuardarDatosBancariosAction::class)->ejecutar([
                'banco' => $this->banco,
                'clabe' => $this->clabe,
                'titular' => $this->titular,
            ]);
        } catch (ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'Revisa los datos bancarios.';

            return;
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudieron guardar los datos bancarios.');

            return;
        }

        $this->dispatch('guardado', mensaje: 'Datos para transferencia guardados.');
    }

    /** Interruptor «Aceptar transferencias»: se guarda al momento y los datos no se tocan. */
    public function alternarTransferencias(): void
    {
        $this->errorMsg = '';
        $nuevo = ! $this->aceptaTransferencias;

        try {
            app(GuardarDatosBancariosAction::class)->cambiarDisponibilidad($nuevo);
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo cambiar la disponibilidad de las transferencias.');

            return;
        }

        $this->aceptaTransferencias = $nuevo;

        $this->dispatch('guardado', mensaje: $nuevo
            ? 'Las transferencias están disponibles.'
            : 'Las transferencias quedaron como no disponibles por el momento.');
    }
};
?>

@php
    $tieneDatosBancarios = $banco !== '' && $clabe !== '' && $titular !== '';
    $ofreceTransferencias = $aceptaTransferencias && $tieneDatosBancarios;
@endphp

<div class="fp-formulario" data-datos-bancarios>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Transferencia bancaria</h2>
            <p class="mt-1 max-w-2xl text-sm text-slate-500">
                Banco, CLABE y titular se muestran en el pedido a quien debe pagarlo. Si los dejas vacíos, no se muestra nada.
                FootwearPoint no recibe ni custodia estos pagos.
            </p>
        </div>
        <span @class([
            'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium',
            'border-emerald-200 bg-emerald-50 text-emerald-800' => $ofreceTransferencias,
            'border-amber-200 bg-amber-50 text-amber-800' => ! $ofreceTransferencias,
        ]) data-estado-transferencias>
            <span @class(['h-1.5 w-1.5 rounded-full', 'bg-emerald-500' => $ofreceTransferencias, 'bg-amber-500' => ! $ofreceTransferencias])></span>
            @if ($ofreceTransferencias)
                Disponible
            @elseif (! $tieneDatosBancarios)
                Faltan datos
            @else
                No disponible por el momento
            @endif
        </span>
    </div>

    @if (! $tieneDatosBancarios)
        <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" data-aviso-faltan-datos>
            Captura tus datos bancarios para aceptar transferencias.
        </p>
    @endif

    <div class="mt-4 flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white px-4 py-3">
        <div>
            <p id="etiqueta-acepta-transferencias" class="text-sm font-medium text-slate-800">Aceptar transferencias</p>
            <p class="text-xs text-slate-500">
                @if (! $tieneDatosBancarios)
                    Captura tus datos bancarios para aceptar transferencias.
                @elseif ($aceptaTransferencias)
                    Tus clientes y tu equipo pueden pagar por transferencia.
                @else
                    No disponible por el momento: la transferencia no aparece al cobrar un pedido ni al cliente. Tus datos se conservan.
                @endif
            </p>
        </div>
        <button
            type="button"
            role="switch"
            aria-checked="{{ $aceptaTransferencias ? 'true' : 'false' }}"
            aria-labelledby="etiqueta-acepta-transferencias"
            wire:click="alternarTransferencias"
            wire:loading.attr="disabled"
            wire:target="alternarTransferencias"
            @class([
                'relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary focus-visible:ring-offset-2 disabled:opacity-60',
                'bg-emerald-500' => $aceptaTransferencias,
                'bg-slate-300' => ! $aceptaTransferencias,
            ])
            data-interruptor-transferencias
        >
            <span @class([
                'inline-block h-5 w-5 rounded-full bg-white shadow transition-transform',
                'translate-x-5' => $aceptaTransferencias,
                'translate-x-0.5' => ! $aceptaTransferencias,
            ])></span>
        </button>
    </div>

    @if ($errorMsg !== '')
        <p class="mt-3 text-sm text-fp-danger" role="alert" data-error-datos-bancarios>{{ $errorMsg }}</p>
    @endif

    <form wire:submit="guardar" class="mt-4 grid gap-4 sm:grid-cols-2">
        <x-ui.select-banco id="banco-transferencia" modelo="banco" :valor="$banco" clabe-id="clabe-transferencia" />
        <label class="text-sm">
            <span class="block font-medium text-slate-700">CLABE</span>
            <input type="text" id="clabe-transferencia" inputmode="numeric" wire:model="clabe" maxlength="22" class="mt-1 w-full rounded-lg text-sm" data-clabe>
        </label>
        <label class="text-sm sm:col-span-2">
            <span class="block font-medium text-slate-700">Titular</span>
            <input type="text" wire:model="titular" maxlength="150" class="mt-1 w-full rounded-lg text-sm" data-titular>
        </label>
        <div>
            <button type="submit" class="fp-btn fp-btn-primario">Guardar datos bancarios</button>
        </div>
    </form>
</div>