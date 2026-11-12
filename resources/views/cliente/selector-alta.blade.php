{{-- Buscador y alta rápida. Se incluye en una pantalla Livewire. --}}
<fieldset class="mb-2" data-primera-compra>
    <legend class="block text-sm text-slate-600 mb-1">¿Primera compra?</legend>
    <label class="mr-4 text-sm"><input type="radio" wire:model.live="primeraCompra" value="si"> Sí</label>
    <label class="text-sm"><input type="radio" wire:model.live="primeraCompra" value="no"> No</label>
</fieldset>
@if ($primeraCompra !== 'si')
    <x-ui.buscador wire:model.live.debounce.400ms="busquedaCliente"
        placeholder="Buscar por nombre o teléfono" etiqueta="Buscar cliente" />
    <select wire:model.live="{{ $seleccion }}" aria-label="{{ $etiqueta }}"
        class="mt-2 w-full rounded-lg text-sm focus:ring-fp-primary">
        <option value="">{{ $vacio }}</option>
        @foreach ($opciones as $opcion)
            <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
        @endforeach
    </select>
    @if (trim($busquedaCliente) !== '' && $opciones->isEmpty())
        <p class="mt-1 text-xs text-slate-500">No hay clientes que coincidan con la búsqueda.</p>
    @endif
@else
    <div class="space-y-2" data-alta-rapida>
        <label class="block text-sm">Nombre
            <input type="text" wire:model="altaNombre" class="mt-1 w-full rounded-lg text-sm" data-alta-nombre>
        </label>
        <label class="block text-sm">Teléfono
            <input type="text" wire:model="altaTelefono" class="mt-1 w-full rounded-lg text-sm" data-alta-telefono>
        </label>
        <label class="block text-sm">Correo (opcional)
            <input type="email" wire:model="altaCorreo" class="mt-1 w-full rounded-lg text-sm">
        </label>
        <label class="block text-sm">Dirección (opcional)
            <input type="text" wire:model="altaDireccion" class="mt-1 w-full rounded-lg text-sm">
        </label>
        <label class="block text-sm">Foto (opcional)
            <input type="file" wire:model="altaFoto" accept="image/*" class="mt-1 text-sm">
        </label>
        <button type="button" wire:click="crearAltaRapida" class="fp-btn fp-btn-primario" data-alta-rapida>Guardar cliente</button>
    </div>
@endif
@if ($avisoCliente !== '')
    <p class="mt-2 text-sm text-slate-700" data-aviso-cliente>{{ $avisoCliente }}</p>
@endif
