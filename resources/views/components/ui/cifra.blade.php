{{-- TG-396 (A36) — Número que avanza hacia el valor real al llegar los datos.
     Sin scripts externos (CSP) y sin temporizadores de adorno: usa requestAnimationFrame.
     Con prefers-reduced-motion: reduce muestra el valor final de inmediato.

     La moneda solo se escribe cuando formato="dinero". Un conteo (usuarios
     activos) no debe llevar «$» ni «MXN» en ningún lado, ni siquiera dentro
     del código del recuento: si estuviera ahí, bastaría un error en la
     condición para que contara personas en pesos. --}}
@props([
    'valor' => 0,
    'formato' => 'numero',
    'texto' => '0',
])

@php
    $objetivo = is_numeric($valor) ? (float) $valor : 0.0;
    $esDinero = $formato === 'dinero';
    $decimales = $esDinero ? 2 : 0;
    $prefijo = $esDinero ? '$' : '';
    $sufijo = $esDinero ? ' '.\App\Support\Dinero::MONEDA : '';
    $llave = md5($formato.'|'.$objetivo.'|'.$texto);
@endphp

<span
    x-data="{
        objetivo: {{ json_encode($objetivo) }},
        decimales: {{ $decimales }},
        prefijo: {{ json_encode($prefijo) }},
        sufijo: {{ json_encode($sufijo) }},
        finalTexto: {{ json_encode($texto) }},
        mostrado: {{ json_encode($texto) }},
        iniciar() {
            if (! Number.isFinite(this.objetivo) || this.objetivo <= 0) {
                this.mostrado = this.finalTexto;
                return;
            }
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                this.mostrado = this.finalTexto;
                return;
            }
            const inicio = performance.now();
            const duracion = 360;
            const paso = (ahora) => {
                const progreso = Math.min((ahora - inicio) / duracion, 1);
                if (progreso >= 1) {
                    this.mostrado = this.finalTexto;
                    return;
                }
                const actual = this.objetivo * (1 - Math.pow(1 - progreso, 3));
                this.mostrado = this.prefijo
                    + actual.toLocaleString('en-US', { minimumFractionDigits: this.decimales, maximumFractionDigits: this.decimales })
                    + this.sufijo;
                requestAnimationFrame(paso);
            };
            requestAnimationFrame(paso);
        }
    }"
    x-init="iniciar()"
    wire:key="cifra-{{ $llave }}"
    data-cifra-animada
    data-formato="{{ $esDinero ? 'dinero' : 'numero' }}"
    data-valor-final="{{ $objetivo }}"
    aria-label="{{ $texto }}"
    x-text="mostrado"
>{{ $texto }}</span>
