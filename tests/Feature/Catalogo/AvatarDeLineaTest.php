<?php

namespace Tests\Feature\Catalogo;

use App\Models\Linea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * TG-384 A24 — El avatar de la línea.
 *
 * El logotipo de la línea, o su inicial cuando no hay logotipo, en las tres
 * pantallas donde conviven varias líneas: catálogo de productos, temporadas y
 * «mis líneas».
 */
class AvatarDeLineaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_con_logotipo_se_enseña_el_logotipo(): void
    {
        $html = Blade::render(
            '<x-ui.avatar-linea nombre="Impuls" url="https://ejemplo.test/impuls.png" />'
        );

        $this->assertStringContainsString('https://ejemplo.test/impuls.png', $html);
        $this->assertStringContainsString('Logotipo de Impuls', $html);
    }

    /**
     * Un logotipo no se recorta.
     *
     * Es la misma lección de A16: recortar al centro se come justo el nombre
     * de la marca, que en un logo suele ir a lo ancho. x-ui.avatar (el de
     * personas) sí recorta, y ahí está bien, porque una cara se centra sola.
     */
    public function test_el_logotipo_no_se_recorta(): void
    {
        $linea = Blade::render('<x-ui.avatar-linea nombre="Impuls" url="https://ejemplo.test/i.png" />');
        $persona = Blade::render('<x-ui.avatar nombre="Ana García" url="https://ejemplo.test/a.png" />');

        $this->assertStringContainsString('object-contain', $linea);
        $this->assertStringNotContainsString('object-cover', $linea);

        // El de personas se queda como estaba.
        $this->assertStringContainsString('object-cover', $persona);
    }

    public function test_sin_logotipo_sale_la_inicial(): void
    {
        $html = Blade::render('<x-ui.avatar-linea nombre="Cklass" />');

        $this->assertStringContainsString('>C<', $html);
        $this->assertStringContainsString('text-fp-primary', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    /**
     * Una sola letra. "Impuls" y "Cklass" son una palabra; con dos iniciales
     * quedaría "IM", que no se lee como una marca.
     */
    public function test_es_una_inicial_y_no_dos(): void
    {
        $html = Blade::render('<x-ui.avatar-linea nombre="Destroyer Kids" />');

        $this->assertStringContainsString('>D<', $html);
        $this->assertStringNotContainsString('>DK<', $html);
    }

    /** El nombre va al lado en las tres pantallas: repetirlo sería ruido. */
    public function test_la_inicial_no_se_le_lee_dos_veces_a_quien_usa_lector(): void
    {
        $html = Blade::render('<x-ui.avatar-linea nombre="Cklass" />');

        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_acepta_la_linea_entera_o_sus_datos_sueltos(): void
    {
        $linea = Linea::query()->firstOrFail();
        $linea->update(['logotipo_url' => 'https://ejemplo.test/linea.png']);

        $html = Blade::render('<x-ui.avatar-linea :linea="$linea" />', ['linea' => $linea->fresh()]);

        $this->assertStringContainsString('https://ejemplo.test/linea.png', $html);
        $this->assertStringContainsString('Logotipo de '.$linea->nombre, $html);
    }

    public function test_las_tres_pantallas_lo_usan(): void
    {
        $pantallas = [
            'resources/views/components/catalogo/⚡productos.blade.php' => 'Catálogo de productos',
            'resources/views/components/catalogo/⚡campanas.blade.php' => 'Temporadas',
            'resources/views/components/catalogo/⚡mis-lineas.blade.php' => 'Mis líneas',
        ];

        foreach ($pantallas as $ruta => $pantalla) {
            $this->assertStringContainsString(
                '<x-ui.avatar-linea',
                file_get_contents(base_path($ruta)),
                "{$pantalla}: no enseña el logotipo de la línea."
            );
        }
    }
}
