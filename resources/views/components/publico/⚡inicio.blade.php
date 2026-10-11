<?php

use App\Models\PlanSuscripcion;
use App\Support\PanelDelUsuario;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * TG-219 (E15-04) — La pagina publica de FootwearPoint, en "/".
 *
 * Antes "/" mandaba al login, asi que quien llegaba sin cuenta no encontraba
 * nada que leer. Aqui va que es FootwearPoint, como funciona, sus beneficios,
 * los planes con sus precios y el contacto.
 *
 * Los precios NO se escriben a mano: salen de planes_suscripcion, los que el
 * admin general tenga activos. Si manana cambia un precio en el panel, esta
 * pagina cambia sola.
 *
 * Ojo con el dominio: en un subdominio de distribuidora "/" NO llega aqui, lo
 * atiende antes el grupo Route::domain de TG-232. Esta pantalla es solo la del
 * dominio principal.
 */
new #[Layout('layouts.public', ['subtitulo' => 'Plataforma para distribuidoras de calzado'])]
#[Title('FootwearPoint — Plataforma para distribuidoras de calzado')]
class extends Component
{
    public function render()
    {
        // K14 todavia no existe. Cuando registre la ruta con ese nombre, el
        // boton apunta solo; mientras tanto baja a la seccion de contacto.
        $alta = Route::has('alta.solicitar') ? route('alta.solicitar') : '#contacto';

        return $this->view([
            'planes' => PlanSuscripcion::query()
                ->where('activo', true)
                ->orderBy('precio_base_mensual')
                ->get(),
            'alta' => $alta,
            'panel' => Auth::check() ? PanelDelUsuario::para(Auth::user()) : null,
            'contacto' => array_filter([
                'email' => config('footwearpoint.contacto.email'),
                'telefono' => config('footwearpoint.contacto.telefono'),
            ]),
        ]);
    }
};
?>

