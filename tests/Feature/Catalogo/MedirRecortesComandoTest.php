<?php

namespace Tests\Feature\Catalogo;

use App\Services\Ia\MedirRecorte;
use Tests\TestCase;

/**
 * TG-377 (A17) — La medida y el comando que la usa.
 *
 * El comando entero no se puede correr aquí: necesita pdftoppm, que en local
 * no está. Lo que sí se prueba es que lo diga en vez de reventar, y que la
 * medida se comporte como dice el informe que se comporta.
 */
class MedirRecortesComandoTest extends TestCase
{
    /** @var list<string> */
    private array $basura = [];

    protected function tearDown(): void
    {
        foreach ($this->basura as $archivo) {
            @unlink($archivo);
        }

        parent::tearDown();
    }

    /**
     * Una imagen de un color, con una franja de otro en el borde que se diga.
     *
     * @param  array{0:int,1:int,2:int}  $fondo
     */
    private function imagen(array $fondo, ?string $bordeConSilueta = null): string
    {
        $img = imagecreatetruecolor(60, 60);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$fondo));

        if ($bordeConSilueta !== null) {
            $negro = imagecolorallocate($img, 0, 0, 0);
            match ($bordeConSilueta) {
                'arriba' => imagefilledrectangle($img, 10, 0, 49, 0, $negro),
                'izquierda' => imagefilledrectangle($img, 0, 10, 0, 49, $negro),
            };
        }

        $ruta = tempnam(sys_get_temp_dir(), 'medida').'.png';
        imagepng($img, $ruta);
        imagedestroy($img);
        $this->basura[] = $ruta;

        return $ruta;
    }

    // --- El comando ---------------------------------------------------------

    /** Sin poppler avisa y se va; no revienta ni deja una importación a medias. */
    public function test_sin_pdftoppm_el_comando_lo_dice_en_vez_de_tronar(): void
    {
        if (app(\App\Services\Ia\RasterizarPaginaPdf::class)->disponible()) {
            $this->markTestSkipped('Esta máquina sí tiene pdftoppm.');
        }

        $this->artisan('catalogo:medir-recortes', ['importacion' => 1])
            ->expectsOutputToContain('poppler')
            ->assertExitCode(1);
    }

    // --- La medida ----------------------------------------------------------

    /** Un recorte con solo fondo alrededor no tiene nada tocando el borde. */
    public function test_sin_silueta_en_el_borde_da_cero(): void
    {
        $this->assertSame(0, app(MedirRecorte::class)->siluetaEnElBorde($this->imagen([255, 255, 255])));
    }

    public function test_una_silueta_cruzando_el_borde_se_cuenta(): void
    {
        $this->assertSame(
            40,
            app(MedirRecorte::class)->siluetaEnElBorde($this->imagen([255, 255, 255], 'arriba'))
        );
    }

    /**
     * El fondo no se da por blanco.
     *
     * Las páginas de catálogo traen fondos de color. Si se diera por blanco,
     * una página con fondo crema contaría TODO el marco como zapato y la
     * medida no significaría nada.
     */
    public function test_un_fondo_de_color_no_se_cuenta_como_zapato(): void
    {
        // Un fondo de catalogo de verdad, bien lejos del blanco: con uno casi
        // blanco la prueba pasaria sin demostrar nada, porque cabe en la tolerancia.
        $crema = [200, 170, 130];
        $medidor = app(MedirRecorte::class);
        $pagina = $this->imagen($crema);

        $this->assertSame($crema, $medidor->fondoDeLaPagina($pagina), 'No reconoció el fondo de la página.');
        $this->assertSame(0, $medidor->siluetaEnElBorde($pagina, $crema));

        // Y dando por hecho que era blanco, se contaría el marco entero.
        $this->assertGreaterThan(0, $medidor->siluetaEnElBorde($pagina, [255, 255, 255]));
    }

    /** El porcentaje permite comparar un zapato chico con uno grande. */
    public function test_el_porcentaje_es_sobre_el_marco(): void
    {
        $medidor = app(MedirRecorte::class);

        // 40 píxeles de 236 que mide el marco de una imagen de 60x60.
        $this->assertSame(16.9, $medidor->porcentajeEnElBorde($this->imagen([255, 255, 255], 'arriba')));
        $this->assertSame(0.0, $medidor->porcentajeEnElBorde($this->imagen([255, 255, 255])));
    }

    /** Las sombras suaves de un PDF dibujado no son el zapato. */
    public function test_el_ruido_del_pdf_no_cuenta_como_silueta(): void
    {
        $this->assertSame(
            0,
            app(MedirRecorte::class)->siluetaEnElBorde($this->imagen([235, 235, 235]), [255, 255, 255]),
            'Una sombra suave se contó como zapato cortado.'
        );
    }
}
