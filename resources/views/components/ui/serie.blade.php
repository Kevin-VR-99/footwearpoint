{{-- TG-395 (A35) — Una serie en el tiempo: gráfica y su tabla, juntas.

     SIN LIBRERÍAS, y no por gusto: el CSP de la casa solo deja cargar
     scripts propios (script-src 'self'), así que un CDN de gráficas no
     arranca. Son columnas de CSS, igual que x-ui.barras.

     LA TABLA VIENE EN EL MISMO COMPONENTE A PROPÓSITO. Una gráfica no se
     puede leer con un lector de pantalla; la tabla es la única forma de que
     el dato llegue. Si fueran dos componentes, tarde o temprano alguien
     pondría la gráfica sola y nadie se daría cuenta hasta que fuera tarde.

     $puntos: lista de ['etiqueta' => string, 'valor' => float,
                        'sin_dato' => bool, 'en_curso' => bool]. --}}
@props([
    'titulo',
    'puntos' => [],
    'total' => null,
    'descripcion' => null,
    'nota' => null,
    'tendencia' => null,
    'agrupacion' => 'día',
    'tono' => 'primario',
    'columna' => 'Valor',
    'formato' => 'numero',
    'vacio' => 'Sin movimiento en este periodo.',
])

@php
    $clave = \Illuminate\Support\Str::slug($titulo);

    $barra = [
        'primario' => 'bg-fp-primary',
        'exito' => 'bg-fp-badge-success-fg',
        'oscuro' => 'bg-fp-sidebar',
    ][$tono] ?? 'bg-fp-primary';

    // El mismo formateador que usan las tarjetas de indicador, para que no se
    // pueda quedar una sola de las dos sin moneda.
    $texto = fn ($valor) => $formato === 'dinero'
        ? \App\Support\Dinero::mxn($valor)
        : number_format((float) $valor);

    $conDato = array_values(array_filter($puntos, fn ($p) => ! $p['sin_dato']));
    $max = $conDato === [] ? 0.0 : max(array_map(fn ($p) => (float) $p['valor'], $conDato));
    $hayMovimiento = $max > 0.0;

    $cumbre = null;
    foreach ($conDato as $p) {
        if ($cumbre === null || (float) $p['valor'] > (float) $cumbre['valor']) {
            $cumbre = $p;
        }
    }

    $huecos = array_filter($puntos, fn ($p) => $p['sin_dato']);

    // Lo que oye quien no ve la gráfica. Tiene que servir por sí solo: de ahí
    // que lleve el recorrido, el pico y de dónde a dónde va.
    // rtrim: las etiquetas de mes acaban en punto («22 nov.») y sin esto la
    // frase sale con dos.
    $frase = fn ($etiqueta) => rtrim((string) $etiqueta, '.');

    $resumen = $titulo.'. Gráfica de '.count($puntos).' tramos de '.$agrupacion
        .', de '.$frase($puntos[0]['etiqueta'] ?? '—').' a '.$frase($puntos[count($puntos) - 1]['etiqueta'] ?? '—').'. '
        .($hayMovimiento
            ? 'El tramo más alto es '.$frase($cumbre['etiqueta']).' con '.$texto($cumbre['valor']).'. '
            : 'Todos los tramos están en cero. ')
        .($huecos !== [] ? count($huecos).' tramos sin dato. ' : '')
        // En la gráfica el tramo a medias solo se distingue por ser más
        // claro, y eso no se oye.
        .(($puntos[count($puntos) - 1]['en_curso'] ?? false) ? 'El último tramo va en curso. ' : '')
        .'Los valores exactos están en la tabla que sigue.';
@endphp

