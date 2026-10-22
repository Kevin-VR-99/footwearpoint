{{-- Estado de un reembolso automático en Mercado Pago, con el logo oficial
     y el botón de reintento si falló (solo para quien tiene permiso). --}}
@props(['reembolso', 'puedeReintentar' => false])

<div {{ $attributes->merge(['class' => 'mt-1 flex flex-wrap items-center gap-2 text-xs']) }} data-reembolso-mp="{{ $reembolso->id }}">
    <x-ui.logo-mercado-pago class="h-5 w-auto shrink-0" />
    <x-ui.insignia-estado :variante="$reembolso->variante()" :texto="$reembolso->etiqueta()" />
    <span class="tabular-nums text-slate-700">${{ number_format((float) $reembolso->monto, 2) }}{{ $reembolso->parcial ? ' (parcial)' : '' }}</span>
    @if ($reembolso->mp_reembolso_id)
        <span class="text-slate-500">N.º de reembolso {{ $reembolso->mp_reembolso_id }}</span>
    @endif
    @if ($reembolso->aprobado_at)
        <span class="text-slate-500">{{ $reembolso->aprobado_at->timezone('America/Mexico_City')->format('d/m/Y H:i') }}</span>
    @endif
    @if ($reembolso->ultimo_error && $reembolso->estado !== \App\Models\ReembolsoMercadoPago::APROBADO)
        <span class="basis-full text-fp-danger">{{ $reembolso->ultimo_error }}</span>
    @endif
    @if ($puedeReintentar && $reembolso->sePuedeReintentar())
        <button type="button" wire:click="reintentarReembolsoMp({{ $reembolso->id }})" wire:loading.attr="disabled"
            class="fp-btn fp-btn-secundario fp-btn-sm" data-reintentar-reembolso="{{ $reembolso->id }}">
            Reintentar reembolso
        </button>
    @endif
</div>