<div class="space-y-16">
    {{-- Qué es --}}
    <section class="text-center max-w-3xl mx-auto">
        <h2 class="text-3xl sm:text-4xl font-bold text-slate-900">
            Una distribuidora de calzado, ordenada de principio a fin
        </h2>
        <p class="mt-4 text-slate-600">
            FootwearPoint junta en un solo lugar el catálogo de tus fábricas, los pedidos de tus
            clientes y los de mostrador, los anticipos y el inventario de tus sucursales. Tus
            clientes piden desde su teléfono y recogen en tu tienda.
        </p>

        <div class="mt-8 flex flex-wrap justify-center gap-3">
            @if ($panel)
                <a href="{{ $panel['url'] }}"
                    class="px-5 py-2.5 rounded-lg bg-[#111E38] text-white font-medium hover:bg-[#1E2F52] transition">
                    {{ $panel['texto'] }}
                </a>
            @else
                <a href="{{ $alta }}"
                    class="px-5 py-2.5 rounded-lg bg-[#111E38] text-white font-medium hover:bg-[#1E2F52] transition">
                    Solicitar mi alta
                </a>
            @endif

            <a href="{{ route('marketplace') }}"
                class="px-5 py-2.5 rounded-lg border border-slate-300 bg-white text-slate-700 font-medium hover:bg-slate-50 transition">
                Ver marketplace
            </a>
        </div>
    </section>

    {{-- Cómo funciona --}}
    <section>
        <h3 class="text-2xl font-bold text-slate-900 text-center">Cómo funciona</h3>

        <ol class="mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
            @foreach ([
                ['Pides tu alta', 'Nos dices de tu distribuidora y eliges tu plan. Te damos tu cuenta y tu tienda.'],
                ['Eliges tus líneas', 'Del catálogo de FootwearPoint activas las marcas que tú vendes y pones tu precio de mayoreo.'],
                ['Recibes pedidos', 'Tus clientes y tus clientes mayoristas piden desde la app; tu personal captura en mostrador.'],
                ['Cobras y entregas', 'Cobras el anticipo, haces el pedido a fábrica y entregas en tu sucursal.'],
            ] as $i => [$titulo, $texto])
                <li class="bg-white rounded-xl border border-slate-200 p-5">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-[#EEF2FF] text-[#1E2F52] font-bold">
                        {{ $i + 1 }}
                    </span>
                    <h4 class="mt-3 font-semibold text-slate-900">{{ $titulo }}</h4>
                    <p class="mt-1 text-sm text-slate-600">{{ $texto }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Beneficios --}}
    <section>
        <h3 class="text-2xl font-bold text-slate-900 text-center">Qué ganas</h3>

        <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach ([
                ['Tu catálogo, siempre al día', 'El catálogo de fábrica lo mantiene FootwearPoint. Tú solo eliges qué vendes y a qué precio.'],
                ['Tu tienda en línea', 'Cada distribuidora tiene su tienda pública y sale en el directorio, sin montar nada.'],
                ['Pedidos sin enredos', 'El anticipo, el saldo y el estado de cada pedido quedan registrados y a la vista.'],
                ['Inventario por sucursal', 'Sabes qué hay en cada tienda y qué se vendió en mostrador.'],
                ['Tus precios son tuyos', 'El precio de mayoreo lo pone cada distribuidora; nadie ve el de las demás.'],
                ['Pagos en línea', 'Tus clientes pagan su anticipo con Mercado Pago desde la app.'],
            ] as [$titulo, $texto])
                <article class="bg-white rounded-xl border border-slate-200 p-5">
                    <h4 class="font-semibold text-slate-900">{{ $titulo }}</h4>
                    <p class="mt-1 text-sm text-slate-600">{{ $texto }}</p>
                </article>
            @endforeach
        </div>
    </section>

    {{-- Planes --}}
    <section id="planes">
        <h3 class="text-2xl font-bold text-slate-900 text-center">Planes</h3>
        <p class="mt-2 text-center text-sm text-slate-500">
            Precios en pesos mexicanos, por mes.
        </p>

        @if ($planes->isEmpty())
            <div class="mt-8 bg-white rounded-xl border border-slate-200 p-8 text-center text-slate-500">
                Estamos actualizando nuestros planes. Escríbenos y te decimos cuál te conviene.
            </div>
        @else
            <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach ($planes as $plan)
                    <article class="bg-white rounded-xl border border-slate-200 p-6 flex flex-col">
                        <h4 class="text-lg font-semibold text-slate-900">{{ $plan->nombre }}</h4>

                        <p class="mt-3">
                            <span class="text-3xl font-bold text-slate-900">
                                ${{ number_format((float) $plan->precio_base_mensual, 2) }}
                            </span>
                            <span class="text-sm text-slate-500">MXN al mes</span>
                        </p>

                        @if ($plan->descripcion)
                            <p class="mt-3 text-sm text-slate-600">{{ $plan->descripcion }}</p>
                        @endif

                        <ul class="mt-4 space-y-1.5 text-sm text-slate-600">
                            <li>{{ $plan->lineas_incluidas }} línea{{ $plan->lineas_incluidas == 1 ? '' : 's' }} incluida{{ $plan->lineas_incluidas == 1 ? '' : 's' }}</li>
                            <li>Línea extra: ${{ number_format((float) $plan->precio_linea_extra, 2) }} MXN al mes</li>
                        </ul>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Contacto --}}
    <section id="contacto" class="bg-white rounded-xl border border-slate-200 p-6 sm:p-8 text-center">
        <h3 class="text-2xl font-bold text-slate-900">¿Hablamos?</h3>
        <p class="mt-2 text-slate-600">
            Cuéntanos de tu distribuidora y te ayudamos a arrancar.
        </p>

        @if ($contacto)
            <ul class="mt-5 flex flex-wrap justify-center gap-x-8 gap-y-2 text-sm">
                @isset($contacto['email'])
                    <li>
                        <a href="mailto:{{ $contacto['email'] }}" class="text-[#2563EB] hover:underline">
                            {{ $contacto['email'] }}
                        </a>
                    </li>
                @endisset
                @isset($contacto['telefono'])
                    <li>
                        <a href="tel:{{ $contacto['telefono'] }}" class="text-[#2563EB] hover:underline">
                            {{ $contacto['telefono'] }}
                        </a>
                    </li>
                @endisset
            </ul>
        @endif

        @unless ($panel)
            <a href="{{ $alta }}"
                class="mt-6 inline-block px-5 py-2.5 rounded-lg bg-[#111E38] text-white font-medium hover:bg-[#1E2F52] transition">
                Solicitar mi alta
            </a>
        @endunless
    </section>
</div>
