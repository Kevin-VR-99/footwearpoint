<?php

namespace Tests\Feature\Catalogo;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * TG-376 A16 — Las fotos se revisan completas.
 *
 * El problema: la foto que propone la IA se miraba en un cuadro de 96x96 con
 * object-cover. Recortada y de ese tamaño no se puede decidir si la propuesta
 * sirve — a un zapato al que le falta la punta se le ve igual de bien — y esa
 * pantalla es justo donde se aprueba o se rechaza.
 *
 * Lo que fija esta prueba es que en las pantallas donde se VALIDA la calidad
 * la foto no se recorte, porque es muy facil que vuelva un object-cover sin
 * que nadie note lo que cuesta.
 */
class FotosCompletasParaRevisarTest extends TestCase
{
    /** @return array<string, string> ruta => nombre para el mensaje de error */
    private static function pantallasDeRevision(): array
    {
        return [
            'resources/views/components/admin/⚡revisar-catalogo.blade.php' => 'Revisar catálogo',
            'resources/views/components/admin/⚡catalogo-maestro.blade.php' => 'Catálogo maestro',
        ];
    }

    public function test_las_pantallas_de_revision_no_recortan_las_fotos(): void
    {
        foreach (self::pantallasDeRevision() as $ruta => $pantalla) {
            // Sin los comentarios de Blade: ahí se nombra object-cover justo
            // para explicar por qué se fue, y eso no recorta nada.
            $codigo = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(base_path($ruta)));

            $this->assertStringNotContainsString(
                'object-cover',
                $codigo,
                "{$pantalla}: una foto volvió a recortarse, y ahí es donde se valida su calidad."
            );
        }
    }

    public function test_la_foto_propuesta_se_puede_abrir_grande(): void
    {
        $vista = file_get_contents(base_path('resources/views/components/admin/⚡revisar-catalogo.blade.php'));

        // Abrirla grande es lo que hace "validable" la calidad: en la
        // miniatura de la tabla no se nota un recorte malo.
        $this->assertStringContainsString('modal="grande"', $vista);
        $this->assertStringContainsString('<x-ui.imagen-ampliable', $vista);
    }

    public function test_el_modal_grande_es_mas_ancho_que_el_normal(): void
    {
        $normal = Blade::render('<x-ui.imagen-ampliable url="https://ejemplo.test/foto.webp" alt="Zapato" />');
        $grande = Blade::render('<x-ui.imagen-ampliable url="https://ejemplo.test/foto.webp" alt="Zapato" modal="grande" />');

        $this->assertStringContainsString('max-w-[360px]', $normal);
        $this->assertStringNotContainsString('max-w-[360px]', $grande);
        $this->assertStringContainsString('max-w-5xl', $grande);
    }

    /**
     * En grande no se fuerza el cuadrado.
     *
     * Las fotos de catalogo son apaisadas. Metidas en un cuadro, un tercio de
     * la caja se iba en franjas grises y el zapato se quedaba chico en medio:
     * medido, 648x432 de foto util contra 968x645 sin el cuadro.
     */
    public function test_en_grande_la_foto_no_se_encierra_en_un_cuadrado(): void
    {
        $grande = Blade::render('<x-ui.imagen-ampliable url="https://ejemplo.test/foto.webp" alt="Zapato" modal="grande" />');
        $normal = Blade::render('<x-ui.imagen-ampliable url="https://ejemplo.test/foto.webp" alt="Zapato" />');

        // La etiqueta <img> del modal: la del boton no lleva aspect-square.
        $this->assertStringContainsString('max-h-[75vh]', $grande);
        $this->assertStringNotContainsString('aspect-square w-full', $grande);

        // El chico se queda como estaba: solo cambia donde se valida calidad.
        $this->assertStringContainsString('aspect-square', $normal);
    }

    /** La foto grande tampoco se recorta: para eso se abre. */
    public function test_la_foto_abierta_se_ve_entera(): void
    {
        $html = Blade::render('<x-ui.imagen-ampliable url="https://ejemplo.test/foto.webp" alt="Zapato" modal="grande" />');

        $this->assertStringContainsString('object-contain', $html);
    }
}
