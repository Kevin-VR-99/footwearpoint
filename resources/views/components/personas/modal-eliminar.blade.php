{{-- Confirmación de «Eliminar definitivamente», igual en las tres pantallas de personas. --}}
@props(['nombre', 'error' => null])

<x-panel.modal titulo="Eliminar definitivamente" cerrar="cancelarEliminar" ancho="max-w-md" data-modal="eliminar">
    <p class="text-sm text-slate-700">
        Vas a borrar a <span class="font-semibold">{{ $nombre }}</span> para siempre.
        No se puede deshacer. Si tiene pedidos u otros movimientos no se podrá eliminar: en ese caso dale de baja.
    </p>
    @if ($error)
        <div class="fp-emerger mt-4 rounded-lg border border-fp-danger/20 bg-fp-danger-soft px-4 py-3 text-sm text-fp-badge-danger-fg" role="alert" data-error-eliminar>
            {{ $error }}
        </div>
    @endif
    <x-slot:pie>
        <button type="button" wire:click="cancelarEliminar" class="fp-btn fp-btn-secundario" autofocus>Cancelar</button>
        <button type="button" wire:click="eliminarDefinitivamente" class="fp-btn fp-btn-peligro"
            wire:loading.attr="disabled" wire:target="eliminarDefinitivamente" @disabled($error)>
            <span wire:loading.remove wire:target="eliminarDefinitivamente">Eliminar definitivamente</span>
            <span wire:loading wire:target="eliminarDefinitivamente">Eliminando…</span>
        </button>
    </x-slot:pie>
</x-panel.modal>
