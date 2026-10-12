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
                // TG-234: si el correo ya sale como icono de sobre, no se repite como texto.
                'email' => config('footwearpoint.contacto.email') !== config('footwearpoint.redes.correo')
                    ? config('footwearpoint.contacto.email')
                    : null,
                'telefono' => config('footwearpoint.contacto.telefono'),
            ]),
            // TG-234: icono oculto si la URL está vacía.
            'redes' => array_filter((array) config('footwearpoint.redes', [])),
        ]);
    }
};
?>

<div class="space-y-20">
    {{-- Qué es (hero) --}}
    <section class="relative -mt-2 overflow-hidden rounded-3xl bg-gradient-to-br from-fp-sidebar via-fp-sidebar to-fp-accent px-6 py-14 text-center text-white shadow-2xl shadow-fp-sidebar/25 sm:px-12 sm:py-20" data-revelar>
        <div class="absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-fp-primary via-white/40 to-fp-danger"></div>
        <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-fp-primary/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-24 -left-24 h-72 w-72 rounded-full bg-fp-danger/10 blur-3xl"></div>
        <div class="relative mx-auto max-w-3xl">
            <img src="{{ asset('brand/logo-mark-white-64.png') }}" alt="" class="mx-auto mb-6 h-16 w-16 rounded-full ring-4 ring-white/10" width="64" height="64">
            <h2 class="text-3xl font-bold tracking-tight sm:text-5xl">
                Una distribuidora de calzado, ordenada de principio a fin
            </h2>
            <p class="mt-5 text-white/75 sm:text-lg">
                FootwearPoint junta en un solo lugar el catálogo de tus fábricas, los pedidos de tus
                clientes y los de mostrador, los anticipos y el inventario de tus sucursales. Tus
                clientes piden desde su teléfono y recogen en tu tienda.
            </p>

            <div class="mt-9 flex flex-wrap justify-center gap-3">
                @if ($panel)
                    <a href="{{ $panel['url'] }}" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-fp-primary to-fp-accent px-6 py-3 font-semibold text-white shadow-lg shadow-fp-primary/30 transition duration-300 hover:-translate-y-0.5 hover:from-fp-accent hover:to-fp-primary hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60">
                        {{ $panel['texto'] }}
                    </a>
                @else
                    <a href="{{ $alta }}" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-fp-primary to-fp-accent px-6 py-3 font-semibold text-white shadow-lg shadow-fp-primary/30 transition duration-300 hover:-translate-y-0.5 hover:from-fp-accent hover:to-fp-primary hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60">
                        Solicitar mi alta
                    </a>
                @endif

                <a href="{{ route('marketplace') }}"
                    class="inline-flex items-center justify-center rounded-xl border border-white/25 bg-white/5 px-6 py-3 font-semibold text-white backdrop-blur transition duration-300 hover:-translate-y-0.5 hover:border-white/50 hover:bg-white hover:text-fp-sidebar">
                    Ver marketplace
                </a>
            </div>
        </div>
    </section>

    {{-- Cómo funciona --}}
    <section>
        <div class="text-center" data-revelar>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-fp-primary">Paso a paso</p>
            <h3 class="mt-1 text-2xl font-bold text-fp-sidebar sm:text-3xl">Cómo funciona</h3>
        </div>

        <ol class="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Pides tu alta', 'Nos dices de tu distribuidora y eliges tu plan. Te damos tu cuenta y tu tienda.'],
                ['Eliges tus líneas', 'Del catálogo de FootwearPoint activas las líneas que vendes y pones tu precio de mayoreo.'],
                ['Recibes pedidos', 'Tus clientes y tus clientes mayoristas piden desde la app; tu personal captura en mostrador.'],
                ['Cobras y entregas', 'Cobras el anticipo, haces el pedido a fábrica y entregas en tu sucursal.'],
            ] as $i => [$titulo, $texto])
                <li class="group rounded-2xl border border-slate-200/80 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:border-fp-primary/30 hover:shadow-xl hover:shadow-fp-sidebar/10 relative p-6" data-revelar style="--retraso: {{ $i * 100 }}ms">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-fp-badge-info-bg font-bold text-fp-badge-info-fg transition duration-300 group-hover:bg-gradient-to-br group-hover:from-fp-primary group-hover:to-fp-accent group-hover:text-white">
                        {{ $i + 1 }}
                    </span>
                    <h4 class="mt-4 font-semibold text-fp-sidebar">{{ $titulo }}</h4>
                    <p class="mt-1 text-sm text-slate-600">{{ $texto }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Beneficios --}}
    <section>
        <div class="text-center" data-revelar>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-fp-primary">Beneficios</p>
            <h3 class="mt-1 text-2xl font-bold text-fp-sidebar sm:text-3xl">Qué ganas</h3>
        </div>

        <div class="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['Tu catálogo, siempre al día', 'El catálogo de fábrica lo mantiene FootwearPoint. Tú solo eliges qué vendes y a qué precio.'],
                ['Tu tienda en línea', 'Cada distribuidora tiene su tienda pública y sale en el directorio, sin montar nada.'],
                ['Pedidos sin enredos', 'El anticipo, el saldo y el estado de cada pedido quedan registrados y a la vista.'],
                ['Inventario por sucursal', 'Sabes qué hay en cada tienda y qué se vendió en mostrador.'],
                ['Tus precios son tuyos', 'El precio de mayoreo lo pone cada distribuidora; nadie ve el de las demás.'],
                ['Pagos en línea', 'Tus clientes pagan su anticipo con Mercado Pago desde la app.'],
            ] as $i => [$titulo, $texto])
                <article class="group rounded-2xl border border-slate-200/80 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:border-fp-primary/30 hover:shadow-xl hover:shadow-fp-sidebar/10 p-6" data-revelar style="--retraso: {{ ($i % 3) * 100 }}ms">
                    <span class="mb-4 block h-1 w-10 rounded-full bg-gradient-to-r from-fp-primary to-fp-accent transition-all duration-300 group-hover:w-16"></span>
                    <h4 class="font-semibold text-fp-sidebar">{{ $titulo }}</h4>
                    <p class="mt-1 text-sm text-slate-600">{{ $texto }}</p>
                </article>
            @endforeach
        </div>
    </section>

    {{-- Planes --}}
    <section id="planes">
        <div class="text-center" data-revelar>
            <h3 class="text-2xl font-bold text-fp-sidebar sm:text-3xl">Planes</h3>
            <p class="mt-2 text-sm text-fp-text-muted">
                Precios en pesos mexicanos, por mes.
            </p>
        </div>

        @if ($planes->isEmpty())
            <div class="mt-8 rounded-2xl border border-slate-200/80 bg-white p-8 text-center text-slate-500 shadow-sm" data-revelar>
                Estamos actualizando nuestros planes. Escríbenos y te decimos cuál te conviene.
            </div>
        @else
            <div class="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($planes as $plan)
                    <article class="group rounded-2xl border border-slate-200/80 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:border-fp-primary/30 hover:shadow-xl hover:shadow-fp-sidebar/10 flex flex-col overflow-hidden" data-revelar style="--retraso: {{ ($loop->index % 3) * 100 }}ms">
                        <div class="h-1 bg-gradient-to-r from-fp-primary via-fp-accent to-fp-sidebar"></div>
                        <div class="flex flex-1 flex-col p-6">
                        <h4 class="text-lg font-semibold text-fp-sidebar">{{ $plan->nombre }}</h4>

                        <p class="mt-3">
                            <span class="text-3xl font-bold text-fp-sidebar transition-colors duration-300 group-hover:text-fp-primary">
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
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Contacto --}}
    <section id="contacto" class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-fp-sidebar to-fp-accent p-8 text-center text-white shadow-xl shadow-fp-sidebar/20 sm:p-12" data-revelar>
        <div class="absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-fp-primary via-white/40 to-fp-danger"></div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-white/50">Contáctanos</p>
        <h3 class="mt-1 text-2xl font-bold sm:text-3xl">¿Hablamos?</h3>
        <p class="mt-2 text-white/70">
            Cuéntanos de tu distribuidora y te ayudamos a arrancar.
        </p>

        @if ($contacto)
            <ul class="mt-6 flex flex-wrap justify-center gap-x-8 gap-y-2 text-sm">
                @isset($contacto['email'])
                    <li>
                        <a href="mailto:{{ $contacto['email'] }}" class="text-white/90 underline-offset-4 transition-colors hover:text-white hover:underline">
                            {{ $contacto['email'] }}
                        </a>
                    </li>
                @endisset
                @isset($contacto['telefono'])
                    <li>
                        <a href="tel:{{ $contacto['telefono'] }}" class="text-white/90 underline-offset-4 transition-colors hover:text-white hover:underline">
                            {{ $contacto['telefono'] }}
                        </a>
                    </li>
                @endisset
            </ul>
        @endif

        {{-- TG-234: redes sociales desde config('footwearpoint.redes'); sin URL no se muestra el icono. --}}
        @if ($redes)
            <ul class="mt-6 flex justify-center gap-3" aria-label="Redes sociales">
                @foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'youtube' => 'YouTube'] as $red => $nombreRed)
                    @isset($redes[$red])
                        <li>
                            <a href="{{ $redes[$red] }}" target="_blank" rel="noopener" aria-label="FootwearPoint en {{ $nombreRed }}" data-red="{{ $red }}"
                                class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white ring-1 ring-white/20 transition duration-300 hover:-translate-y-0.5 hover:bg-white hover:text-fp-sidebar hover:shadow-lg">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    @if ($red === 'instagram')
                                        <path d="M12 2.2c3.2 0 3.6 0 4.8.1 1.2.1 1.8.2 2.2.4.6.2 1 .5 1.4.9.4.4.7.8.9 1.4.2.4.4 1.1.4 2.2.1 1.3.1 1.6.1 4.8s0 3.6-.1 4.8c-.1 1.2-.2 1.8-.4 2.2-.2.6-.5 1-.9 1.4-.4.4-.8.7-1.4.9-.4.2-1.1.4-2.2.4-1.3.1-1.6.1-4.8.1s-3.6 0-4.8-.1c-1.2-.1-1.8-.2-2.2-.4-.6-.2-1-.5-1.4-.9-.4-.4-.7-.8-.9-1.4-.2-.4-.4-1.1-.4-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.8c.1-1.2.2-1.8.4-2.2.2-.6.5-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.2 1.1-.4 2.2-.4C8.4 2.2 8.8 2.2 12 2.2zm0 1.8c-3.1 0-3.5 0-4.7.1-1.1.1-1.7.2-2.1.4-.5.2-.9.4-1.3.8-.4.4-.6.8-.8 1.3-.2.4-.3 1-.4 2.1C2.6 9.9 2.6 10.3 2.6 12s0 2.1.1 3.3c.1 1.1.2 1.7.4 2.1.2.5.4.9.8 1.3.4.4.8.6 1.3.8.4.2 1 .3 2.1.4 1.2.1 1.6.1 4.7.1s3.5 0 4.7-.1c1.1-.1 1.7-.2 2.1-.4.5-.2.9-.4 1.3-.8.4-.4.6-.8.8-1.3.2-.4.3-1 .4-2.1.1-1.2.1-1.6.1-3.3s0-2.1-.1-3.3c-.1-1.1-.2-1.7-.4-2.1-.2-.5-.4-.9-.8-1.3-.4-.4-.8-.6-1.3-.8-.4-.2-1-.3-2.1-.4C15.5 4 15.1 4 12 4zm0 3.1a4.9 4.9 0 110 9.8 4.9 4.9 0 010-9.8zm0 8a3.1 3.1 0 100-6.2 3.1 3.1 0 000 6.2zm5.1-8.2a1.1 1.1 0 11-2.3 0 1.1 1.1 0 012.3 0z"/>
                                    @elseif ($red === 'facebook')
                                        <path d="M22 12a10 10 0 10-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.3v7A10 10 0 0022 12z"/>
                                    @else
                                        <path d="M23.5 6.2a3 3 0 00-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 00.5 6.2 31 31 0 000 12a31 31 0 00.5 5.8 3 3 0 002.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 002.1-2.1A31 31 0 0024 12a31 31 0 00-.5-5.8zM9.6 15.6V8.4l6.3 3.6-6.3 3.6z"/>
                                    @endif
                                </svg>
                            </a>
                        </li>
                    @endisset
                @endforeach
                {{-- TG-234: correo como icono redondo, mismo estilo que las redes. --}}
                @isset($redes['correo'])
                    <li>
                        <a href="mailto:{{ $redes['correo'] }}" aria-label="Escríbenos a {{ $redes['correo'] }}" title="{{ $redes['correo'] }}" data-red="correo"
                            class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white ring-1 ring-white/20 transition duration-300 hover:-translate-y-0.5 hover:bg-white hover:text-fp-sidebar hover:shadow-lg">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75A1.75 1.75 0 014.75 5h14.5A1.75 1.75 0 0121 6.75v10.5A1.75 1.75 0 0119.25 19H4.75A1.75 1.75 0 013 17.25V6.75z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 7l8.5 6 8.5-6" />
                            </svg>
                        </a>
                    </li>
                @endisset
            </ul>
        @endif

        @unless ($panel)
            <a href="{{ $alta }}" class="mt-8 inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-fp-primary to-fp-accent px-6 py-3 font-semibold text-white shadow-lg shadow-fp-primary/30 transition duration-300 hover:-translate-y-0.5 hover:from-fp-accent hover:to-fp-primary hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60">
                Solicitar mi alta
            </a>
        @endunless
    </section>
</div>
