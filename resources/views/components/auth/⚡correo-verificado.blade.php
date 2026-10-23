<?php

use App\Models\Usuario;
use Illuminate\Auth\Events\Verified;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * TG-189 (A11 / E1-03) — La página a la que lleva el enlace del correo.
 *
 * NO pide iniciar sesión, a propósito. Quien recibe este correo casi siempre
 * es un cliente mayorista o un cliente, que usan la app y no el panel web; si
 * se les pidiera entrar aquí, el enlace no les serviría de nada.
 *
 * Lo que la protege es la firma: Laravel firma la dirección con la llave de
 * la aplicación y le pone caducidad, y el enlace lleva además un hash del
 * correo. Cambiar el número, el correo o la fecha invalida la firma y la ruta
 * ni siquiera llega aquí.
 */
new #[Layout('layouts.guest')] #[Title('Confirmar correo — FootwearPoint')] class extends Component
{
    public string $resultado = 'invalido';

    public string $mensaje = '';

    public function mount(int $id, string $hash): void
    {
        $usuario = Usuario::find($id);

        // Mismo mensaje exista o no la cuenta: un enlace manipulado no sirve
        // para averiguar qué correos están registrados.
        if ($usuario === null || ! hash_equals($hash, sha1($usuario->getEmailForVerification()))) {
            $this->mensaje = 'Este enlace no sirve. Puede que esté incompleto o que ya haya vencido. Pide uno nuevo desde la app.';

            return;
        }

        if ($usuario->hasVerifiedEmail()) {
            $this->resultado = 'ya_estaba';
            $this->mensaje = 'Tu correo ya estaba confirmado. No tienes que hacer nada más.';

            return;
        }

        $usuario->markEmailAsVerified();

        event(new Verified($usuario));

        $this->resultado = 'listo';
        $this->mensaje = 'Listo, tu correo quedó confirmado. Ya puedes hacer pedidos desde la app.';
    }
};
?>

<div class="fp-acceso-tarjeta p-8 text-center">
    <div class="fp-acceso-logo mb-4 flex justify-center">
        <img src="{{ asset('brand/logo-full-160.png') }}" alt="Footwear Point" class="h-16 w-auto object-contain">
    </div>
    @if ($resultado === 'listo')
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-fp-badge-success-bg fp-emerger">
            <span class="text-3xl font-bold text-fp-badge-success-fg" aria-hidden="true">&check;</span>
        </div>
        <h1 class="text-2xl font-bold text-slate-900">Correo confirmado</h1>
    @elseif ($resultado === 'ya_estaba')
        <h1 class="text-2xl font-bold text-slate-900">Ya estaba confirmado</h1>
    @else
        <h1 class="text-2xl font-bold text-slate-900">Ese enlace no sirve</h1>
    @endif

    <p class="mt-3 text-sm text-slate-600">{{ $mensaje }}</p>

    @if ($resultado !== 'invalido')
        {{-- Abre la app si está instalada; si no, lleva a descargarla. --}}
        <x-app-movil.botones destino="perfil" />
    @endif

    <p class="mt-6 text-sm text-slate-500">Puedes cerrar esta página y volver a la app.</p>

    <a href="{{ route('login') }}" class="mt-4 inline-block fp-enlace text-sm">
        Ir al inicio de sesión
    </a>
</div>
