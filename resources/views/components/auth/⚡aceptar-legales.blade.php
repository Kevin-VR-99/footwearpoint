<?php

use App\Models\AceptacionLegal;
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

        if ($this->aviso) {
            AceptacionLegal::firstOrCreate(
                [
                    'usuario_id'     => $usuario->id,
                    'tipo_documento' => 'aviso_privacidad',
                    'version'        => '1.0',
                ],
                [
                    'fecha_aceptacion' => now(),
                    'ip_origen'        => $ip,
                ]
            );
            $aceptadas[] = 'Aviso de privacidad';
        }

        if ($this->terminos) {
            AceptacionLegal::firstOrCreate(
                [
                    'usuario_id'     => $usuario->id,
                    'tipo_documento' => 'terminos_condiciones',
                    'version'        => '1.0',
                ],
                [
                    'fecha_aceptacion' => now(),
                    'ip_origen'        => $ip,
                ]
            );
            $aceptadas[] = 'Términos y condiciones';
        }

        $this->mensaje = 'Aceptaste: ' . implode(', ', $aceptadas) . '.';
        $this->reset(['aviso', 'terminos']);
    }
};
?>

<div class="fp-acceso-tarjeta p-8">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-slate-900">Documentos legales</h1>
        <p class="text-sm text-slate-500 mt-1">Acepta los documentos para continuar</p>
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
                    Acepto el <strong>Aviso de privacidad</strong> (versión 1.0)
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
                    Acepto los <strong>Términos y condiciones</strong> (versión 1.0)
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