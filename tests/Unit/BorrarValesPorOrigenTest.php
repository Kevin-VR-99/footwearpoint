<?php

namespace Tests\Unit;

use App\Services\Vale\BorrarValesPorOrigen;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** TG-356: el orden de borrado sigue vale_origen_id, no el número de id. */
class BorrarValesPorOrigenTest extends TestCase
{
    public function test_el_hijo_con_id_menor_sale_antes_que_su_origen(): void
    {
        $this->assertSame([[1], [2]], BorrarValesPorOrigen::ondas([2, 1], [1 => 2, 2 => null]));
    }

    public function test_una_cadena_de_tres_sale_de_la_hoja_a_la_raiz(): void
    {
        $this->assertSame([[3], [2], [1]], BorrarValesPorOrigen::ondas([1, 2, 3], [3 => 2, 2 => 1, 1 => null]));
    }

    public function test_dos_hijos_del_mismo_origen_salen_en_la_misma_onda(): void
    {
        $ondas = BorrarValesPorOrigen::ondas([1, 2, 3], [2 => 1, 3 => 1, 1 => null]);
        $this->assertEqualsCanonicalizing([2, 3], $ondas[0]);
        $this->assertSame([1], $ondas[1]);
    }

    public function test_un_ciclo_no_devuelve_orden(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ciclo');
        BorrarValesPorOrigen::ondas([1, 2], [1 => 2, 2 => 1]);
    }

    public function test_un_vale_que_se_apunta_a_si_mismo_es_ciclo(): void
    {
        $this->expectException(RuntimeException::class);
        BorrarValesPorOrigen::ondas([5], [5 => 5]);
    }
}
