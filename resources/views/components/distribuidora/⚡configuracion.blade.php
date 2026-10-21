<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component {
    public const PESTANAS = [
        'general' => 'Anticipos y plazos',
        'ciclos' => 'Ciclos de compra',
        'mercado-pago' => 'Mercado Pago',
        // TG-230 (G11): pago de la mensualidad con Mercado Pago.
        'suscripcion' => 'Suscripción',
    ];

    // TG-225 (G6): ?pestana=mercado-pago abre esa pestaña directo (el
    // regreso de Mercado Pago llega así).
    #[Url(as: 'pestana', except: 'general')]
    public string $pestanaActiva = 'general';

    public function mount(): void
    {
        $paginas = [
            'perfil' => 'distribuidora.perfil',
            'usuarios' => 'distribuidora.empleados',
            'clientes' => 'distribuidora.clientes-directos',
        ];

        if (isset($paginas[$this->pestanaActiva])) {
            $this->redirect(route($paginas[$this->pestanaActiva]), navigate: true);

            return;
        }

        if (! array_key_exists($this->pestanaActiva, self::PESTANAS)) {
            $this->pestanaActiva = 'general';
        }
    }
};
?>

<div class="mx-auto max-w-5xl">
    {{-- Encabezado minimalista --}}
    <x-panel.encabezado eyebrow="Administración" titulo="Configuración" nivel="h1"
        subtitulo="Anticipos, ciclos, Mercado Pago y suscripción." class="mb-6">
        <x-slot:acciones>
        <span class="inline-flex items-center gap-1.5 self-start rounded-full border border-fp-danger/20 bg-fp-danger-soft px-2.5 py-1 text-[11px] font-medium text-fp-danger">
            <span class="h-1.5 w-1.5 rounded-full bg-fp-danger"></span>
            Solo admin
        </span>
        </x-slot:acciones>
    </x-panel.encabezado>

    <div x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible" x-transition
        class="fp-emerger mb-4 rounded-lg border border-fp-primary/20 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm"
        style="display: none;">
        <span class="font-medium text-fp-primary">Listo.</span>
        <span x-text="mensaje"></span>
    </div>

    {{-- Pestañas con icono para ubicarse rápido --}}
    @php
        $iconos = [
        'general' => 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4',
        'ciclos' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
        'suscripcion' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
    ];
    @endphp
    <div class="fp-aparecer mb-6 rounded-2xl border border-slate-200/80 bg-white p-1.5 shadow-sm">
        <div class="fp-pestanas flex flex-wrap gap-1" role="tablist" aria-label="Secciones de configuración">
            @foreach ($this::PESTANAS as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $pestanaActiva === $key ? 'true' : 'false' }}"
                    wire:click="$set('pestanaActiva', '{{ $key }}')" data-pestana-config="{{ $key }}"
                    @class([
                        'fp-pestana group inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition',
                        'bg-fp-primary text-white shadow-sm' => $pestanaActiva === $key,
                        'text-slate-600 hover:-translate-y-0.5 hover:bg-slate-50 hover:text-slate-900 motion-reduce:transform-none' => $pestanaActiva !== $key,
                    ])>
                    @if ($key === 'mercado-pago')
                        <x-ui.logo-mercado-pago class="h-4 w-auto" aria-hidden="true" />
                    @elseif (isset($iconos[$key]))
                        <svg class="h-4 w-4 transition-transform duration-200 group-hover:scale-110 motion-reduce:transform-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconos[$key] }}" /></svg>
                    @endif
                    {{ $label }}
                </button>
            @endforeach
        </div>
        <div class="mt-1.5 h-0.5 w-full rounded-full bg-gradient-to-r from-fp-primary via-white to-fp-danger opacity-80"></div>
    </div>

    <div class="fp-tarjeta fp-aparecer p-4 sm:p-6" wire:key="panel-config-{{ $pestanaActiva }}">
        @if ($pestanaActiva === 'general')
            <livewire:distribuidora.general :key="'config-general'" />
        @elseif ($pestanaActiva === 'ciclos')
            <livewire:distribuidora.ciclos :key="'config-ciclos'" />
        @elseif ($pestanaActiva === 'mercado-pago')
            <livewire:distribuidora.mercado-pago :key="'config-mercado-pago'" />
        @elseif ($pestanaActiva === 'suscripcion')
            <livewire:distribuidora.suscripcion :key="'config-suscripcion'" />
        @endif
    </div>
</div>
