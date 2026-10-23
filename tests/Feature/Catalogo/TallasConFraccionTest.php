<?php

namespace Tests\Feature\Catalogo;

use App\Services\Ia\RangoDeTallas;
use PHPUnit\Framework\TestCase;

/**
 * TG-282 — Las medias tallas escritas como fracción.
 *
 * Los catálogos no imprimen "6.5", imprimen "6½". La IA lo transcribe fiel
 * —"6 1/2"— y eso está bien: el dato es correcto. El que no lo entendía era
 * este lector, que hacía is_numeric("6 1/2") y se rendía.
 *
 * No es teoría: en el Cklass real se quedaron fuera 15 productos con "no se
 * entendió el rango de tallas", todos de las páginas 53 a la 70, que es la
 * sección donde las tallas van con medios.
 */
class TallasConFraccionTest extends TestCase
{
    private RangoDeTallas $rango;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rango = new RangoDeTallas;
    }

    public function test_el_caso_que_dejo_fuera_quince_productos(): void
    {
        // Tal cual venía el 262-34 de la página 53.
        $valores = $this->rango->valores('2', '6 1/2', true);

        $this->assertSame(['2', '2.5', '3', '3.5', '4', '4.5', '5', '5.5', '6', '6.5'], $valores);
    }

    public function test_entiende_la_fraccion_escrita_de_varias_formas(): void
    {
        $esperado = ['6', '6.5'];

        foreach (['6 1/2', '6½', '6.5', '6,5'] as $hasta) {
            $this->assertSame($esperado, $this->rango->valores('6', $hasta, true), "No entendió «{$hasta}»");
        }
    }

    public function test_una_fraccion_suelta_tambien(): void
    {
        $this->assertSame(['0.5', '1'], $this->rango->valores('1/2', '1', true));
    }

    public function test_los_numeros_de_siempre_siguen_igual(): void
    {
        $this->assertSame(['22', '23', '24', '25', '26', '27'], $this->rango->valores('22', '27'));
        $this->assertSame(['2', '3', '4', '5', '6'], $this->rango->valores(2, 6));
    }

    /**
     * Lo que NO debe pasar: que por entender fracciones se empiece a aceptar
     * cualquier cosa. Inventar una talla es peor que reportar el problema.
     */
    public function test_lo_que_no_se_entiende_se_sigue_reportando(): void
    {
        foreach ([
            ['22', 'UNITALLA'],
            ['chico', 'grande'],
            ['22', ''],
            ['22', null],
            ['6', '1/0'],
            // Al revés.
            ['27', '22'],
            // Un rango absurdo es error de lectura, no un producto con 100 tallas.
            ['1', '200'],
        ] as [$desde, $hasta]) {
            $this->assertSame([], $this->rango->valores($desde, $hasta), json_encode([$desde, $hasta]));
        }
    }
}
