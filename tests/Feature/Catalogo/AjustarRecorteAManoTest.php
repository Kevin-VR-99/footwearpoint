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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-377 (A17) — Corregir el recorte a mano.
 *
 * La otra mitad del «listo cuando» de la tarjeta. Cuando la IA apunta mal, o
 * recorta de menos, tiene que poder arreglarse sin volver a leer el catálogo
 * (que cuesta dinero) y sin salir a buscar la foto por fuera.
 *
 * Aquí NO hace falta poppler: la página se deja puesta a mano en el disco,
 * que es justo lo que haría el dibujado. Así se puede probar el recorte
 * completo en una máquina que no tiene pdftoppm.
 */
class AjustarRecorteAManoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private ImportacionCatalogo $importacion;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Storage::fake('s3');

        $campana = Campana::firstOrFail();

        $this->importacion = ImportacionCatalogo::create([
            'linea_id' => $campana->linea_id,
            'campana_id' => $campana->id,
            'archivo_url' => 'catalogos-ia/2026/11/prueba.pdf',
            'tipo_archivo' => 'pdf',
            'estado' => 'requiere_revision',
            'paginas' => 10,
            'iniciada_por_usuario_id' => $this->adminGeneral()->id,
        ]);
    }

    private function adminGeneral(): Usuario
    {
        return Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail();
    }

    /** @param  array<string, mixed>  $datos */
    private function producto(array $datos = []): ProductoImportadoStaging
    {
        return ProductoImportadoStaging::create([
            'importacion_id' => $this->importacion->id,
            'datos_extraidos' => array_merge([
                'pagina' => 3,
                'codigo_catalogo' => '0526-10',
                'modelo' => 'babito',
                'foto_x' => 0.30, 'foto_y' => 0.30, 'foto_ancho' => 0.20, 'foto_alto' => 0.20,
            ], $datos),
            'campos_dudosos' => ['foto'],
            'estado' => 'pendiente',
        ]);
    }

    /** Deja la página ya dibujada donde la buscaría el sistema. */
    private function dejarLaPaginaPuesta(int $pagina = 3): void
    {
        $img = imagecreatetruecolor(400, 400);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledellipse($img, 200, 200, 120, 120, imagecolorallocate($img, 0, 0, 0));

        $temporal = tempnam(sys_get_temp_dir(), 'pag').'.png';
        imagepng($img, $temporal);
        imagedestroy($img);

        Storage::disk(SubirCatalogoParaImportarAction::disco())->put(
            app(PaginaDelCatalogo::class)->ruta($this->importacion, $pagina),
            (string) file_get_contents($temporal)
        );

        @unlink($temporal);
    }

    private function pantalla()
    {
        return Livewire::actingAs($this->adminGeneral())
            ->test('admin.revisar-catalogo', ['importacion' => $this->importacion->id]);
    }

    // --- Abrir el editor ----------------------------------------------------

    /**
     * Arranca del recuadro que produjo la foto que se está viendo.
     *
     * O sea el de la IA CON su aire, no el crudo. Si arrancara del crudo, el
     * recuadro saldría más apretado que la foto de al lado y nadie entendería
     * por qué no coinciden.
     */
    public function test_el_editor_abre_con_el_recuadro_que_se_uso_de_verdad(): void
    {
        $producto = $this->producto();

        $this->pantalla()
            ->call('ajustarRecorte', $producto->id)
            ->assertSet('recortandoDe', $producto->id)
            ->assertSet('recortePagina', 3)
            // 0.20 de ancho con 8% de aire por lado = 0.232, y la x baja 0.016.
            // Con tolerancia: son decimales, y el ultimo bit no significa nada.
            ->assertSet('recorteCaja.ancho', fn ($v) => abs($v - 0.232) < 0.0001)
            ->assertSet('recorteCaja.x', fn ($v) => abs($v - 0.284) < 0.0001);
    }

    /** Sin recuadro de la IA se puede encuadrar igual: justo esos hay que arreglar. */
    public function test_un_producto_sin_recuadro_tambien_se_puede_encuadrar(): void
    {
        $producto = $this->producto([
            'foto_x' => null, 'foto_y' => null, 'foto_ancho' => null, 'foto_alto' => null,
        ]);

        $this->pantalla()
            ->call('ajustarRecorte', $producto->id)
            ->assertSet('recortandoDe', $producto->id)
            ->assertSet('recorteCaja.ancho', 0.4);
    }

    /** Sin saber de qué página salió no hay nada que enseñar; se dice y ya. */
    public function test_sin_pagina_lo_explica_en_vez_de_abrir_vacio(): void
    {
        $producto = $this->producto(['pagina' => null]);

        $this->pantalla()
            ->call('ajustarRecorte', $producto->id)
            ->assertSet('recortandoDe', null)
            ->assertSet('esError', true);
    }

    // --- Guardar ------------------------------------------------------------

    public function test_guardar_recorta_con_el_recuadro_nuevo_y_lo_deja_confirmado(): void
    {
        $this->dejarLaPaginaPuesta();
        $producto = $this->producto();

        $this->pantalla()
            ->call('ajustarRecorte', $producto->id)
            ->set('recorteCaja', ['x' => 0.1, 'y' => 0.1, 'ancho' => 0.5, 'alto' => 0.5])
            ->call('guardarRecorte')
            ->assertSet('recortandoDe', null)
            ->assertSet('esError', false);

        $producto->refresh();

        $this->assertNotNull($producto->foto_propuesta_url, 'No quedó foto después de recortar.');
        $this->assertSame('confirmada', $producto->foto_estado);
    }

    /**
     * «foto» deja de ser dudoso.
     *
     * Lo marcó la IA porque no estaba segura. Una persona acaba de encuadrarla
     * mirándola: seguir señalándola como dudosa haría ruido en la revisión.
     */
    public function test_al_encuadrar_a_mano_la_foto_deja_de_ser_dudosa(): void
    {
        $this->dejarLaPaginaPuesta();
        $producto = $this->producto();

        $this->assertContains('foto', $producto->campos_dudosos);

        $this->pantalla()
            ->call('ajustarRecorte', $producto->id)
            ->set('recorteCaja', ['x' => 0.2, 'y' => 0.2, 'ancho' => 0.4, 'alto' => 0.4])
            ->call('guardarRecorte');

        $this->assertNotContains('foto', $producto->refresh()->campos_dudosos);
    }

    /**
     * El recuadro llega del navegador, así que no se cree.
     *
     * Un recuadro de la nada haría que GD devolviera basura o nada, y se
     * guardaría una «foto» que no es nada.
     */
    public function test_un_recuadro_ridiculo_se_rechaza_con_un_mensaje(): void
    {
        $this->dejarLaPaginaPuesta();
        $producto = $this->producto();

        $this->pantalla()
            ->call('ajustarRecorte', $producto->id)
            ->set('recorteCaja', ['x' => 0.5, 'y' => 0.5, 'ancho' => 0.001, 'alto' => 0.001])
            ->call('guardarRecorte')
            ->assertSet('esError', true);

        $this->assertNull($producto->refresh()->foto_propuesta_url);
    }

    /** Un recuadro que se sale de la hoja se acomoda, no revienta. */
    public function test_un_recuadro_que_se_sale_se_acomoda_a_la_pagina(): void
    {
        $this->dejarLaPaginaPuesta();
        $producto = $this->producto();

        $this->pantalla()
            ->call('ajustarRecorte', $producto->id)
            ->set('recorteCaja', ['x' => 0.8, 'y' => 0.8, 'ancho' => 0.9, 'alto' => 0.9])
            ->call('guardarRecorte')
            ->assertSet('esError', false);

        $this->assertNotNull($producto->refresh()->foto_propuesta_url);
    }

    /** Sin la página dibujada se dice, en vez de guardar una foto vacía. */
    public function test_sin_la_pagina_avisa_y_no_guarda_nada(): void
    {
        $producto = $this->producto();

        $this->pantalla()
            ->call('ajustarRecorte', $producto->id)
            ->set('recorteCaja', ['x' => 0.1, 'y' => 0.1, 'ancho' => 0.4, 'alto' => 0.4])
            ->call('guardarRecorte')
            ->assertSet('esError', true);

        $this->assertNull($producto->refresh()->foto_propuesta_url);
    }

    // --- La página, por su ruta privada --------------------------------------

    public function test_la_pagina_no_se_sirve_sin_sesion(): void
    {
        $this->get(route('admin.catalogos.pagina', ['importacion' => $this->importacion->id, 'pagina' => 3]))
            ->assertRedirect(route('login'));
    }

    /** Una página fuera del catálogo es una dirección escrita a mano. */
    public function test_una_pagina_que_no_existe_responde_404(): void
    {
        $this->actingAs($this->adminGeneral())
            ->get(route('admin.catalogos.pagina', ['importacion' => $this->importacion->id, 'pagina' => 99]))
            ->assertNotFound();
    }

    public function test_la_pagina_ya_dibujada_se_sirve_y_no_se_vuelve_a_dibujar(): void
    {
        $this->dejarLaPaginaPuesta(2);

        $this->actingAs($this->adminGeneral())
            ->get(route('admin.catalogos.pagina', ['importacion' => $this->importacion->id, 'pagina' => 2]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }
}
