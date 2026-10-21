<?php

namespace Tests\Feature\Funciones;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** La capa de carga empieza oculta: el CSS de Livewire no cubre wire:loading.delay.flex. */
class CapaCargandoTest extends TestCase
{
    public function test_la_capa_de_carga_inicia_oculta(): void
    {
        $html = Blade::render('<x-ui.cargando />');

        $this->assertStringContainsString('wire:loading.delay.flex', $html);
        $this->assertStringContainsString('style="display: none"', $html);
    }
}
