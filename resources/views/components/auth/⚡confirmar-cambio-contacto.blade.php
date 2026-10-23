<?php

use App\Models\CambioContactoPendiente;
use App\Services\Perfil\CambioContactoAction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * A esta página lleva el enlace para confirmar un correo o teléfono nuevo.
 * Como la de confirmar correo, no pide sesión: la protege la firma con
 * caducidad de la ruta ('signed'). Al terminar ofrece abrir la app o
 * descargarla.
 */
new #[Layout('layouts.guest')] #[Title('Confirmar cambio — FootwearPoint')] class extends Component
{
    public string $resultado = 'vencido';

    public string $tipo = 'email';

    public string $mensaje = '';

    public function mount(int $cambio): void
    {
        $registro = CambioContactoPendiente::find($cambio);

        if ($registro === null) {
            $this->mensaje = 'Este enlace no sirve. Puede que ya hayas pedido otro cambio después. Pídelo de nuevo desde la app.';

            return;
        }

        $this->tipo = $registro->tipo;
        $this->resultado = app(CambioContactoAction::class)->confirmar($registro);
        $que = $this->tipo === 'email' ? 'correo' : 'teléfono';

        $this->mensaje = match ($this->resultado) {
            'listo'     => $this->tipo === 'email'
                ? 'Listo, tu correo cambió a '.$registro->valor_nuevo.'. Desde ahora entras a la app con él.'
                : 'Listo, tu teléfono cambió a '.$registro->valor_nuevo.'.',
            'ya_estaba' => 'Este cambio ya estaba confirmado. No tienes que hacer nada más.',
            'ocupado'   => 'Ese correo ya lo usa otra cuenta, así que no se aplicó el cambio. Pide otro desde la app.',
            default     => 'Este enlace ya venció. Pide el cambio de '.$que.' otra vez desde la app.',
        };
    }
};
?>

<div class="fp-acceso-tarjeta p-8 text-center" data-confirmar-cambio="{{ $resultado }}">
    <div class="fp-acceso-logo mb-4 flex justify-center">
        <img src="{{ asset('brand/logo-full-160.png') }}" alt="Footwear Point" class="h-16 w-auto object-contain">
    </div>
    @if ($resultado === 'listo')
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-fp-badge-success-bg fp-emerger">
            <span class="text-3xl font-bold text-fp-badge-success-fg" aria-hidden="true">&check;</span>
        </div>
        <h1 class="text-2xl font-bold text-slate-900">{{ $tipo === 'email' ? 'Correo actualizado' : 'Teléfono actualizado' }}</h1>
    @elseif ($resultado === 'ya_estaba')
        <h1 class="text-2xl font-bold text-slate-900">Ya estaba confirmado</h1>
    @else
        <h1 class="text-2xl font-bold text-slate-900">No se pudo aplicar el cambio</h1>
    @endif

    <p class="mt-3 text-sm text-slate-600" role="status">{{ $mensaje }}</p>

    <x-app-movil.botones destino="perfil" />

    <p class="mt-4 text-xs text-slate-500">Si ya tienes la app, se abre en tu perfil. Si no, te llevamos a descargarla.</p>
</div>
