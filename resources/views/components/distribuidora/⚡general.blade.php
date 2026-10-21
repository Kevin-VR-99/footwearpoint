<?php

use App\Models\ConfiguracionDistribuidora;
use App\Services\Distribuidora\ActualizarConfiguracionDistribuidoraAction;
use Livewire\Component;

new class extends Component {
    public float $anticipo_por_producto = 0;
    public int $dias_solicitud_cambio = 12;
    public int $dias_gestion_devolucion = 20;
    public int $dias_vigencia_vale = 90;
    public int $dias_maximos_recoleccion = 5;
    public int $stock_minimo_general = 0;

    public function mount(): void
    {
        $config = ConfiguracionDistribuidora::first();
        if ($config) {
            $this->anticipo_por_producto = (float) $config->anticipo_por_producto;
            $this->dias_solicitud_cambio = $config->dias_solicitud_cambio;
            $this->dias_gestion_devolucion = $config->dias_gestion_devolucion;
            $this->dias_vigencia_vale = $config->dias_vigencia_vale;
            $this->dias_maximos_recoleccion = $config->dias_maximos_recoleccion;
            $this->stock_minimo_general = (int) $config->stock_minimo_general;
        }
    }

    public function guardarConfiguracion(): void
    {
        $datos = $this->validate([
            'anticipo_por_producto' => ['required', 'numeric', 'min:0'],
            'dias_solicitud_cambio' => ['required', 'integer', 'min:1'],
            'dias_gestion_devolucion' => ['required', 'integer', 'min:1'],
            'dias_vigencia_vale' => ['required', 'integer', 'min:1'],
            'dias_maximos_recoleccion' => ['required', 'integer', 'min:1'],
            'stock_minimo_general' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        app(ActualizarConfiguracionDistribuidoraAction::class)->ejecutar($datos);
        $this->dispatch('guardado', mensaje: 'Configuración general actualizada correctamente.');
    }

};
?>

<div>
    <form wire:submit="guardarConfiguracion" class="fp-tarjeta p-5 sm:p-6 space-y-4 max-w-2xl fp-formulario fp-aparecer">
        <div class="grid grid-cols-2 gap-4">
            <div class="fp-campo">
                <label for="general-anticipo-por-producto" class="block text-sm font-medium text-slate-700 mb-1">Anticipo por producto (MXN)</label>
                <input id="general-anticipo-por-producto" type="number" step="0.01" wire:model="anticipo_por_producto" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
            </div>
            <div class="fp-campo">
                <label for="general-dias-maximos-recoleccion" class="block text-sm font-medium text-slate-700 mb-1">Días máximos de recolección</label>
                <input id="general-dias-maximos-recoleccion" type="number" wire:model="dias_maximos_recoleccion" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
            </div>
            <div class="fp-campo">
                <label for="general-dias-solicitud-cambio" class="block text-sm font-medium text-slate-700 mb-1">Días para solicitar cambio</label>
                <input id="general-dias-solicitud-cambio" type="number" wire:model="dias_solicitud_cambio" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
            </div>
            <div class="fp-campo">
                <label for="general-dias-gestion-devolucion" class="block text-sm font-medium text-slate-700 mb-1">Días de gestión de devolución</label>
                <input id="general-dias-gestion-devolucion" type="number" wire:model="dias_gestion_devolucion" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
            </div>
            <div class="fp-campo col-span-2">
                <label for="general-dias-vigencia-vale" class="block text-sm font-medium text-slate-700 mb-1">Días de vigencia del vale</label>
                <input id="general-dias-vigencia-vale" type="number" wire:model="dias_vigencia_vale" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
            </div>
        </div>
        <div class="fp-campo" data-stock-minimo-general>
            <label for="general-stock-minimo-general" class="block text-sm font-medium text-slate-700 mb-1">Stock mínimo general (piezas por variante)</label>
            <input id="general-stock-minimo-general" type="number" min="0" wire:model="stock_minimo_general" aria-describedby="ayuda-stock-minimo" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
            <p id="ayuda-stock-minimo" class="mt-1 text-xs text-slate-500">Si una talla y color baja de este número te avisamos en la campana. 0 = sin alerta. Cada variante puede tener su propio mínimo en Stock local.</p>
            @error('stock_minimo_general') <p class="mt-1 text-sm text-fp-danger">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled">Guardar Cambios</button>
    </form>
</div>
