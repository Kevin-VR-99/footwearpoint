{{-- Campo de foto de perfil dentro de los modales de personas: vista previa, subir/cambiar, quitar y error. --}}
@props(['modelo', 'archivo' => null, 'actual' => null, 'nombre' => null, 'quitar', 'id', 'datos'])

<div class="flex items-center gap-4" data-foto="{{ $datos }}">
    @if ($archivo && method_exists($archivo, 'isPreviewable') && $archivo->isPreviewable())
        <img src="{{ $archivo->temporaryUrl() }}" alt="Vista previa de la foto nueva" class="fp-foto-vista h-16 w-16 rounded-full object-cover ring-2 ring-fp-primary/30">
    @else
        <x-ui.avatar :url="$actual" :nombre="$nombre" tamano="h-16 w-16 text-base" />
    @endif
    <div class="text-sm">
        <label for="{{ $id }}" class="fp-btn fp-btn-secundario cursor-pointer focus-within:ring-2 focus-within:ring-fp-primary">
            <span wire:loading.remove wire:target="{{ $modelo }}">{{ $actual || $archivo ? 'Cambiar foto' : 'Subir foto' }}</span>
            <span wire:loading wire:target="{{ $modelo }}">Subiendo…</span>
            <input id="{{ $id }}" type="file" wire:model="{{ $modelo }}" accept="image/jpeg,image/png,image/webp" class="sr-only">
        </label>
        @if ($archivo)
            <button type="button" wire:click="{{ $quitar }}" class="ml-2 text-xs font-medium text-slate-600 hover:text-fp-danger">Quitar</button>
        @endif
        <p class="mt-1 text-xs text-fp-text-muted">JPG, PNG o WebP, máximo 2 MB.</p>
        @error($modelo) <span class="text-fp-badge-danger-fg text-xs" role="alert">{{ $message }}</span> @enderror
    </div>
</div>
