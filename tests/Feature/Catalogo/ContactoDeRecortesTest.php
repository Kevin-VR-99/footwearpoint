<?php

namespace Tests\Feature\Catalogo;

use App\Models\Campana;
use App\Models\ImportacionCatalogo;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Services\Catalogo\SubirCatalogoParaImportarAction;
use App\Services\Ia\PaginaDelCatalogo;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TG-377 (A17) — Las hojas de contacto, que son la medición de esta tarjeta.
 *
 * No hace falta poppler: la página se deja ya dibujada en el disco, que es lo
 * que haría el dibujado de verdad.
 */
class ContactoDeRecortesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private ImportacionCatalogo $importacion;

    private string $destino;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $campana = Campana::firstOrFail();

        $this->importacion = ImportacionCatalogo::create([
            'linea_id' => $campana->linea_id,
            'campana_id' => $campana->id,
            'archivo_url' => 'catalogos-ia/2026/11/contacto.pdf',
            'tipo_archivo' => 'pdf',
            'estado' => 'requiere_revision',
            'paginas' => 4,
            'iniciada_por_usuario_id' => Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail()->id,
        ]);

        $this->destino = sys_get_temp_dir().'/contacto-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->destino.'/*') ?: [] as $archivo) {
            @unlink($archivo);
        }

        @rmdir($this->destino);

        parent::tearDown();
    }

    private function producto(array $datos = []): ProductoImportadoStaging
    {
        return ProductoImportadoStaging::create([
            'importacion_id' => $this->importacion->id,
            'datos_extraidos' => array_merge([
                'pagina' => 2,
                'codigo_catalogo' => '0526-10',
                'foto_x' => 0.25, 'foto_y' => 0.25, 'foto_ancho' => 0.30, 'foto_alto' => 0.30,
            ], $datos),
            'campos_dudosos' => [],
            'estado' => 'pendiente',
        ]);
    }

    private function dejarLaPaginaPuesta(int $pagina = 2): void
    {
        $img = imagecreatetruecolor(600, 600);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledellipse($img, 240, 240, 180, 180, imagecolorallocate($img, 0, 0, 0));

        $temporal = tempnam(sys_get_temp_dir(), 'pg').'.png';
        imagepng($img, $temporal);
        imagedestroy($img);

        Storage::disk(SubirCatalogoParaImportarAction::disco())->put(
            app(PaginaDelCatalogo::class)->ruta($this->importacion, $pagina),
            (string) file_get_contents($temporal)
        );

        @unlink($temporal);
    }

    // --- Lo que entrega ------------------------------------------------------

    public function test_deja_una_hoja_por_producto_con_el_codigo_en_el_nombre(): void
    {
        $this->dejarLaPaginaPuesta();
        $this->producto();

        $this->artisan('catalogo:contacto-recortes', [
            'importacion' => $this->importacion->id,
            '--destino' => $this->destino,
            '--margenes' => '0,0.10',
        ])->assertExitCode(0);

        $this->assertFileExists($this->destino.'/pag2-0526-10.jpg');
    }

    /**
     * Cada margen es una franja de la hoja.
     *
     * Si solo saliera una, no habría nada que comparar y la «medición» sería
     * una foto suelta.
     */
    public function test_la_hoja_crece_con_cada_margen_que_se_pide(): void
    {
        $this->dejarLaPaginaPuesta();
        $this->producto();

        $alto = function (string $margenes): int {
            foreach (glob($this->destino.'/*') ?: [] as $viejo) {
                @unlink($viejo);
            }

            $this->artisan('catalogo:contacto-recortes', [
                'importacion' => $this->importacion->id,
                '--destino' => $this->destino,
                '--margenes' => $margenes,
            ])->assertExitCode(0);

            return (int) getimagesize($this->destino.'/pag2-0526-10.jpg')[1];
        };

        $this->assertGreaterThan($alto('0'), $alto('0,0.10,0.20'));
    }

    /** Se puede pedir una sola página, para no sacar el catálogo entero. */
    public function test_se_puede_limitar_a_una_pagina(): void
    {
        $this->dejarLaPaginaPuesta(2);
        $this->producto(['pagina' => 2, 'codigo_catalogo' => 'DE-LA-2']);
        $this->producto(['pagina' => 3, 'codigo_catalogo' => 'DE-LA-3']);

        $this->artisan('catalogo:contacto-recortes', [
            'importacion' => $this->importacion->id,
            '--destino' => $this->destino,
            '--pagina' => 2,
        ])->assertExitCode(0);

        $this->assertFileExists($this->destino.'/pag2-DE-LA-2.jpg');
        $this->assertFileDoesNotExist($this->destino.'/pag3-DE-LA-3.jpg');
    }

    // --- Lo que no hace ------------------------------------------------------

    public function test_una_importacion_que_no_existe_se_dice_y_no_truena(): void
    {
        $this->artisan('catalogo:contacto-recortes', ['importacion' => 999999])
            ->expectsOutputToContain('No existe')
            ->assertExitCode(1);
    }

    /**
     * Un producto sin recuadro usable no genera hoja.
     *
     * No hay nada que comparar: lo que ese producto necesita es que alguien
     * lo encuadre a mano, no una hoja con cinco recortes vacíos.
     */
    public function test_sin_recuadro_usable_no_saca_hoja(): void
    {
        $this->dejarLaPaginaPuesta();
        $this->producto(['foto_x' => null, 'foto_y' => null, 'foto_ancho' => null, 'foto_alto' => null]);

        $this->artisan('catalogo:contacto-recortes', [
            'importacion' => $this->importacion->id,
            '--destino' => $this->destino,
        ])->assertExitCode(0);

        $this->assertSame([], glob($this->destino.'/*.jpg') ?: []);
    }

    /** Sin la página dibujada lo dice, en vez de dejar hojas vacías. */
    public function test_sin_la_pagina_avisa(): void
    {
        $this->producto();

        $this->artisan('catalogo:contacto-recortes', [
            'importacion' => $this->importacion->id,
            '--destino' => $this->destino,
        ])->assertExitCode(0);

        $this->assertSame([], glob($this->destino.'/*.jpg') ?: []);
    }
}
