<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.panel')] class extends Component {
    public string $pestanaActiva = 'perfil';
};
?>

<div>
    <h1 class="text-xl font-semibold text-slate-800 mb-4">Configuración de la Distribuidora</h1>

    <div x-data="{ visible: false, mensaje: '' }"
        x-on:guardado.window="mensaje = $event.detail.mensaje; visible = true; setTimeout(() => visible = false, 3000)"
        x-show="visible" x-transition
        class="mb-4 rounded-md bg-fp-badge-success-bg text-fp-badge-success-fg px-4 py-2 text-sm" style="display: none;">
        <span x-text="mensaje"></span>
    </div>

    <div class="border-b border-slate-200 mb-6 flex gap-6 flex-wrap">
        <button type="button" wire:click="$set('pestanaActiva', 'perfil')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'perfil' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Datos Generales
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'general')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'general' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Anticipos y Plazos
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'ciclos')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'ciclos' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Ciclos de Compra
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'usuarios')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'usuarios' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Usuarios y Revendedores
        </button>
        <button type="button" wire:click="$set('pestanaActiva', 'clientes')"
            class="pb-3 text-sm font-medium {{ $pestanaActiva === 'clientes' ? 'border-b-2 border-fp-primary text-fp-primary' : 'text-slate-500' }}">
            Clientes Directos
        </button>
    </div>

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