<x-panel.tarjeta class="p-5 fp-aparecer" data-serie="{{ $clave }}">
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-slate-700">{{ $titulo }}</h3>
            @if ($total !== null)
                {{-- Sin partirse: «$45,357.00 MXN» en dos renglones se lee
                     como dos cifras distintas. --}}
                <p class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-fp-sidebar sm:text-2xl">
                    <x-ui.cifra :valor="(float) $total" :formato="$formato" :texto="$texto($total)" />
                </p>
            @endif
        </div>

        @if ($tendencia)
            {{-- La flecha y la palabra, no solo el color: con el color solo,
                 quien no distingue verde de rojo no se lleva nada (A21). --}}
            @php
                $sinBase = $tendencia['sin_comparacion'] !== null || $tendencia['porcentaje'] === null;
                $estilo = $sinBase
                    ? 'bg-slate-100 text-slate-600'
                    : ['sube' => 'bg-fp-badge-success-bg text-fp-badge-success-fg',
                       'baja' => 'bg-fp-danger-soft text-fp-danger',
                       'igual' => 'bg-slate-100 text-slate-600'][$tendencia['direccion']];
                $flecha = $sinBase ? '·' : ['sube' => '↑', 'baja' => '↓', 'igual' => '='][$tendencia['direccion']];
            @endphp
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $estilo }}"
                data-tendencia="{{ $sinBase ? 'sin-comparacion' : $tendencia['direccion'] }}">
                <span aria-hidden="true">{{ $flecha }}</span>
                @if ($sinBase)
                    Sin comparación
                @else
                    {{ ['sube' => 'Sube', 'baja' => 'Baja', 'igual' => 'Igual'][$tendencia['direccion']] }}
                    {{ number_format(abs($tendencia['porcentaje']), 1) }}%
                @endif
            </span>
        @endif
    </div>

    @if ($descripcion)
        <p class="mt-2 text-xs text-fp-text-muted">{{ $descripcion }}</p>
    @endif

    @if ($tendencia && $tendencia['sin_comparacion'] !== null)
        <p class="mt-2 text-xs text-fp-text-muted" data-sin-comparacion>{{ $tendencia['sin_comparacion'] }}</p>
    @elseif ($tendencia && $tendencia['porcentaje'] === null)
        <p class="mt-2 text-xs text-fp-text-muted" data-sin-comparacion>
            En el periodo anterior no hubo nada, así que no hay porcentaje que calcular.
        </p>
    @elseif ($tendencia)
        <p class="mt-2 text-xs text-fp-text-muted">
            El periodo anterior, del mismo largo, fue {{ $texto($tendencia['anterior']) }}.
        </p>
    @endif

    @if ($nota)
        <p class="mt-2 text-xs font-medium text-fp-text-muted" data-nota-serie>{{ $nota }}</p>
    @endif

    @if ($puntos === [] || (! $hayMovimiento && $huecos === []))
        <x-panel.vacio :mensaje="$vacio" class="mt-2" />
    @else
        <div class="mt-4 flex h-40 items-end gap-px sm:gap-0.5" role="img" aria-label="{{ $resumen }}">
            @foreach ($puntos as $punto)
                @if ($punto['sin_dato'])
                    <div class="h-full flex-1 rounded-sm border border-dashed border-slate-200 bg-slate-50"
                        title="{{ $punto['etiqueta'] }}: sin dato" data-punto="sin-dato"></div>
                @else
                    @php($alto = $hayMovimiento ? round(((float) $punto['valor'] / $max) * 100, 2) : 0)
                    <div class="flex h-full flex-1 flex-col justify-end"
                        title="{{ $punto['etiqueta'] }}: {{ $texto($punto['valor']) }}{{ $punto['en_curso'] ? ' (en curso)' : '' }}"
                        data-punto="{{ $punto['en_curso'] ? 'en-curso' : 'dato' }}">
                        <div class="fp-serie-columna rounded-t-sm {{ $barra }} {{ $punto['en_curso'] ? 'opacity-50' : '' }}"
                            style="height: {{ (float) $punto['valor'] > 0 ? max($alto, 2) : 0 }}%"></div>
                    </div>
                @endif
            @endforeach
        </div>

        <div class="mt-1.5 flex items-baseline justify-between text-[11px] text-fp-text-muted" aria-hidden="true">
            <span>{{ $puntos[0]['etiqueta'] }}</span>
            <span>{{ $puntos[count($puntos) - 1]['etiqueta'] }}</span>
        </div>

        @if ($huecos !== [])
            <p class="mt-2 text-[11px] text-fp-text-muted" data-leyenda-huecos>
                Los tramos punteados no son cero: de esos días ya no se guarda el detalle.
            </p>
        @endif
    @endif

    {{-- La alternativa tabular. Va plegada para que tres series no hagan una
         página de tres metros, pero está en el documento y se llega a ella
         con el teclado. --}}
    <details class="group mt-4 border-t border-slate-100 pt-3">
        <summary class="cursor-pointer text-xs font-semibold text-fp-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-fp-primary">
            Ver los datos en tabla
        </summary>

        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm" data-tabla-serie="{{ $clave }}">
                <caption class="sr-only">{{ $titulo }}, por {{ $agrupacion }}. Los mismos datos de la gráfica.</caption>
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-slate-600">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-semibold">Tramo</th>
                        <th scope="col" class="px-3 py-2 text-right font-semibold">{{ $columna }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($puntos as $punto)
                        <tr>
                            <th scope="row" class="px-3 py-1.5 text-left font-normal text-slate-700">
                                {{ $punto['etiqueta'] }}
                                @if ($punto['en_curso'])
                                    <span class="text-fp-text-muted">(en curso)</span>
                                @endif
                            </th>
                            <td class="px-3 py-1.5 text-right tabular-nums text-slate-700">
                                {{ $punto['sin_dato'] ? 'Sin dato' : $texto($punto['valor']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</x-panel.tarjeta>
