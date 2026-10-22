@props(['usuario' => null])

@if ($usuario)
    <div class="mt-1">
        @if ($usuario->hasVerifiedEmail())
            <x-panel.insignia variante="success" punto>Correo confirmado</x-panel.insignia>
        @else
            <x-panel.insignia variante="warning" punto>Correo sin confirmar</x-panel.insignia>
        @endif
    </div>
@endif
