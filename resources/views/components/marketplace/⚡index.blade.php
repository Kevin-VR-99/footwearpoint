<?php

use App\Models\Distribuidora;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Marketplace — FootwearPoint')] class extends Component
{
    public function render()
    {
        $distribuidoras = Distribuidora::query()
            ->where('estado', 'activa')
            ->where('marketplace_visible', true)
            ->orderBy('nombre_comercial')
            ->get();

        return $this->view([
            'distribuidoras' => $distribuidoras,
        ]);
    }
};
?>

<div>
    <div class="mb-8">
        <h2 class="text-2xl font-bold text-slate-900">Distribuidoras</h2>
        <p class="text-sm text-slate-500 mt-1">
            Encuentra distribuidoras de calzado afiliadas a FootwearPoint.
        </p>
    </div>

    @if ($distribuidoras->isEmpty())
        <div class="bg-white rounded-xl border border-slate-200 p-10 text-center text-slate-500">
            No hay distribuidoras visibles en el marketplace por ahora.
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach ($distribuidoras as $d)
                <article class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                    <div class="h-28 bg-[#1E2F52] flex items-center justify-center">
                        @if ($d->logotipo_url)
                            <img src="{{ $d->logotipo_url }}"
                                 alt="Logo {{ $d->nombre_comercial }}"
                                 class="max-h-20 max-w-[80%] object-contain">
                        @else
                            <span class="text-white/80 text-lg font-semibold px-4 text-center">
                                {{ $d->nombre_comercial }}
                            </span>
                        @endif
                    </div>

                    <div class="p-5 flex-1 flex flex-col">
                        <h3 class="text-lg font-semibold text-slate-900">
                            {{ $d->nombre_comercial }}
                        </h3>

                        @if ($d->descripcion_publica)
                            <p class="text-sm text-slate-600 mt-2 line-clamp-3">
                                {{ $d->descripcion_publica }}
                            </p>
                        @endif

                        <ul class="mt-4 space-y-1.5 text-sm text-slate-600">
                            @if ($d->direccion_publica)
                                <li class="flex gap-2">
                                    <span class="text-slate-400 shrink-0">📍</span>
                                    <span>{{ $d->direccion_publica }}</span>
                                </li>
                            @endif
                            @if ($d->telefono_publico)
                                <li class="flex gap-2">
                                    <span class="text-slate-400 shrink-0">📞</span>
                                    <a href="tel:{{ $d->telefono_publico }}" class="text-[#2563EB] hover:underline">
                                        {{ $d->telefono_publico }}
                                    </a>
                                </li>
                            @endif
                            @if ($d->email_publico)
                                <li class="flex gap-2">
                                    <span class="text-slate-400 shrink-0">✉️</span>
                                    <a href="mailto:{{ $d->email_publico }}" class="text-[#2563EB] hover:underline">
                                        {{ $d->email_publico }}
                                    </a>
                                </li>
                            @endif
                            @if ($d->horario_publico)
                                <li class="flex gap-2">
                                    <span class="text-slate-400 shrink-0">🕐</span>
                                    <span>{{ $d->horario_publico }}</span>
                                </li>
                            @endif
                        </ul>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>