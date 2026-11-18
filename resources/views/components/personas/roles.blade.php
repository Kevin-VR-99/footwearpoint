{{-- TG-335: roles de personal de una cuenta, cada uno con su distribuidora. --}}
@props(['roles' => []])

<ul {{ $attributes->class(['space-y-1']) }}>
    @forelse ($roles as $rol)
        <li class="flex flex-wrap items-center gap-1.5" data-rol="{{ $rol['rol'] }}">
            <x-panel.insignia :variante="$rol['rol'] === \App\Support\PersonalPlataforma::ADMIN_GENERAL ? 'info' : 'neutral'">{{ $rol['etiqueta'] }}</x-panel.insignia>
            @if ($rol['distribuidora'])
                <span class="text-xs text-slate-600">{{ $rol['distribuidora'] }}</span>
            @endif
            @unless ($rol['activo'])
                <x-panel.insignia variante="aviso">De baja aquí</x-panel.insignia>
            @endunless
        </li>
    @empty
        <li class="text-xs text-slate-500">Sin roles</li>
    @endforelse
</ul>
