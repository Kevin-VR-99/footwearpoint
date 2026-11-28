<?php

namespace Tests\Unit\Support;

use App\Support\BusquedaDeCliente;
use PHPUnit\Framework\TestCase;

class BusquedaDeClienteTest extends TestCase
{
    public function test_letras_parciales_encuentran_el_nombre_sin_importar_acentos_ni_mayusculas(): void
    {
        $this->assertTrue(BusquedaDeCliente::coincide('Ana Ramírez', '5512345678', 'ram'));
        $this->assertTrue(BusquedaDeCliente::coincide('Ana Ramírez', '5512345678', '  RAMÍREZ  '));
        $this->assertTrue(BusquedaDeCliente::coincide('Ana Ramírez', '5512345678', 'ramirez'));
        $this->assertFalse(BusquedaDeCliente::coincide('Ana Ramírez', '5512345678', 'pedro'));
    }

    public function test_el_telefono_coincide_con_unas_cuantas_cifras_aunque_vengan_con_espacios(): void
    {
        $this->assertTrue(BusquedaDeCliente::coincide('Ana Ramírez', '5512345678', '1234'));
        $this->assertTrue(BusquedaDeCliente::coincide('Ana Ramírez', '5512345678', '55 1234'));
        $this->assertTrue(BusquedaDeCliente::coincide('Ana Ramírez', '55 1234 5678', '5512345678'));
        $this->assertFalse(BusquedaDeCliente::coincide('Ana Ramírez', '5512345678', '0000'));
    }

    public function test_termino_vacio_coincide_con_cualquiera(): void
    {
        $this->assertTrue(BusquedaDeCliente::coincide('Ana', '5512345678', ''));
        $this->assertTrue(BusquedaDeCliente::coincide('Ana', '5512345678', '   '));
    }
}
