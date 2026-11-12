<?php

use App\Support\DocumentosLegales;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.guest')] #[Title('Documentos legales — FootwearPoint')] class extends Component
{
    public bool $aviso = false;
    public bool $terminos = false;
    public string $mensaje = '';

    public function mount()
    {
        if (!Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }
    }

    public function aceptar()
    {
        $this->mensaje = '';

        if (!$this->aviso && !$this->terminos) {
            $this->addError('aviso', 'Debes aceptar al menos un documento.');
            return;
        }

        $usuario = Auth::user();
        $ip = request()->ip();
        $aceptadas = [];

        // TG-323: la versión la pone el servidor (la vigente de cada uno).
        foreach (['aviso' => DocumentosLegales::AVISO, 'terminos' => DocumentosLegales::TERMINOS] as $casilla => $tipo) {
            if ($this->{$casilla}) {
                DocumentosLegales::registrar($usuario, $tipo, $ip);
                $aceptadas[] = DocumentosLegales::nombre($tipo);
            }
        }

        $this->mensaje = 'Aceptaste: ' . implode(', ', $aceptadas) . '.';
        $this->reset(['aviso', 'terminos']);

        // Si llegó porque le faltaba aceptar algo y ya no falta nada, sigue
        // a su panel.
        if (DocumentosLegales::alCorriente($usuario)) {
            $this->redirect(route('dashboard'), navigate: true);
        }
    }

    /** @return list<string> */
    public function pendientes(): array
    {
        return DocumentosLegales::pendientes(Auth::user());
    }
};
?>

<div class="fp-acceso-tarjeta p-8">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-slate-900">Documentos legales</h1>
        <p class="text-sm text-slate-600 mt-1">Acepta los documentos para continuar</p>
        @if ($this->pendientes() !== [])
            <p class="mt-3 rounded-lg bg-fp-badge-warning-bg px-3 py-2 text-sm text-fp-badge-warning-fg" role="status" data-legales-pendientes>
                Actualizamos {{ count($this->pendientes()) === 2 ? 'los dos documentos' : 'un documento' }}. Para seguir usando FootwearPoint, lee y acepta la versión nueva.
            </p>
        @endif
    </div>

    @if ($mensaje)
        <div class="fp-emerger mb-4 rounded-xl border border-fp-badge-success-fg/20 bg-fp-badge-success-bg p-3 text-sm text-fp-badge-success-fg" role="status">
            {{ $mensaje }}
        </div>
    @endif

    <form wire:submit="aceptar" class="space-y-5">
        {{-- Aviso de privacidad --}}
        <div class="rounded-lg border border-slate-200 overflow-hidden">
            <label class="flex items-start gap-3 p-4 hover:bg-slate-50 cursor-pointer">
                <input type="checkbox" wire:model="aviso"
                       class="mt-1 rounded text-blue-600 focus:ring-blue-600">
                <span class="text-sm text-slate-700">
                    Acepto el <strong>Aviso de privacidad</strong> (versión {{ \App\Support\DocumentosLegales::version('aviso_privacidad') }}, {{ \App\Support\DocumentosLegales::fecha('aviso_privacidad') }})
                    @if (in_array('aviso_privacidad', $this->pendientes(), true))
                        <span class="ml-1 rounded-full bg-fp-badge-warning-bg px-2 py-0.5 text-xs font-medium text-fp-badge-warning-fg">Por aceptar</span>
                    @endif
                </span>
            </label>

            <details class="border-t border-slate-200 bg-slate-50">
                <summary class="px-4 py-2 fp-enlace cursor-pointer text-sm">
                    Ver aviso de privacidad completo
                </summary>
                <div class="px-4 pb-4 text-sm text-slate-600 space-y-2 max-h-48 overflow-y-auto">
                    {{-- TG-219: el texto vive en el parcial, compartido con /aviso-privacidad. --}}
                    <x-legales.aviso-privacidad />
                </div>
            </details>
        </div>
        @error('aviso')
            <p class="text-sm text-fp-danger">{{ $message }}</p>
        @enderror

        {{-- Términos y condiciones --}}
        <div class="rounded-lg border border-slate-200 overflow-hidden">
            <label class="flex items-start gap-3 p-4 hover:bg-slate-50 cursor-pointer">
                <input type="checkbox" wire:model="terminos"
                       class="mt-1 rounded text-blue-600 focus:ring-blue-600">
                <span class="text-sm text-slate-700">
                    Acepto los <strong>Términos y condiciones</strong> (versión {{ \App\Support\DocumentosLegales::version('terminos_condiciones') }}, {{ \App\Support\DocumentosLegales::fecha('terminos_condiciones') }})
                    @if (in_array('terminos_condiciones', $this->pendientes(), true))
                        <span class="ml-1 rounded-full bg-fp-badge-warning-bg px-2 py-0.5 text-xs font-medium text-fp-badge-warning-fg">Por aceptar</span>
                    @endif
                </span>
            </label>

            <details class="border-t border-slate-200 bg-slate-50">
                <summary class="px-4 py-2 fp-enlace cursor-pointer text-sm">
                    Ver términos y condiciones completos
                </summary>
                <div class="px-4 pb-4 text-sm text-slate-600 space-y-2 max-h-48 overflow-y-auto">
                    {{-- TG-219: el texto vive en el parcial, compartido con /terminos. --}}
                    <x-legales.terminos />
                </div>
            </details>
        </div>

        <button type="submit"
                class="fp-btn fp-btn-oscuro w-full py-2.5"
                wire:loading.attr="disabled">
            <span wire:loading.remove>Aceptar documentos</span>
            <span wire:loading>Guardando...</span>
        </button>

        <div class="text-center">
            <a href="{{ route('dashboard') }}" class="fp-enlace text-sm">
                Volver al dashboard
            </a>
        </div>
    </form>
</div>