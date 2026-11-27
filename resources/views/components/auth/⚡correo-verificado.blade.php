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

<div class="fp-acceso-tarjeta p-6 sm:p-8 text-center">
    <x-auth.encabezado class="mb-5" />
    {{-- TG-390 (A30) — Los tres estados, con el mismo trato.

         Antes solo el exito traia circulo y color: los otros dos eran un
         titulo suelto. Quien abre el enlace y le sale "Ya estaba confirmado"
         no sabia si eso era bueno o un problema, porque la pantalla no se lo
         decia por ningun otro medio que el texto.

         NO SE AGREGAN ESTADOS. La tarjeta dice "estados existentes" y "sin
         ampliar funciones": siguen siendo los mismos tres que decide mount().

         El icono va con aria-hidden y el estado se anuncia con role="status":
         una palomita no se lee en voz alta, y el color tampoco. --}}
    @php
        $estados = [
            'listo' => ['titulo' => 'Correo confirmado', 'icono' => '&check;', 'fondo' => 'bg-fp-badge-success-bg', 'texto' => 'text-fp-badge-success-fg'],
            'ya_estaba' => ['titulo' => 'Ya estaba confirmado', 'icono' => '&check;', 'fondo' => 'bg-slate-100', 'texto' => 'text-slate-500'],
            'invalido' => ['titulo' => 'Ese enlace no sirve', 'icono' => '&times;', 'fondo' => 'bg-fp-danger-soft', 'texto' => 'text-fp-danger'],
        ];
        $estado = $estados[$resultado] ?? $estados['invalido'];
    @endphp

    <div role="status" data-estado-confirmacion="{{ $resultado }}">
        <div class="fp-emerger mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full {{ $estado['fondo'] }}">
            <span class="text-3xl font-bold {{ $estado['texto'] }}" aria-hidden="true">{!! $estado['icono'] !!}</span>
        </div>
        <h1 class="text-2xl font-bold text-slate-900">{{ $estado['titulo'] }}</h1>
    </div>

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
