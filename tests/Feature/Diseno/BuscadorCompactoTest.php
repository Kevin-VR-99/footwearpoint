<?php

namespace Tests\Feature\Diseno;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * A19 — Buscador compacto que se expande.
 *
 * Lo que de verdad define esta tarjeta —el ancho al enfocar— pasa en el
 * navegador y se comprobó ahí: en reposo 240px, al enfocarlo 416px, y el
 * botón de al lado quieto en x=465 en los tres estados.
 *
 * Lo que se fija aquí es el andamio del que depende todo eso: que exista el
 * hueco que mantiene el tamaño en la barra, y que la etiqueta no se quede con
 * atributos que son del campo.
 */
class BuscadorCompactoTest extends TestCase
{
    private function dibujar(string $plantilla): string
    {
        return trim(Blade::render($plantilla));
    }

    /**
     * El hueco es lo que impide que los botones se muevan: conserva su tamaño
     * en la barra mientras el buscador crece encima. Sin él, expandir empuja
     * al select y al botón de exportar.
     */
    public function test_el_compacto_va_dentro_de_un_hueco_que_guarda_su_lugar(): void
    {
        $html = $this->dibujar('<x-ui.buscador compacto placeholder="Buscar cliente" />');

        $this->assertStringContainsString('fp-buscador-hueco', $html);
        $this->assertStringContainsString('data-buscador-compacto', $html);
    }

    public function test_sin_compacto_se_queda_como_estaba(): void
    {
        $html = $this->dibujar('<x-ui.buscador placeholder="Buscar cliente" />');

        // 26 buscadores en 18 pantallas: el compacto es opcional a propósito y
        // los demás no deben cambiar de forma.
        $this->assertStringNotContainsString('fp-buscador-hueco', $html);
        $this->assertStringNotContainsString('data-buscador-compacto', $html);
        $this->assertStringContainsString('fp-buscador', $html);
    }

    /**
     * La etiqueta solo lleva su clase. Al armar el hueco se le pasaron todos
     * los atributos por error y wire:model acabó también en el <label>, con lo
     * que Livewire se enganchaba dos veces al mismo campo.
     */
    public function test_los_atributos_del_campo_no_se_quedan_en_la_etiqueta(): void
    {
        $html = $this->dibujar('<x-ui.buscador compacto wire:model.live="buscar" placeholder="Buscar cliente" />');

        $this->assertSame(
            1,
            substr_count($html, 'wire:model.live="buscar"'),
            'wire:model aparece más de una vez: Livewire se engancharía dos veces al mismo campo.'
        );

        $etiqueta = substr($html, strpos($html, '<label'), strpos($html, '>', strpos($html, '<label')) - strpos($html, '<label'));
        $this->assertStringNotContainsString('wire:model', $etiqueta);
    }

    public function test_el_campo_conserva_su_etiqueta_accesible(): void
    {
        $html = $this->dibujar('<x-ui.buscador compacto etiqueta="Buscar clientes" placeholder="Nombre o correo" />');

        // Al reacomodar el marcado es fácil perderla, y es lo único que oye
        // quien usa lector de pantalla: la lupa no dice nada.
        $this->assertStringContainsString('aria-label="Buscar clientes"', $html);
        $this->assertStringContainsString('Buscar clientes', $html);
    }
}
