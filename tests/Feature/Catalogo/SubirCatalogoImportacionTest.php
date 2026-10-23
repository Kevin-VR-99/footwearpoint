<?php

namespace Tests\Feature\Catalogo;

use App\Jobs\ProcesarCatalogoConIa;
use App\Models\Auditoria;
use App\Models\ImportacionCatalogo;
use App\Models\Producto;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Services\Catalogo\SubirCatalogoParaImportarAction;
use App\Services\Ia\ProponerRecortesDeFotosAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-237 (A7 / E5-01) — Subir el catalogo de la fabrica para la IA.
 *
 * Solo el admin general. El archivo va a un disco PRIVADO, nunca al bucket
 * publico de las fotos de producto: un catalogo de fabrica es material del
 * proveedor.
 */
class SubirCatalogoImportacionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const DISCO = 'catalogos_de_prueba';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        // Disco falso: nada se escribe de verdad en el disco de la maquina.
        Storage::fake(self::DISCO);
        config(['filesystems.catalogos_disk' => self::DISCO]);
    }

    private function adminGeneral(): Usuario
    {
        return Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();
    }

    private function adminDeDistribuidora(): Usuario
    {
        return Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
    }

    private function pdfDePrueba(string $nombre = 'catalogo-impuls.pdf', int $kb = 500): UploadedFile
    {
        return UploadedFile::fake()->create($nombre, $kb, 'application/pdf');
    }

    public function test_el_admin_general_sube_un_catalogo_y_queda_listo_para_la_ia(): void
    {
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('temporada_id', \App\Models\Temporada::actual()->id)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir')
            ->assertHasNoErrors()
            ->assertSet('esError', false);

        $importacion = ImportacionCatalogo::with('campana')->latest('id')->firstOrFail();

        $this->assertSame(1, (int) $importacion->linea_id);
        $this->assertSame(1, (int) $importacion->campana->linea_id);
        $this->assertSame(\App\Models\Temporada::actual()->id, (int) $importacion->campana->temporada_id);
        $this->assertSame('pdf', $importacion->tipo_archivo);
        // "cargado" es lo que mira A8 para saber que hay trabajo pendiente.
        $this->assertSame('cargado', $importacion->estado);
        $this->assertSame($this->adminGeneral()->id, (int) $importacion->iniciada_por_usuario_id);

        Storage::disk(self::DISCO)->assertExists($importacion->archivo_url);
    }

    public function test_el_archivo_no_queda_en_el_disco_publico_de_las_fotos(): void
    {
        Storage::fake('productos');

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir')
            ->assertHasNoErrors();

        $ruta = ImportacionCatalogo::latest('id')->firstOrFail()->archivo_url;

        Storage::disk('productos')->assertMissing($ruta);
        Storage::disk(self::DISCO)->assertExists($ruta);
    }

    public function test_por_omision_entra_a_la_temporada_actual(): void
    {
        // La temporada es global: si la línea aún no tenía su publicación en
        // ella, se crea sola.
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->assertSet('temporada_id', \App\Models\Temporada::actual()->id)
            ->set('linea_id', 3)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir')
            ->assertHasNoErrors();

        $campana = ImportacionCatalogo::latest('id')->firstOrFail()->campana;
        $this->assertSame(3, (int) $campana->linea_id);
        $this->assertSame(\App\Models\Temporada::actual()->id, (int) $campana->temporada_id);
    }

    public function test_no_se_importa_a_una_temporada_pasada(): void
    {
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('temporada_id', \App\Models\Temporada::actual()->anterior()->id)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir')
            ->assertHasErrors(['temporada_id']);
    }

    public function test_un_admin_de_distribuidora_no_puede_ni_abrir_la_pantalla(): void
    {
        $this->actingAs($this->adminDeDistribuidora())
            ->get('/admin/catalogos')
            ->assertForbidden();
    }

    public function test_un_admin_de_distribuidora_tampoco_puede_subir_nada(): void
    {
        $this->actingAs($this->adminDeDistribuidora());

        Livewire::test('admin.catalogos-importaciones')
            ->assertForbidden();

        $this->assertSame(0, ImportacionCatalogo::count());
    }

    public function test_sin_iniciar_sesion_manda_al_login(): void
    {
        $this->get('/admin/catalogos')->assertRedirect(route('login'));
    }

    public function test_se_rechaza_un_archivo_que_no_es_catalogo(): void
    {
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', UploadedFile::fake()->create('programa.exe', 10))
            ->call('subir')
            ->assertHasErrors(['archivo' => 'mimes']);

        $this->assertSame(0, ImportacionCatalogo::count());
    }

    public function test_se_rechaza_un_archivo_mas_grande_que_el_tope(): void
    {
        $this->actingAs($this->adminGeneral());

        $componente = Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', $this->pdfDePrueba('enorme.pdf', SubirCatalogoParaImportarAction::MAXIMO_KB + 1024))
            ->call('subir')
            ->assertHasErrors(['archivo' => 'max']);

        // Y el aviso se entiende, en espanol: el tope duro de Livewire queda
        // mas arriba justamente para que el que salga sea este.
        $this->assertStringContainsString(
            'pesa más de 250 MB',
            $componente->errors()->first('archivo')
        );

        $this->assertSame(0, ImportacionCatalogo::count());
    }

    public function test_un_catalogo_de_28_mb_si_pasa(): void
    {
        // Los catalogos reales de la fabrica pesan eso; con el tope de
        // fabrica de Livewire (12 MB) se rechazaban antes de llegar aqui.
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', $this->pdfDePrueba('confort-76-paginas.pdf', 28 * 1024))
            ->call('subir')
            ->assertHasNoErrors();

        $this->assertSame(1, ImportacionCatalogo::count());
    }

    public function test_hay_que_decir_a_que_linea_pertenece(): void
    {
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir')
            ->assertHasErrors(['linea_id' => 'required']);
    }

    public function test_la_carga_queda_en_la_bitacora_de_auditoria(): void
    {
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', $this->pdfDePrueba('impuls-oi26.pdf'))
            ->call('subir')
            ->assertHasNoErrors();

        $registro = Auditoria::withoutGlobalScopes()
            ->where('accion', 'importacion_catalogo.cargada')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($this->adminGeneral()->id, $registro->usuario_id);
        $this->assertEquals(
            ['archivo' => 'impuls-oi26.pdf', 'linea_id' => 1],
            $registro->datos_nuevos
        );
    }

    public function test_se_puede_quitar_un_catalogo_que_todavia_no_se_proceso(): void
    {
        $this->actingAs($this->adminGeneral());

        $componente = Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir');

        $importacion = ImportacionCatalogo::latest('id')->firstOrFail();
        $ruta = $importacion->archivo_url;

        $componente->call('eliminar', $importacion->id)->assertSet('esError', false);

        $this->assertSame(0, ImportacionCatalogo::count());
        Storage::disk(self::DISCO)->assertMissing($ruta);
    }

    public function test_se_puede_quitar_uno_ya_leido_sin_tocar_lo_publicado(): void
    {
        $this->actingAs($this->adminGeneral());
        Storage::fake('s3');

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir');

        $importacion = ImportacionCatalogo::latest('id')->firstOrFail();
        $importacion->update(['estado' => 'requiere_revision']);
        $rutaPdf = $importacion->archivo_url;

        $producto = Producto::query()->firstOrFail();
        $recorte = ProponerRecortesDeFotosAction::CARPETA.'/prueba.webp';
        $fotoPublicada = 'productos/campana/imagenes/queda.webp';
        Storage::disk('s3')->put($recorte, 'recorte');
        Storage::disk('s3')->put($fotoPublicada, 'foto');

        ProductoImportadoStaging::create([
            'importacion_id' => $importacion->id,
            'datos_extraidos' => ['modelo' => 'publicado'],
            'estado' => 'aprobado',
            'producto_creado_id' => $producto->id,
            'foto_propuesta_url' => Storage::disk('s3')->url($recorte),
            'foto_estado' => 'confirmada',
        ]);
        ProductoImportadoStaging::create([
            'importacion_id' => $importacion->id,
            'datos_extraidos' => ['modelo' => 'solo-propuesta'],
            'estado' => 'pendiente',
        ]);

        Livewire::test('admin.catalogos-importaciones')
            ->call('pedirQuitar', $importacion->id)
            ->assertSee('1 producto que ya se publicó se queda')
            ->call('cancelarConfirmacion')
            ->assertDontSee('1 producto que ya se publicó se queda');

        $this->assertSame(1, ImportacionCatalogo::count());
        Storage::disk(self::DISCO)->assertExists($rutaPdf);

        Livewire::test('admin.catalogos-importaciones')
            ->call('pedirQuitar', $importacion->id)
            ->call('confirmarQuitar')
            ->assertSet('esError', false)
            ->assertSee('1 producto ya publicado sigue');

        $this->assertSame(0, ImportacionCatalogo::count());
        $this->assertSame(0, ProductoImportadoStaging::count());
        $this->assertNotNull($producto->fresh());
        Storage::disk(self::DISCO)->assertMissing($rutaPdf);
        Storage::disk('s3')->assertMissing($recorte);
        Storage::disk('s3')->assertExists($fotoPublicada);
    }

    public function test_no_se_puede_quitar_uno_que_se_esta_leyendo(): void
    {
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir');

        $importacion = ImportacionCatalogo::latest('id')->firstOrFail();
        $importacion->update(['estado' => 'procesando']);

        Livewire::test('admin.catalogos-importaciones')
            ->assertDontSee('wire:confirm')
            ->assertDontSee('>Quitar<')
            ->call('pedirQuitar', $importacion->id)
            ->assertSet('esError', true)
            ->call('eliminar', $importacion->id)
            ->assertSet('esError', true);

        $this->assertSame(1, ImportacionCatalogo::count());
        Storage::disk(self::DISCO)->assertExists($importacion->archivo_url);
    }

    public function test_procesar_con_ia_pide_modal_y_cancelar_no_lee(): void
    {
        $this->actingAs($this->adminGeneral());
        Queue::fake();

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir');

        $importacion = ImportacionCatalogo::latest('id')->firstOrFail();

        Livewire::test('admin.catalogos-importaciones')
            ->assertDontSee('wire:confirm')
            ->assertSee('Procesar con IA')
            ->call('pedirProcesar', $importacion->id)
            ->assertSee('cuesta dinero de verdad')
            ->call('cancelarConfirmacion')
            ->assertDontSee('cuesta dinero de verdad')
            ->assertSet('confirmarLecturaId', null);

        $this->assertSame('cargado', $importacion->fresh()->estado);
        Queue::assertNothingPushed();

        Livewire::test('admin.catalogos-importaciones')
            ->call('pedirProcesar', $importacion->id)
            ->call('confirmarProcesar')
            ->assertSet('esError', false);

        $this->assertSame('procesando', $importacion->fresh()->estado);
        Queue::assertPushed(ProcesarCatalogoConIa::class);
    }

    public function test_el_archivo_se_sirve_solo_al_admin_general(): void
    {
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir');

        $importacion = ImportacionCatalogo::latest('id')->firstOrFail();
        $direccion = route('admin.catalogos.archivo', $importacion->id);

        $this->actingAs($this->adminGeneral())->get($direccion)->assertOk();
        $this->actingAs($this->adminDeDistribuidora())->get($direccion)->assertForbidden();
    }

    public function test_ver_archivo_abre_un_visor_en_la_misma_pagina(): void
    {
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir');

        $importacion = ImportacionCatalogo::latest('id')->firstOrFail();
        $direccion = route('admin.catalogos.archivo', $importacion->id);

        $html = Livewire::test('admin.catalogos-importaciones')->html();
        $this->assertStringContainsString('data-visor-archivo', $html);
        $this->assertStringContainsString('data-ver-archivo', $html);
        $this->assertStringContainsString('abrir-visor', $html);
        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringContainsString('x-on:keydown.escape.window="abierto = false"', $html);
        $this->assertStringContainsString('x-on:click.self="abierto = false"', $html);
        // Ya no es un enlace que abre otra página.
        $this->assertStringNotContainsString('href="'.$direccion.'"', $html);

        $respuesta = $this->get($direccion)->assertOk();
        $this->assertStringStartsWith('inline', $respuesta->headers->get('Content-Disposition'));
        $this->assertSame('SAMEORIGIN', $respuesta->headers->get('X-Frame-Options'));
    }

    public function test_se_rechaza_una_imagen_porque_la_ia_solo_lee_pdf(): void
    {
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', UploadedFile::fake()->image('catalogo.jpg'))
            ->call('subir')
            ->assertHasErrors(['archivo' => 'mimes']);

        $this->assertSame(0, ImportacionCatalogo::count());
    }

    public function test_la_pantalla_acepta_hasta_250_mb_y_muestra_el_avance_de_la_lectura(): void
    {
        $this->actingAs($this->adminGeneral());

        $html = Livewire::test('admin.catalogos-importaciones')->html();
        $this->assertStringContainsString('Hasta 250 MB', $html);
        $this->assertStringContainsString('livewire-upload-progress', $html);

        $importacion = ImportacionCatalogo::create([
            'linea_id' => 1,
            'archivo_url' => 'catalogos-ia/2026/10/en-curso.pdf',
            'tipo_archivo' => 'pdf',
            'estado' => 'procesando',
            'paginas' => 76,
            'paginas_leidas' => 20,
            'leyendo_hasta' => 30,
            'iniciada_por_usuario_id' => $this->adminGeneral()->id,
        ]);

        Livewire::test('admin.catalogos-importaciones')
            ->assertSee('Van 20 de 76 páginas')
            ->assertSee('Faltan 56')
            ->assertSee('Leyendo hasta la página 30')
            ->assertSee('20 / 76')
            ->assertSee('width: 26%')
            ->assertDontSee('Puede haberse trabado');

        $limite = max(60, (int) config('ia.segundos_por_peticion')) + 180;
        ImportacionCatalogo::where('id', $importacion->id)->update([
            'updated_at' => now()->subSeconds($limite),
        ]);

        Livewire::test('admin.catalogos-importaciones')
            ->assertSee('Puede haberse trabado')
            ->assertSee('no la reintentes');
    }
}
