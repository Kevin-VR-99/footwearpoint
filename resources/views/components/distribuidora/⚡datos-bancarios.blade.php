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
};
?>

<div class="fp-formulario" data-datos-bancarios>
    <h2 class="text-lg font-semibold text-slate-900">Transferencia a tu cuenta</h2>
    <p class="mt-1 max-w-2xl text-sm text-slate-500">
        Banco, CLABE y titular se muestran en el pedido a quien debe pagarlo. Si los dejas vacíos, no se muestra nada.
        FootwearPoint no recibe ni custodia estos pagos.
    </p>

    @if ($errorMsg !== '')
        <p class="mt-3 text-sm text-fp-danger" data-error-datos-bancarios>{{ $errorMsg }}</p>
    @endif

    <form wire:submit="guardar" class="mt-4 grid gap-4 sm:grid-cols-2">
        <label class="text-sm">
            <span class="block font-medium text-slate-700">Banco</span>
            <input type="text" wire:model="banco" maxlength="80" class="mt-1 w-full rounded-lg text-sm" data-banco>
        </label>
        <label class="text-sm">
            <span class="block font-medium text-slate-700">CLABE</span>
            <input type="text" inputmode="numeric" wire:model="clabe" maxlength="22" class="mt-1 w-full rounded-lg text-sm" data-clabe>
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
