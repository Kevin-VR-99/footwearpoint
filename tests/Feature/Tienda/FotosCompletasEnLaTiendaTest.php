<?php

namespace Tests\Feature\Tienda;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * TG-407 — En la tienda pública el zapato se ve entero.
 *
 * EL PROBLEMA. La ficha y las tarjetas metían la foto en un cuadrado con
 * object-cover. Las fotos del catálogo son apaisadas, así que la foto se
 * agrandaba hasta llenar el cuadrado y lo que sobraba se cortaba. Medido
 * sobre una tienda real, de la foto más apaisada (relación 2.52) se veía el
 * 40% del ancho: el cliente veía medio zapato en la ficha y el zapato
 * completo al abrir el modal, que sí usa object-contain. La foto guardada
 * nunca estuvo mal; era la caja.
 *
 * POR QUÉ 4/3. De las 16 fotos de esa tienda, la mediana es 1.30 y doce caen
 * entre 1.04 y 1.44. Con object-contain, una caja de 4/3 deja un 16% de hueco
 * de media; la cuadrada dejaba 27%.
 *
 * ESTO NO ES LO MISMO QUE FotosCompletasParaRevisarTest. Aquella cuida las
 * pantallas donde se APRUEBA una foto, y el motivo es que un recorte malo no
 * se nota en miniatura. Esta cuida la pantalla donde un cliente DECIDE SI
 * COMPRA, y el motivo es que no puede comprar lo que no ve. Son dos razones
 * distintas para la misma regla, y por eso viven aparte: si alguien cambiara
 * una, tiene que seguir chocando con la otra.
 */
class FotosCompletasEnLaTiendaTest extends TestCase
{
    /** @return array<string, string> ruta => nombre para el mensaje de error */
    private static function pantallasPublicas(): array
    {
        return [
            'resources/views/components/tienda/tarjeta-producto.blade.php' => 'Tarjeta del listado',
            'resources/views/components/tienda/⚡producto.blade.php' => 'Ficha del producto',
        ];
    }

    /** Sin los comentarios de Blade: ahí se nombra object-cover para explicar por qué se fue. */
    private function vista(string $ruta): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(base_path($ruta)));
    }

    // --- Lo que esta tarjeta viene a arreglar --------------------------------

    public function test_las_pantallas_publicas_no_recortan_la_foto(): void
    {
        foreach (self::pantallasPublicas() as $ruta => $pantalla) {
            $this->assertStringNotContainsString(
                'object-cover',
                $this->vista($ruta),
                "{$pantalla}: la foto volvió a recortarse, y ahí es donde el cliente decide si compra."
            );
        }
    }

    /**
     * La portada de la ficha: entera y en la forma de las fotos.
     *
     * Es el único sitio que usa tamano="portada", así que esto no toca ninguna
     * otra pantalla del panel.
     */
    public function test_la_portada_se_ve_entera_y_no_en_un_cuadrado(): void
    {
        $portada = Blade::render('<x-ui.imagen-ampliable tamano="portada" url="https://ejemplo.test/zapato.webp" alt="Zapato" />');

        $this->assertStringContainsString('object-contain', $portada);
        $this->assertStringNotContainsString('object-cover', $portada, 'La portada volvió a recortar el zapato.');
        $this->assertStringContainsString('aspect-[4/3]', $portada);
        $this->assertStringNotContainsString('aspect-square w-full', $portada, 'La portada volvió al cuadrado.');
    }

    /**
     * La tarjeta del listado, igual.
     *
     * Es lo primero que ve quien entra a la tienda. Si ahí sale cortado, el
     * cliente ni llega a la ficha.
     */
    public function test_la_tarjeta_del_listado_tambien_ensena_la_foto_entera(): void
    {
        $vista = $this->vista('resources/views/components/tienda/tarjeta-producto.blade.php');

        $this->assertStringContainsString('object-contain', $vista);
        $this->assertStringContainsString('aspect-[4/3]', $vista);
        $this->assertStringNotContainsString('aspect-square', $vista, 'La tarjeta volvió al cuadrado.');
    }

    /**
     * Al pasar el cursor no se acerca, porque acercar vuelve a cortar.
     *
     * La caja recorta lo que se sale (overflow-hidden). Un scale de 110%
     * empuja el zapato fuera de la caja justo cuando alguien lo está mirando
     * de cerca, que es exactamente lo que esta tarjeta quita.
     */
    public function test_al_pasar_el_cursor_la_foto_publica_no_se_acerca(): void
    {
        $portada = Blade::render('<x-ui.imagen-ampliable tamano="portada" url="https://ejemplo.test/zapato.webp" alt="Zapato" />');

        $this->assertStringNotContainsString('group-hover:scale-110', $portada, 'La portada se acerca y vuelve a cortar el zapato.');
        $this->assertStringNotContainsString(
            'group-hover:scale-110',
            $this->vista('resources/views/components/tienda/tarjeta-producto.blade.php'),
            'La tarjeta se acerca y vuelve a cortar el zapato.'
        );
    }

    // --- Lo que NO debía cambiar ---------------------------------------------

    /**
     * Las miniaturas del panel se quedan como estaban.
     *
     * tamano="portada" solo lo usa la ficha de la tienda, pero el componente
     * lo comparten unas veinticinco pantallas del panel. Si esto empieza a
     * fallar es que el arreglo se desbordó a sitios que nadie pidió tocar.
     */
    public function test_las_miniaturas_del_panel_siguen_igual(): void
    {
        $chica = Blade::render('<x-ui.imagen-ampliable url="https://ejemplo.test/zapato.webp" alt="Zapato" />');
        $media = Blade::render('<x-ui.imagen-ampliable tamano="media" url="https://ejemplo.test/zapato.webp" alt="Zapato" />');

        $this->assertStringContainsString('h-10 w-10', $chica);
        $this->assertStringContainsString('h-20 w-20', $media);

        // En chico el gesto de acercarse sigue: ahí la foto es pequeña y es lo
        // que avisa de que se puede abrir.
        $this->assertStringContainsString('group-hover:scale-110', $chica);
        $this->assertStringContainsString('group-hover:scale-110', $media);
    }

    /** El modal ya se veía entero (A16) y tiene que seguir así. */
    public function test_el_modal_sigue_ensenando_la_foto_entera(): void
    {
        $html = Blade::render('<x-ui.imagen-ampliable tamano="portada" url="https://ejemplo.test/zapato.webp" alt="Zapato" />');

        $this->assertStringContainsString('data-modal-imagen-stock', $html);
        $this->assertSame(
            2,
            substr_count($html, 'object-contain'),
            'La foto de la caja y la del modal tienen que verse enteras las dos.'
        );
    }

    // --- Y la pantalla sigue de pie -------------------------------------------

    public function test_la_ficha_publica_sigue_abriendo(): void
    {
        $vista = $this->vista('resources/views/components/tienda/⚡producto.blade.php');

        $this->assertStringContainsString('tamano="portada"', $vista, 'La ficha dejó de usar la portada.');
        $this->assertStringContainsString('x-ui.imagen-ampliable', $vista);
    }
}
