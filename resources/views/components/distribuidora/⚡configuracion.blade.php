<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component {
    public string $pestanaActiva = 'perfil';
};
?>

<div class="mx-auto max-w-5xl">
    {{-- Encabezado minimalista --}}
    <div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-fp-primary">Administración</p>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Configuración</h1>
            <p class="mt-1 text-sm text-slate-500">Datos, reglas y usuarios de tu distribuidora.</p>
        </div>
        <span class="inline-flex items-center gap-1.5 self-start rounded-full border border-fp-danger/20 bg-fp-danger-soft px-2.5 py-1 text-[11px] font-medium text-fp-danger">
            <span class="h-1.5 w-1.5 rounded-full bg-fp-danger"></span>
            Solo admin
        </span>
    </div>

    <div x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible" x-transition
        class="mb-4 rounded-lg border border-fp-primary/20 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm"
        style="display: none;">
        <span class="font-medium text-fp-primary">Listo.</span>
        <span x-text="mensaje"></span>
    </div>

    {{-- Pestañas tipo píldora --}}
    <div class="mb-6 rounded-xl border border-slate-200/80 bg-white p-1.5 shadow-sm">
        <div class="flex flex-wrap gap-1">
            @php
                $tabs = [
                    'perfil' => 'Datos generales',
                    'general' => 'Anticipos y plazos',
                    'ciclos' => 'Ciclos de compra',
                    'usuarios' => 'Usuarios',
                    'clientes' => 'Clientes directos',
                ];
            @endphp
            @foreach ($tabs as $key => $label)
                <button type="button"
                    wire:click="$set('pestanaActiva', '{{ $key }}')"
                    @class([
                        'rounded-lg px-3.5 py-2 text-sm font-medium transition-colors',
                        'bg-fp-primary text-white shadow-sm' => $pestanaActiva === $key,
                        'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => $pestanaActiva !== $key,
                    ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>
        <div class="mt-1.5 h-0.5 w-full rounded-full bg-gradient-to-r from-fp-primary via-white to-fp-danger opacity-80"></div>
    </div>

    <div class="rounded-xl border border-slate-200/80 bg-white p-4 shadow-sm sm:p-6">
        @if ($pestanaActiva === 'perfil')
            <livewire:distribuidora.perfil :key="'config-perfil'" />
        @elseif ($pestanaActiva === 'general')
            <livewire:distribuidora.general :key="'config-general'" />
        @elseif ($pestanaActiva === 'ciclos')
            <livewire:distribuidora.ciclos :key="'config-ciclos'" />
        @elseif ($pestanaActiva === 'usuarios')
            <livewire:distribuidora.usuarios :key="'config-usuarios'" />
        @elseif ($pestanaActiva === 'clientes')
            <livewire:distribuidora.clientes :key="'config-clientes'" />
        @endif
    </div>
</div>
