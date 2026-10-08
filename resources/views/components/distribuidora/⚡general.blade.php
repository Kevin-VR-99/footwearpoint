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
    public string $moneda = 'MXN';
    public string $zona_horaria = 'America/Mexico_City';

    public function mount(): void
    {
        $config = ConfiguracionDistribuidora::first();
        if ($config) {
            $this->anticipo_por_producto = (float) $config->anticipo_por_producto;
            $this->dias_solicitud_cambio = $config->dias_solicitud_cambio;
            $this->dias_gestion_devolucion = $config->dias_gestion_devolucion;
            $this->dias_vigencia_vale = $config->dias_vigencia_vale;
            $this->dias_maximos_recoleccion = $config->dias_maximos_recoleccion;
            $this->moneda = $config->moneda;
            $this->zona_horaria = $config->zona_horaria;
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
            'moneda' => ['required', 'string', 'size:3'],
            'zona_horaria' => ['required', 'string', 'max:60'],
        ]);

        app(ActualizarConfiguracionDistribuidoraAction::class)->ejecutar($datos);
        $this->dispatch('guardado', mensaje: 'Configuración general actualizada correctamente.');
    }

};
?>

<div>
    <form wire:submit="guardarConfiguracion" class="rounded-xl border border-slate-200/80 bg-white p-5 sm:p-6 space-y-4 max-w-2xl">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Anticipo por producto (MXN)</label>
                <input type="number" step="0.01" wire:model="anticipo_por_producto" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Días máximos de recolección</label>
                <input type="number" wire:model="dias_maximos_recoleccion" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Días para solicitar cambio</label>
                <input type="number" wire:model="dias_solicitud_cambio" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Días de gestión de devolución</label>
                <input type="number" wire:model="dias_gestion_devolucion" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Días de vigencia del vale</label>
                <input type="number" wire:model="dias_vigencia_vale" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Moneda</label>
                <input type="text" wire:model="moneda" maxlength="3" class="w-full rounded-md border-slate-300 uppercase">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Zona horaria</label>
            <input type="text" wire:model="zona_horaria" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary">
        </div>
        <button type="submit" class="rounded-lg bg-fp-primary px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-fp-primary/90" wire:loading.attr="disabled">Guardar Cambios</button>
    </form>
</div>
