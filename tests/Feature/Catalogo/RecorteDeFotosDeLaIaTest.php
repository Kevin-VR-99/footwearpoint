<?php

namespace Tests\Feature\Catalogo;

use App\Models\ImportacionCatalogo;
use App\Models\ProductoImagen;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Services\Ia\AprobarProductosImportadosAction;
use App\Services\Ia\ProponerRecortesDeFotosAction;
use App\Services\Ia\RasterizarPaginaPdf;
use App\Services\Ia\RecortarFotoDelCatalogo;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-282 (E5-05) — El recorte que la IA propone para cada producto.
 *
 * Medido antes de construir esto, con un PDF de 10 páginas del Cklass: la IA
 * acierta el recuadro, incluso en hojas con tres colores. Lo que se prueba
 * aquí es lo que pasa alrededor: que un recuadro malo no se publique, que sin
 * pdftoppm la lectura siga sirviendo, y sobre todo que una propuesta que
 * nadie miró NO entre al catálogo.
 *
 * Ninguna prueba llama al API ni necesita poppler: el rasterizador se
 * sustituye por uno que devuelve una imagen hecha aquí.
 */
class RecorteDeFotosDeLaIaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Una "página" de 600x800 con un cuadro rojo en el cuarto de arriba. */
    private string $paginaFalsa;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        $this->paginaFalsa = $this->dibujarPagina();
    }

    protected function tearDown(): void
    {
        @unlink($this->paginaFalsa);

        parent::tearDown();
    }

    private function dibujarPagina(): string
    {
        $lienzo = imagecreatetruecolor(600, 800);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagefilledrectangle($lienzo, 60, 80, 540, 280, imagecolorallocate($lienzo, 220, 30, 30));

        $ruta = tempnam(sys_get_temp_dir(), 'pag').'.png';
        imagepng($lienzo, $ruta);
        imagedestroy($lienzo);

        return $ruta;
    }

    private function importacion(): ImportacionCatalogo
    {
        return ImportacionCatalogo::create([
            'linea_id'                => 1,
            'archivo_url'             => 'catalogos-ia/2026/10/prueba.pdf',
            'tipo_archivo'            => 'pdf',
            'estado'                  => 'requiere_revision',
            'paginas'                 => 3,
            'iniciada_por_usuario_id' => Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail()->id,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function producto(ImportacionCatalogo $importacion, array $extra = []): ProductoImportadoStaging
    {
        return ProductoImportadoStaging::create([
            'importacion_id'  => $importacion->id,
            'datos_extraidos' => [
                'pagina'          => 1,
                'marca'           => 'Cklass',
                'modelo'          => '355-37',
                'nombre'          => 'Zapato de dama',
                'color'           => 'NEGRO',
                'color_base'      => 'Negro',
                'codigo_catalogo' => 'CK-355-37',
                'precio_publico'  => 699.0,
                'talla_desde'     => '22',
                'talla_hasta'     => '27',
                'medios_numeros'  => false,
                'disponibilidad'  => null,
                'foto_x'          => 0.1,
                'foto_y'          => 0.1,
                'foto_ancho'      => 0.8,
                'foto_alto'       => 0.25,
                ...$extra,
            ],
            'campos_dudosos' => [],
            // Recién leído por la IA, que es cuando corren los recortes.
            'estado'         => 'pendiente',
        ]);
    }

    /** Un rasterizador que no necesita poppler: devuelve la página dibujada. */
    private function conRasterizador(?string $devuelve): void
    {
        $falso = new class($devuelve) extends RasterizarPaginaPdf
        {
            public function __construct(private ?string $devuelve)
            {
            }

            public function disponible(): bool
            {
                return true;
            }

            public function aPng(string $rutaPdf, int $pagina): ?string
            {
                if ($this->devuelve === null) {
                    return null;
                }

                // Una copia, porque quien llama la borra.
                $copia = tempnam(sys_get_temp_dir(), 'copia').'.png';
                copy($this->devuelve, $copia);

                return $copia;
            }
        };

        $this->app->instance(RasterizarPaginaPdf::class, $falso);
    }

    private function proponer(ImportacionCatalogo $importacion): void
    {
        app(ProponerRecortesDeFotosAction::class)->ejecutar($importacion, '%PDF-1.6 de mentiras');
    }

    // ------------------------------------------------------------------
    // Proponer
    // ------------------------------------------------------------------

    public function test_cada_producto_con_recuadro_recibe_su_recorte(): void
    {
        $this->conRasterizador($this->paginaFalsa);

        $importacion = $this->importacion();
        $producto = $this->producto($importacion);

        $this->proponer($importacion);

        $producto->refresh();

        $this->assertSame('propuesta', $producto->foto_estado);
        $this->assertNotNull($producto->foto_propuesta_url);
        $this->assertCount(1, Storage::disk('s3')->allFiles(ProponerRecortesDeFotosAction::CARPETA));
    }

    public function test_la_pagina_se_dibuja_una_sola_vez_aunque_tenga_tres_colores(): void
    {
        $vecesDibujada = 0;
        $pagina = $this->paginaFalsa;

        $falso = new class($pagina, $vecesDibujada) extends RasterizarPaginaPdf
        {
            public int $veces = 0;

            public function __construct(private string $pagina, int $ignorado)
            {
            }

            public function disponible(): bool
            {
                return true;
            }

            public function aPng(string $rutaPdf, int $pagina): ?string
            {
                $this->veces++;
                $copia = tempnam(sys_get_temp_dir(), 'copia').'.png';
                copy($this->pagina, $copia);

                return $copia;
            }
        };

        $this->app->instance(RasterizarPaginaPdf::class, $falso);

        $importacion = $this->importacion();
        // Tres colores del mismo modelo, los tres en la página 1.
        $this->producto($importacion, ['color' => 'NEGRO', 'codigo_catalogo' => 'CK-1', 'foto_y' => 0.05]);
        $this->producto($importacion, ['color' => 'MIEL', 'codigo_catalogo' => 'CK-2', 'foto_y' => 0.40]);
        $this->producto($importacion, ['color' => 'HUESO', 'codigo_catalogo' => 'CK-3', 'foto_y' => 0.70]);

        $this->proponer($importacion);

        $this->assertSame(1, $falso->veces, 'La página se dibujó más de una vez.');
        $this->assertCount(3, Storage::disk('s3')->allFiles(ProponerRecortesDeFotosAction::CARPETA));
    }

    /**
     * Sin poppler el catálogo se lee igual y los productos nacen sin foto.
     * Es el caso de todas las máquinas del equipo: XAMPP no lo trae.
     */
    public function test_sin_pdftoppm_los_productos_se_guardan_sin_foto_y_no_truena(): void
    {
        $falso = new class extends RasterizarPaginaPdf
        {
            public function disponible(): bool
            {
                return false;
            }
        };
        $this->app->instance(RasterizarPaginaPdf::class, $falso);

        $importacion = $this->importacion();
        $producto = $this->producto($importacion);

        $this->proponer($importacion);

        $this->assertNull($producto->refresh()->foto_propuesta_url);
        $this->assertSame([], Storage::disk('s3')->allFiles(ProponerRecortesDeFotosAction::CARPETA));
    }

    public function test_un_producto_sin_recuadro_queda_marcado_como_dudoso(): void
    {
        $this->conRasterizador($this->paginaFalsa);

        $importacion = $this->importacion();
        $producto = $this->producto($importacion, [
            'foto_x' => null, 'foto_y' => null, 'foto_ancho' => null, 'foto_alto' => null,
        ]);

        $this->proponer($importacion);

        $producto->refresh();

        $this->assertNull($producto->foto_propuesta_url);
        $this->assertContains('foto', $producto->campos_dudosos);
    }

    // ------------------------------------------------------------------
    // El recuadro, cuando viene mal
    // ------------------------------------------------------------------

    public function test_un_recuadro_diminuto_no_produce_foto(): void
    {
        $recortador = app(RecortarFotoDelCatalogo::class);

        // 2% de la pagina: es un logo o un icono, no un zapato.
        $this->assertNull($recortador->recortar($this->paginaFalsa, [
            'foto_x' => 0.1, 'foto_y' => 0.1, 'foto_ancho' => 0.02, 'foto_alto' => 0.02,
        ]));
    }

    public function test_un_recuadro_imposible_no_produce_foto(): void
    {
        $recortador = app(RecortarFotoDelCatalogo::class);

        foreach ([
            ['foto_x' => -0.2, 'foto_y' => 0.1, 'foto_ancho' => 0.5, 'foto_alto' => 0.5],
            ['foto_x' => 0.1, 'foto_y' => 0.1, 'foto_ancho' => 0.0, 'foto_alto' => 0.5],
            ['foto_x' => 0.97, 'foto_y' => 0.1, 'foto_ancho' => 0.5, 'foto_alto' => 0.5],
            ['foto_x' => 'mucho', 'foto_y' => 0.1, 'foto_ancho' => 0.5, 'foto_alto' => 0.5],
        ] as $caja) {
            $this->assertNull($recortador->recortar($this->paginaFalsa, $caja), json_encode($caja));
        }
    }

    /** La IA tiende a pasarse un poco; perder la foto por eso sería tonto. */
    public function test_un_recuadro_que_se_pasa_tantito_se_recorta_al_borde(): void
    {
        $ruta = app(RecortarFotoDelCatalogo::class)->recortar($this->paginaFalsa, [
            'foto_x' => 0.1, 'foto_y' => 0.1, 'foto_ancho' => 0.95, 'foto_alto' => 0.3,
        ]);

        $this->assertNotNull($ruta);
        $this->assertLessThanOrEqual(600, getimagesize($ruta)[0]);
        @unlink($ruta);
    }

    // ------------------------------------------------------------------
    // Lo más importante: nada se publica solo
    // ------------------------------------------------------------------

    private function aprobar(ImportacionCatalogo $importacion): array
    {
        // Lo que hace el revisor antes de aprobar: dejar las filas en
        // "corregido" y decir a qué color de la paleta corresponde cada una.
        // Sin el color base, aprobar las salta.
        $negro = \App\Models\Color::query()->orderBy('id')->firstOrFail();

        foreach ($importacion->productosStaging as $fila) {
            $fila->update([
                'estado' => 'corregido',
                'datos_extraidos' => [...$fila->datos_extraidos, 'color_base_id' => $negro->id],
            ]);
        }

        return app(AprobarProductosImportadosAction::class)->ejecutar(
            $importacion,
            campanaId: \App\Models\Campana::query()->orderBy('id')->firstOrFail()->id,
            categoriaPorOmision: \App\Models\CategoriaProducto::query()->orderBy('id')->firstOrFail()->id,
            marcaPorOmision: \App\Models\Marca::query()->orderBy('id')->firstOrFail()->id,
        );
    }

    public function test_una_propuesta_que_nadie_miro_no_entra_al_catalogo(): void
    {
        $this->conRasterizador($this->paginaFalsa);

        $importacion = $this->importacion();
        $producto = $this->producto($importacion);
        $this->proponer($importacion);

        $this->assertSame('propuesta', $producto->refresh()->foto_estado);

        $this->aprobar($importacion);

        $producto->refresh();

        $this->assertNotNull($producto->producto_campana_creado_id, 'El producto sí debe aprobarse.');
        $this->assertSame(
            0,
            ProductoImagen::where('producto_campana_id', $producto->producto_campana_creado_id)->count(),
            'Una foto que nadie confirmó NO debe publicarse.'
        );
    }

    public function test_una_foto_confirmada_si_entra_al_catalogo_como_principal(): void
    {
        $this->conRasterizador($this->paginaFalsa);

        $importacion = $this->importacion();
        $producto = $this->producto($importacion);
        $this->proponer($importacion);

        $producto->update(['foto_estado' => 'confirmada']);

        $this->aprobar($importacion);

        $producto->refresh();

        $foto = ProductoImagen::where('producto_campana_id', $producto->producto_campana_creado_id)->first();

        $this->assertNotNull($foto, 'La foto confirmada debió publicarse.');
        $this->assertTrue($foto->es_principal);
    }

    public function test_descartar_la_foto_deja_aprobar_el_producto_igual(): void
    {
        $this->conRasterizador($this->paginaFalsa);

        $importacion = $this->importacion();
        $producto = $this->producto($importacion);
        $this->proponer($importacion);

        $producto->update(['foto_estado' => 'descartada']);

        $resumen = $this->aprobar($importacion);

        $this->assertSame(1, $resumen['aprobados']);
        $this->assertNotNull($producto->refresh()->producto_campana_creado_id);
    }

    // ------------------------------------------------------------------
    // La pantalla de revisión
    // ------------------------------------------------------------------

    private function revisar(ImportacionCatalogo $importacion)
    {
        return Livewire::actingAs(Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail())
            ->test('admin.revisar-catalogo', ['importacion' => $importacion->id]);
    }

    public function test_el_revisor_puede_quedarse_con_el_recorte(): void
    {
        $this->conRasterizador($this->paginaFalsa);

        $importacion = $this->importacion();
        $producto = $this->producto($importacion);
        $this->proponer($importacion);

        $this->revisar($importacion)->call('confirmarFoto', $producto->id);

        $this->assertSame('confirmada', $producto->refresh()->foto_estado);
    }

    public function test_el_revisor_puede_dejarlo_sin_foto(): void
    {
        $this->conRasterizador($this->paginaFalsa);

        $importacion = $this->importacion();
        $producto = $this->producto($importacion);
        $this->proponer($importacion);

        $this->revisar($importacion)->call('descartarFoto', $producto->id);

        $this->assertSame('descartada', $producto->refresh()->foto_estado);
    }

    public function test_el_revisor_puede_subir_otra_foto_y_queda_confirmada(): void
    {
        $importacion = $this->importacion();
        $producto = $this->producto($importacion);

        $this->revisar($importacion)
            ->call('pedirFoto', $producto->id)
            ->set('fotoNueva', UploadedFile::fake()->image('la-buena.jpg', 900, 700))
            ->call('subirFoto')
            ->assertHasNoErrors();

        $producto->refresh();

        // Si alguien la eligió a mano, no hay nada más que revisar.
        $this->assertSame('confirmada', $producto->foto_estado);
        $this->assertNotNull($producto->foto_propuesta_url);
    }

    public function test_la_pantalla_enseña_la_columna_de_la_foto(): void
    {
        $this->conRasterizador($this->paginaFalsa);

        $importacion = $this->importacion();
        $this->producto($importacion);
        $this->proponer($importacion);

        $this->revisar($importacion)
            ->assertSee('Foto')
            ->assertSeeHtml('wire:click="confirmarFoto(');
    }

    /** El id viaja desde el navegador: no debe tocar otra importación. */
    public function test_no_se_puede_tocar_la_foto_de_otra_importacion(): void
    {
        $this->conRasterizador($this->paginaFalsa);

        $mia = $this->importacion();
        $ajena = $this->importacion();
        $productoAjeno = $this->producto($ajena);

        $this->assertThrows(
            fn () => $this->revisar($mia)->call('confirmarFoto', $productoAjeno->id),
            \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        );
    }
}
