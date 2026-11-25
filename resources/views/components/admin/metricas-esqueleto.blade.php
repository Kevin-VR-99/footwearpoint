{{-- TG-396 (A36) — Skeleton local para Métricas mientras consulta.
     Reutiliza x-ui.esqueleto y .fp-esqueleto sin inventar cifras ni ceros. --}}
@props(['tipo' => 'indicadores'])

@if ($tipo === 'indicadores')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" role="status" aria-label="Cargando indicadores" data-metricas-esqueleto="indicadores">
        <span class="sr-only">Cargando indicadores…</span>
        @for ($i = 0; $i < 4; $i++)
            <x-panel.tarjeta class="p-5 space-y-3" aria-hidden="true">
                <div class="fp-esqueleto h-4 w-28"></div>
                <div class="fp-esqueleto h-8 w-36"></div>
                <x-ui.esqueleto :filas="2" aria-hidden="true" />
            </x-panel.tarjeta>
        @endfor
    </div>
@else
    <div class="space-y-4" role="status" aria-label="Cargando gráficas" data-metricas-esqueleto="series">
        <span class="sr-only">Cargando gráficas…</span>
        <div class="space-y-2" aria-hidden="true">
            <div class="fp-esqueleto h-4 w-48"></div>
            <div class="fp-esqueleto h-3 w-80 max-w-full"></div>
        </div>
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3" aria-hidden="true">
            @for ($i = 0; $i < 3; $i++)
                <x-panel.tarjeta class="p-5 space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="space-y-2 flex-1">
                            <div class="fp-esqueleto h-4 w-32"></div>
                            <div class="fp-esqueleto h-7 w-28"></div>
                        </div>
                        <div class="fp-esqueleto h-6 w-20 rounded-full"></div>
                    </div>
                    <div class="fp-esqueleto h-3 w-4/5"></div>
                    <div class="mt-4 flex h-40 items-end gap-1.5">
                        @foreach ([38, 62, 48, 82, 54, 70, 90, 64, 44, 76] as $alto)
                            <div class="fp-esqueleto flex-1 rounded-t-sm" style="height: {{ $alto }}%"></div>
                        @endforeach
                    </div>
                    <x-ui.esqueleto :filas="2" class="border-t border-slate-100 pt-3" aria-hidden="true" />
                </x-panel.tarjeta>
            @endfor
        </div>
    </div>
@endif
