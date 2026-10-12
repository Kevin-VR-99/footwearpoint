<?php

namespace Tests\Feature\Catalogo;

use App\Models\Auditoria;
use App\Models\ImportacionCatalogo;
use App\Models\Usuario;
use App\Services\Catalogo\SubirCatalogoParaImportarAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        return Usuario::where('email', 'admin.general@footwearpoint.test')->firstOrFail();
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
            ->set('campana_id', 1)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir')
            ->assertHasNoErrors()
            ->assertSet('esError', false);

        $importacion = ImportacionCatalogo::latest('id')->firstOrFail();

        $this->assertSame(1, (int) $importacion->linea_id);
        $this->assertSame(1, (int) $importacion->campana_id);
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

    public function test_la_temporada_es_opcional(): void
    {
        // Se puede subir el catalogo antes de decidir a que temporada entra.
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 3)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir')
            ->assertHasNoErrors();

        $this->assertNull(ImportacionCatalogo::latest('id')->firstOrFail()->campana_id);
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
            'pesa más de 40 MB',
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

    public function test_al_cambiar_de_linea_se_olvida_la_temporada_elegida(): void
    {
        // Las temporadas son de una linea: dejar la anterior mezclaria
        // el catalogo de una linea con la temporada de otra.
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('campana_id', 1)
            ->set('linea_id', 3)
            ->assertSet('campana_id', null);
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

    public function test_no_se_puede_quitar_uno_que_la_ia_ya_proceso(): void
    {
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->set('linea_id', 1)
            ->set('archivo', $this->pdfDePrueba())
            ->call('subir');

        $importacion = ImportacionCatalogo::latest('id')->firstOrFail();
        $importacion->update(['estado' => 'requiere_revision']);

        Livewire::test('admin.catalogos-importaciones')
            ->call('eliminar', $importacion->id)
            ->assertSet('esError', true);

        $this->assertSame(1, ImportacionCatalogo::count());
        Storage::disk(self::DISCO)->assertExists($importacion->archivo_url);
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
}
