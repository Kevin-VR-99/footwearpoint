<?php

namespace Tests\Feature\Diseno;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * TG-378 A18 — Los componentes base del sistema de diseño.
 *
 * Por qué existen: en las vistas había 199 <label> y 128 bloques @error
 * escritos a mano, el mensaje de error se pintaba de 4 formas distintas
 * (incluida text-red-600, que ni siquiera es el rojo de la casa) y el mismo
 * <select> se escribía de 6 maneras. Cada formulario volvía a decidir lo
 * mismo y cada quien decidía distinto.
 *
 * Estas pruebas fijan lo que no debe volver a variar.
 */
class ComponentesBaseTest extends TestCase
{
    /** Dibuja un trozo de Blade, opcionalmente con errores de validación puestos. */
    private function dibujar(string $plantilla, array $errores = []): string
    {
        $bolsa = new ViewErrorBag;

        if ($errores !== []) {
            $bolsa->put('default', new \Illuminate\Support\MessageBag($errores));
        }

        View::share('errors', $bolsa);

        return trim(Blade::render($plantilla));
    }

    public function test_el_campo_amarra_la_etiqueta_con_el_control(): void
    {
        $html = $this->dibujar(
            '<x-ui.campo etiqueta="Nombre comercial" para="nombre"><input id="nombre"></x-ui.campo>'
        );

        // Sin for/id la etiqueta no enfoca el control y el lector de pantalla
        // no sabe de quién habla.
        $this->assertStringContainsString('for="nombre"', $html);
        $this->assertStringContainsString('id="nombre"', $html);
        $this->assertStringContainsString('Nombre comercial', $html);
    }

    public function test_el_campo_lleva_la_clase_de_la_casa_y_la_del_apilado(): void
    {
        $html = $this->dibujar('<x-ui.campo etiqueta="X" para="x"><input id="x"></x-ui.campo>');

        // .fp-campo ya existía antes de A18 y es la que tiñe la etiqueta al
        // enfocar; si el componente la perdiera, se saldría de la casa.
        $this->assertStringContainsString('fp-campo', $html);
        // Y el apilado va en su propia clase, para no cambiarle el diseño a
        // los 22 archivos que ya usaban .fp-campo con su propio margen.
        $this->assertStringContainsString('fp-campo-apilado', $html);
    }

    public function test_el_campo_enseña_el_error_de_la_validacion(): void
    {
        $html = $this->dibujar(
            '<x-ui.campo etiqueta="Correo" para="correo" error="correo"><input id="correo"></x-ui.campo>',
            ['correo' => ['Ese correo ya está registrado.']]
        );

        $this->assertStringContainsString('fp-campo-error', $html);
        $this->assertStringContainsString('Ese correo ya está registrado.', $html);
    }

    /**
     * Dos textos chicos debajo del mismo control compiten entre sí, y el que
     * importa cuando algo salió mal es el error.
     */
    public function test_la_ayuda_se_calla_cuando_hay_error(): void
    {
        $plantilla = '<x-ui.campo etiqueta="Correo" para="correo" error="correo" ayuda="Te escribiremos aquí."><input id="correo"></x-ui.campo>';

        $sinError = $this->dibujar($plantilla);
        $this->assertStringContainsString('Te escribiremos aquí.', $sinError);

        $conError = $this->dibujar($plantilla, ['correo' => ['Correo inválido.']]);
        $this->assertStringContainsString('Correo inválido.', $conError);
        $this->assertStringNotContainsString('Te escribiremos aquí.', $conError);
    }

    /** El asterisco solo no se oye: hace falta decirlo con palabras. */
    public function test_el_obligatorio_se_anuncia_y_no_solo_se_pinta(): void
    {
        $html = $this->dibujar('<x-ui.campo etiqueta="Nombre" para="n" requerido><input id="n"></x-ui.campo>');

        $this->assertStringContainsString('fp-campo-requerido', $html);
        $this->assertStringContainsString('aria-label="obligatorio"', $html);
    }

    public function test_la_entrada_y_el_selector_usan_la_misma_clase(): void
    {
        $entrada = $this->dibujar('<x-ui.entrada />');
        $selector = $this->dibujar('<x-ui.selector />');

        // La clase es la que fija el radio con el token: es justo lo que
        // variaba entre rounded-lg y rounded-md según quién escribiera.
        $this->assertStringContainsString('fp-control', $entrada);
        $this->assertStringContainsString('fp-control', $selector);
    }

    public function test_un_tipo_de_entrada_inventado_no_pasa(): void
    {
        $html = $this->dibujar('<x-ui.entrada tipo="cualquier-cosa" />');

        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringNotContainsString('cualquier-cosa', $html);
    }

    public function test_las_utilidades_de_la_vista_siguen_mandando(): void
    {
        // Igual que x-panel.boton: el componente pone la base, la vista afina.
        $html = $this->dibujar('<x-ui.entrada class="uppercase" />');

        $this->assertStringContainsString('fp-control', $html);
        $this->assertStringContainsString('uppercase', $html);
    }

    public function test_el_selector_pone_su_opcion_vacia(): void
    {
        $html = $this->dibujar('<x-ui.selector vacio="Elige una marca"><option value="1">Nike</option></x-ui.selector>');

        $this->assertStringContainsString('<option value="">Elige una marca</option>', $html);
        $this->assertStringContainsString('Nike', $html);

        // Sin "vacio" no se inventa ninguna: hay selectores que no la quieren.
        $this->assertStringNotContainsString('<option value="">', $this->dibujar('<x-ui.selector />'));
    }
}
