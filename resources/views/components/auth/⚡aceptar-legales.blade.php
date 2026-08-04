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

<div class="bg-white rounded-xl shadow-lg border border-slate-200 p-8">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-slate-900">Documentos legales</h1>
        <p class="text-sm text-slate-500 mt-1">Acepta los documentos para continuar</p>
    </div>

    @if ($mensaje)
        <div class="mb-4 rounded-lg bg-green-50 text-green-700 text-sm p-3">
            {{ $mensaje }}
        </div>
    @endif

    <form wire:submit="aceptar" class="space-y-5">
        {{-- Aviso de privacidad --}}
        <div class="rounded-lg border border-slate-200 overflow-hidden">
            <label class="flex items-start gap-3 p-4 hover:bg-slate-50 cursor-pointer">
                <input type="checkbox" wire:model="aviso"
                       class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-600">
                <span class="text-sm text-slate-700">
                    Acepto el <strong>Aviso de privacidad</strong> (versión 1.0)
                </span>
            </label>

            <details class="border-t border-slate-200 bg-slate-50">
                <summary class="px-4 py-2 text-sm text-blue-700 cursor-pointer hover:underline">
                    Ver aviso de privacidad completo
                </summary>
                <div class="px-4 pb-4 text-sm text-slate-600 space-y-2 max-h-48 overflow-y-auto">
                    <p><strong>Responsable:</strong> FootwearPoint y la distribuidora correspondiente.</p>
                    <p>Los datos personales recabados (nombre, correo, teléfono, dirección y datos de pedidos) se utilizan para:</p>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>Gestionar tu cuenta y pedidos de calzado.</li>
                        <li>Contactarte sobre el estado de tus órdenes (Click & Collect).</li>
                        <li>Cumplir obligaciones legales y de facturación cuando aplique.</li>
                    </ul>
                    <p>No se venden datos a terceros. Puedes solicitar acceso, rectificación o cancelación escribiendo a la distribuidora o a soporte de FootwearPoint.</p>
                    <p class="text-xs text-slate-400">Versión 1.0 — FootwearPoint MVP</p>
                </div>
            </details>
        </div>
        @error('aviso')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        {{-- Términos y condiciones --}}
        <div class="rounded-lg border border-slate-200 overflow-hidden">
            <label class="flex items-start gap-3 p-4 hover:bg-slate-50 cursor-pointer">
                <input type="checkbox" wire:model="terminos"
                       class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-600">
                <span class="text-sm text-slate-700">
                    Acepto los <strong>Términos y condiciones</strong> (versión 1.0)
                </span>
            </label>

            <details class="border-t border-slate-200 bg-slate-50">
                <summary class="px-4 py-2 text-sm text-blue-700 cursor-pointer hover:underline">
                    Ver términos y condiciones completos
                </summary>
                <div class="px-4 pb-4 text-sm text-slate-600 space-y-2 max-h-48 overflow-y-auto">
                    <p>Al usar FootwearPoint aceptas que:</p>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>La plataforma facilita pedidos a fábrica y recolección en sucursal (Click & Collect).</li>
                        <li>Los precios, anticipos y plazos los define cada distribuidora.</li>
                        <li>Los pedidos están sujetos a disponibilidad de fábrica y surtido parcial.</li>
                        <li>El anticipo mínimo y políticas de cambio/devolución se aplican según la configuración de la distribuidora.</li>
                        <li>FootwearPoint no es el vendedor final del calzado; actúa como plataforma tecnológica.</li>
                    </ul>
                    <p>El incumplimiento de pagos o mal uso de la cuenta puede derivar en suspensión del acceso.</p>
                    <p class="text-xs text-slate-400">Versión 1.0 — FootwearPoint MVP</p>
                </div>
            </details>
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-[#111E38] text-white font-medium py-2.5 hover:bg-[#1E2F52] transition"
                wire:loading.attr="disabled">
            <span wire:loading.remove>Aceptar documentos</span>
            <span wire:loading>Guardando...</span>
        </button>

        <div class="text-center">
            <a href="{{ route('dashboard') }}" class="text-sm text-blue-700 hover:underline">
                Volver al dashboard
            </a>
        </div>
    </form>
</div>