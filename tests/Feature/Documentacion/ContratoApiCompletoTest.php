<?php

namespace Tests\Feature\Documentacion;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TG-223 (E18-03) — Ningún endpoint de /api se queda sin documentar.
 *
 * El acuerdo del equipo es que quien crea un endpoint lo documenta. Esta
 * prueba lo vuelve automático: si una ruta de /api no aparece en
 * docs/contrato-api.md, falla y dice cuál es.
 *
 * No revisa que lo documentado sea correcto, eso no lo puede saber una
 * prueba: solo que esté mencionado, ya sea a detalle (lo que usa la app) o en
 * la lista final de "Endpoints que no usa la app".
 */
class ContratoApiCompletoTest extends TestCase
{
    private const CONTRATO = 'docs/contrato-api.md';

    /**
     * Rutas que a propósito no se documentan.
     *
     * Solo las que Laravel o un paquete registran solos y no son parte del
     * contrato con la app.
     */
    private const SIN_DOCUMENTAR = [
        'api/user',
    ];

    public function test_todas_las_rutas_de_api_estan_en_el_contrato(): void
    {
        $contrato = file_get_contents(base_path(self::CONTRATO));

        $this->assertNotFalse($contrato, 'No se encontró '.self::CONTRATO);

        $faltantes = [];

        foreach ($this->rutasDeApi() as $ruta) {
            if (! $this->estaMencionada($ruta, $contrato)) {
                $faltantes[] = $ruta;
            }
        }

        $this->assertSame([], $faltantes, $this->mensaje($faltantes));
    }

    /** @return array<int, string> rutas únicas de /api, sin el método */
    private function rutasDeApi(): array
    {
        $rutas = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($ruta) => $ruta->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'api/'))
            ->reject(fn (string $uri) => in_array($uri, self::SIN_DOCUMENTAR, true))
            ->unique()
            ->values()
            ->all();

        $this->assertNotEmpty($rutas, 'No se encontró ninguna ruta de /api.');

        return $rutas;
    }

    /**
     * El contrato escribe las rutas con su parámetro real ({id}, {pedido}…) y
     * a veces agrupa varias en una línea. Para no pelearse con eso, se compara
     * la ruta con los nombres de parámetro normalizados a {}.
     */
    private function estaMencionada(string $ruta, string $contrato): bool
    {
        $patron = preg_replace('/\{[^}]+\}/', '{}', $ruta);

        $contratoNormalizado = preg_replace('/\{[^}]+\}/', '{}', $contrato);

        // Con y sin la diagonal inicial: el documento usa /api/... y las
        // tablas del final a veces juntan varias rutas en una celda.
        return str_contains($contratoNormalizado, '/'.$patron)
            || str_contains($contratoNormalizado, $patron);
    }

    /** @param array<int, string> $faltantes */
    private function mensaje(array $faltantes): string
    {
        if ($faltantes === []) {
            return '';
        }

        $lista = implode("\n  - ", $faltantes);

        return "Falta documentar en ".self::CONTRATO.":\n  - ".$lista."\n\n"
            ."Si la app lo usa, documéntalo a detalle en su sección. Si solo lo usa el panel, "
            ."agrégalo a la lista \"Endpoints que no usa la app\" del final, con una línea de para qué sirve.";
    }
}
