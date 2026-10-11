<?php

namespace Tests\Feature\Panel;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * TG-179: componentes de panel compartidos (encabezado, tarjeta, tabla,
 * vacío, botón, alerta y filtros) con la paleta de la casa.
 */
class ComponentesPanelTest extends TestCase
{
    public function test_encabezado_muestra_titulo_subtitulo_y_acciones(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-panel.encabezado titulo="Pedidos" subtitulo="Listado" eyebrow="Operación">
                <x-slot:acciones><span>Acción</span></x-slot:acciones>
            </x-panel.encabezado>
        BLADE);

        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('Pedidos', $html);
        $this->assertStringContainsString('Listado', $html);
        $this->assertStringContainsString('Operación', $html);
        $this->assertStringContainsString('<span>Acción</span>', $html);
        $this->assertStringContainsString('text-fp-sidebar', $html);
    }

    public function test_encabezado_permite_h1_y_enlace_de_regreso(): void
    {
        $html = Blade::render('<x-panel.encabezado titulo="Detalle" nivel="h1" volver="/pedidos" volver-texto="← Pedidos" />');

        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('href="/pedidos"', $html);
        $this->assertStringContainsString('← Pedidos', $html);
    }

    public function test_tarjeta_y_tabla_usan_el_contenedor_de_la_casa(): void
    {
        $tarjeta = Blade::render('<x-panel.tarjeta titulo="Resumen">Contenido</x-panel.tarjeta>');
        $this->assertStringContainsString('rounded-2xl border border-slate-200/80 bg-white shadow-sm', $tarjeta);
        $this->assertStringContainsString('Resumen', $tarjeta);

        $tabla = Blade::render(<<<'BLADE'
            <x-panel.tabla>
                <x-slot:encabezado><tr><th>Folio</th></tr></x-slot:encabezado>
                <x-panel.vacio colspan="1" mensaje="No hay pedidos." />
            </x-panel.tabla>
        BLADE);
        $this->assertStringContainsString('<thead class="bg-fp-page', $tabla);
        $this->assertStringContainsString('colspan="1"', $tabla);
        $this->assertStringContainsString('No hay pedidos.', $tabla);
    }

    public function test_vacio_sin_colspan_es_un_bloque(): void
    {
        $html = Blade::render('<x-panel.vacio mensaje="Sin datos" />');

        $this->assertStringNotContainsString('<td', $html);
        $this->assertStringContainsString('Sin datos', $html);
    }

    public function test_boton_como_enlace_o_boton_y_sus_variantes(): void
    {
        $enlace = Blade::render('<x-panel.boton href="/x">Ir</x-panel.boton>');
        $this->assertStringContainsString('<a href="/x"', $enlace);
        $this->assertStringContainsString('bg-fp-primary', $enlace);

        $boton = Blade::render('<x-panel.boton variante="peligro" wire:click="borrar">Borrar</x-panel.boton>');
        $this->assertStringContainsString('type="button"', $boton);
        $this->assertStringContainsString('wire:click="borrar"', $boton);
        $this->assertStringContainsString('bg-fp-danger', $boton);
    }

    public function test_alerta_usa_los_colores_de_insignia(): void
    {
        $tipos = [
            'exito' => 'bg-fp-badge-success-bg',
            'error' => 'bg-fp-badge-danger-bg',
            'aviso' => 'bg-fp-badge-warning-bg',
            'info' => 'bg-fp-badge-info-bg',
        ];

        foreach ($tipos as $tipo => $clase) {
            $html = Blade::render('<x-panel.alerta :tipo="$tipo">Mensaje</x-panel.alerta>', ['tipo' => $tipo]);
            $this->assertStringContainsString($clase, $html);
            $this->assertStringContainsString('Mensaje', $html);
        }

        $this->assertStringContainsString('role="alert"', Blade::render('<x-panel.alerta tipo="error">x</x-panel.alerta>'));
    }

    public function test_filtros_conserva_su_contenido(): void
    {
        $html = Blade::render('<x-panel.filtros class="mb-4"><select wire:model.live="filtro"></select></x-panel.filtros>');

        $this->assertStringContainsString('wire:model.live="filtro"', $html);
        $this->assertStringContainsString('mb-4', $html);
    }
}
