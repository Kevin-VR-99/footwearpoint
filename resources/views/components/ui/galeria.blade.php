{{-- TG-385 A25 — Galería reutilizable de un producto.
     0 fotos: hueco estándar. 1 foto: solo portada (sin selector).
     2+ fotos: portada (4/3, object-contain) y miniaturas para cambiarla
     con clic o teclado, ARIA y prefers-reduced-motion. --}}
@props([
    'imagenes' => [],
    'alt' => 'Producto',
    'modal' => 'chico',
    'clasesMiniaturas' => '',
])

@php
    $fotos = collect($imagenes)
        ->map(function ($item) use ($alt) {
            if ($item instanceof \App\Models\ProductoImagen) {
                $url = trim((string) $item->url);
                $miniatura = trim((string) $item->urlMiniatura());
                $mediana = trim((string) $item->urlMediana());
                $etiqueta = $alt;
            } elseif (is_array($item)) {
                $url = trim((string) ($item['url'] ?? $item['mediana'] ?? $item['miniatura'] ?? ''));
                $miniatura = trim((string) ($item['miniatura'] ?? $item['url_miniatura'] ?? $url));
                $mediana = trim((string) ($item['mediana'] ?? $item['url_mediana'] ?? $url));
                $etiqueta = trim((string) ($item['alt'] ?? '')) ?: $alt;
            } else {
                $url = trim((string) $item);
                $miniatura = $url;
                $mediana = $url;
                $etiqueta = $alt;
            }

            $portadaUrl = $mediana !== '' ? $mediana : ($url !== '' ? $url : $miniatura);
            $thumbUrl = $miniatura !== '' ? $miniatura : $portadaUrl;

            if ($portadaUrl === '') {
                return null;
            }

            return [
                'portada' => $portadaUrl,
                'miniatura' => $thumbUrl,
                'alt' => $etiqueta,
            ];
        })
        ->filter()
        ->values();
@endphp

@if ($fotos->isEmpty())
    <div {{ $attributes }} data-galeria-vacia>
        <x-ui.imagen-ampliable tamano="portada" :url="null" :alt="$alt" />
    </div>
@elseif ($fotos->count() === 1)
    @php($primera = $fotos->first())
    <div {{ $attributes }} data-galeria-unica>
        @isset($portada)
            <div x-data="{ actual() { return @js($primera); } }">
                {{ $portada }}
            </div>
        @else
            <x-ui.imagen-ampliable tamano="portada" :modal="$modal" :url="$primera['portada']" :alt="$primera['alt']" />
        @endisset
    </div>
@else
    @php($primera = $fotos->first())
    @php($total = $fotos->count())
    @php($fotosJson = json_encode($fotos->all(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT))
    <div
        x-data="{
            activa: 0,
            fotos: {{ $fotosJson }},
            elegir(indice) {
                if (indice >= 0 && indice < this.fotos.length) {
                    this.activa = indice;
                }
            },
            mover(delta) {
                if (! this.fotos.length) {
                    return;
                }
                const siguiente = (this.activa + delta + this.fotos.length) % this.fotos.length;
                this.elegir(siguiente);
                this.$nextTick(() => this.$refs['thumb_' + siguiente]?.focus());
            },
            actual() {
                return this.fotos[this.activa] || this.fotos[0] || {};
            }
        }"
        wire:key="galeria-{{ md5($fotos->toJson()) }}"
        data-galeria-producto
        role="region"
        aria-label="Galería de {{ $alt }}"
        {{ $attributes->class('space-y-3') }}
    >
        @isset($portada)
            {{ $portada }}
        @else
            <x-ui.imagen-ampliable
                tamano="portada"
                :modal="$modal"
                :url="$primera['portada']"
                :alt="$primera['alt']"
                url-dinamica="actual().portada"
                alt-dinamico="actual().alt"
            />
        @endisset

        <div class="flex flex-wrap items-center gap-2 {{ $clasesMiniaturas }}" role="group" aria-label="Vistas de {{ $alt }}" data-galeria-miniaturas>
            @foreach ($fotos as $indice => $foto)
                <button
                    type="button"
                    x-ref="thumb_{{ $indice }}"
                    @click="elegir({{ $indice }})"
                    @keydown.arrow-right.prevent="mover(1)"
                    @keydown.arrow-down.prevent="mover(1)"
                    @keydown.arrow-left.prevent="mover(-1)"
                    @keydown.arrow-up.prevent="mover(-1)"
                    @keydown.home.prevent="elegir(0); $nextTick(() => $refs.thumb_0?.focus())"
                    @keydown.end.prevent="elegir({{ $total - 1 }}); $nextTick(() => $refs['thumb_{{ $total - 1 }}']?.focus())"
                    data-galeria-miniatura="{{ $indice }}"
                    aria-label="Ver imagen {{ $indice + 1 }} de {{ $total }} de {{ $foto['alt'] }}"
                    aria-pressed="{{ $indice === 0 ? 'true' : 'false' }}"
                    :aria-pressed="activa === {{ $indice }} ? 'true' : 'false'"
                    :class="activa === {{ $indice }}
                        ? 'border-fp-primary ring-2 ring-fp-primary/40 opacity-100'
                        : 'border-slate-200 opacity-75 hover:opacity-100 hover:border-fp-primary/50'"
                    class="inline-flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-white p-1 transition duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-fp-primary motion-reduce:transition-none motion-reduce:transform-none {{ $indice === 0 ? 'border-fp-primary ring-2 ring-fp-primary/40 opacity-100' : 'border-slate-200 opacity-75' }}"
                >
                    <img
                        src="{{ $foto['miniatura'] }}"
                        alt="{{ $foto['alt'] }} ({{ $indice + 1 }} de {{ $total }})"
                        loading="lazy"
                        decoding="async"
                        width="64"
                        height="64"
                        class="h-full w-full object-contain"
                    >
                </button>
            @endforeach
        </div>

        <p class="sr-only" aria-live="polite" x-text="'Mostrando imagen ' + (activa + 1) + ' de ' + fotos.length + ' de ' + (actual().alt || '')"></p>
    </div>
@endif
