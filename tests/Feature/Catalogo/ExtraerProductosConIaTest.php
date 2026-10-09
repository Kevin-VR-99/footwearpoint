<?php

namespace Tests\Feature\Catalogo;

use App\Jobs\ProcesarCatalogoConIa;
use App\Models\Auditoria;
use App\Models\ImportacionCatalogo;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Services\Catalogo\SubirCatalogoParaImportarAction;
use App\Services\Ia\ContarPaginasPdf;
use App\Services\Ia\ExtraerProductosDelCatalogoAction;
use App\Services\Ia\LectorDeCatalogos;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Apoyo\LectorDeCatalogosFalso;
use Tests\TestCase;

/**
 * TG-238 (A8 / E5-02) — Leer el catálogo con la IA en bloques de páginas.
 *
 * Ninguna de estas pruebas llama al API de verdad: usan un lector falso, así
 * que no cuestan dinero ni necesitan la llave.
 */
class ExtraerProductosConIaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const DISCO = 'catalogos_de_prueba';

    private LectorDeCatalogosFalso $lector;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        Storage::fake(self::DISCO);
        config(['filesystems.catalogos_disk' => self::DISCO]);
        config(['ia.paginas_por_bloque' => 10]);

        $this->lector = new LectorDeCatalogosFalso([
            3  => ['marca' => 'Impuls', 'modelo' => '355-37', 'color' => 'Miel', 'precio_publico' => 699.0, 'dudosos' => []],
            7  => ['marca' => null, 'modelo' => '355-38', 'color' => 'Negro', 'precio_publico' => 629.0, 'dudosos' => ['marca']],
            14 => ['marca' => 'Impuls', 'modelo' => '355-39', 'color' => 'Hueso', 'precio_publico' => 749.0, 'dudosos' => []],
            23 => ['marca' => 'Impuls', 'modelo' => '355-40', 'color' => 'Azul', 'precio_publico' => 799.0, 'dudosos' => ['precio_publico']],
        ]);

        $this->app->instance(LectorDeCatalogos::class, $this->lector);
    }

    private function adminGeneral(): Usuario
    {
        return Usuario::where('email', 'admin.general@footwearpoint.test')->firstOrFail();
    }

    /** Un PDF con el número de páginas que se le pida, como los de verdad. */
    private function pdfDe(int $paginas): string
    {
        $pdf = "%PDF-1.6\n";
        $pdf .= "1 0 obj\n<< /Type /Pages /Kids [] /Count {$paginas} >>\nendobj\n";

        for ($i = 0; $i < $paginas; $i++) {
            $pdf .= ($i + 2)." 0 obj\n<< /Type /Page /Parent 1 0 R >>\nendobj\n";
        }

        return $pdf."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function importacionConCatalogoDe(int $paginas): ImportacionCatalogo
    {
        $ruta = 'catalogos-ia/2026/10/prueba.pdf';
        Storage::disk(self::DISCO)->put($ruta, $this->pdfDe($paginas));

        return ImportacionCatalogo::create([
            'linea_id' => 1,
            'archivo_url' => $ruta,
            'tipo_archivo' => 'pdf',
            'estado' => 'cargado',
            'iniciada_por_usuario_id' => $this->adminGeneral()->id,
        ]);
    }

    private function accion(): ExtraerProductosDelCatalogoAction
    {
        return app(ExtraerProductosDelCatalogoAction::class);
    }

    // ------------------------------------------------------------------
    // Contar las páginas
    // ------------------------------------------------------------------

    public function test_cuenta_las_paginas_de_las_dos_maneras_que_usa_un_pdf(): void
    {
        $contador = new ContarPaginasPdf;

        // Como el Impuls real: el total declarado, sin objetos de página a la vista.
        $this->assertSame(122, $contador->contar("%PDF-1.6\n<< /Type /Pages /Count 122 >>"));

        // Como el Confort real: sin total declarado, pero con sus objetos.
        $soloObjetos = str_repeat("<< /Type /Page /Parent 1 0 R >>\n", 76);
        $this->assertSame(76, $contador->contar("%PDF-1.7\n".$soloObjetos));

        // Y no confunde "/Pages" con "/Page".
        $this->assertSame(0, $contador->contar("%PDF-1.4\n<< /Type /Pages /Kids [] >>"));
    }

    // ------------------------------------------------------------------
    // El recorrido completo
    // ------------------------------------------------------------------

    public function test_lee_el_catalogo_por_bloques_y_deja_los_productos_para_revisar(): void
    {
        $importacion = $this->importacionConCatalogoDe(25);

        $this->accion()->ejecutar($importacion);

        // 25 páginas de 10 en 10: tres bloques, el último más corto.
        $this->assertSame(
            [['desde' => 1, 'hasta' => 10], ['desde' => 11, 'hasta' => 20], ['desde' => 21, 'hasta' => 25]],
            $this->lector->bloquesPedidos
        );

        $importacion->refresh();
        $this->assertSame(25, (int) $importacion->paginas);
        // Nadie publica nada: queda esperando a una persona.
        $this->assertSame('requiere_revision', $importacion->estado);
        $this->assertSame(4, $importacion->productosStaging()->count());
    }

    public function test_el_catalogo_se_sube_una_sola_vez_aunque_haya_muchos_bloques(): void
    {
        // Es la razón de todo el diseño: mandar 29 MB una vez y no trece.
        $this->accion()->ejecutar($this->importacionConCatalogoDe(50));

        $this->assertSame(1, $this->lector->vecesQueSubio);
        $this->assertCount(5, $this->lector->bloquesPedidos);
    }

    public function test_guarda_lo_que_la_ia_marco_como_dudoso(): void
    {
        // Es lo que va a mirar primero quien revise (A9).
        $importacion = $this->importacionConCatalogoDe(25);

        $this->accion()->ejecutar($importacion);

        $sinMarca = ProductoImportadoStaging::where('importacion_id', $importacion->id)
            ->get()
            ->first(fn ($p) => $p->datos_extraidos['modelo'] === '355-38');

        $this->assertNotNull($sinMarca);
        $this->assertSame(['marca'], $sinMarca->campos_dudosos);
        $this->assertSame('pendiente', $sinMarca->estado);
        // "dudosos" no se guarda dentro de los datos del producto.
        $this->assertArrayNotHasKey('dudosos', $sinMarca->datos_extraidos);
        $this->assertSame(7, $sinMarca->datos_extraidos['pagina']);
    }

    public function test_anota_cuanto_costo_la_importacion(): void
    {
        $importacion = $this->importacionConCatalogoDe(25);

        $this->accion()->ejecutar($importacion);

        $importacion->refresh();

        $this->assertSame('anthropic', $importacion->proveedor_ia);
        $this->assertSame('claude-opus-5-5', $importacion->modelo_ia);
        $this->assertSame(12000, (int) $importacion->tokens_salida);

        // Tres bloques: uno escribe el documento en el caché y dos lo leen.
        // 119653*8 + 119653*0.20*2 + 341*4*3 + 12000*20, todo por millón.
        $esperado = round((119653 * 8 + 119653 * 0.20 * 2 + 341 * 4 * 3 + 12000 * 20) / 1_000_000, 4);
        $this->assertEqualsWithDelta($esperado, (float) $importacion->costo_usd, 0.0001);
    }

    public function test_el_costo_se_va_guardando_bloque_a_bloque(): void
    {
        // Si el proceso muere a la mitad, tiene que quedar registrado lo ya gastado.
        $importacion = $this->importacionConCatalogoDe(30);
        $this->lector->fallaEnElBloqueQueEmpiezaEn = 21;

        $this->accion()->ejecutar($importacion);

        $importacion->refresh();
        $this->assertSame('error', $importacion->estado);
        $this->assertGreaterThan(0, (float) $importacion->costo_usd);
        // Y lo de los dos primeros bloques no se perdió.
        $this->assertSame(3, $importacion->productosStaging()->count());
    }

    public function test_borra_el_catalogo_del_proveedor_al_terminar(): void
    {
        $this->accion()->ejecutar($this->importacionConCatalogoDe(15));

        $this->assertTrue($this->lector->borrado, 'El archivo se quedó en el proveedor.');
    }

    public function test_tambien_lo_borra_cuando_algo_falla(): void
    {
        $importacion = $this->importacionConCatalogoDe(15);
        $this->lector->fallaEnElBloqueQueEmpiezaEn = 1;

        $this->accion()->ejecutar($importacion);

        $this->assertTrue($this->lector->borrado, 'Falló y el archivo se quedó en el proveedor.');
        $this->assertSame('error', $importacion->fresh()->estado);
    }

    public function test_si_falla_el_aviso_se_entiende_y_no_trae_tecnicismos(): void
    {
        $importacion = $this->importacionConCatalogoDe(15);
        $this->lector->fallaEnElBloqueQueEmpiezaEn = 1;

        $this->accion()->ejecutar($importacion);

        $mensaje = $importacion->fresh()->mensaje_error;
        $this->assertNotEmpty($mensaje);
        $this->assertStringNotContainsString('Exception', $mensaje);
        $this->assertStringNotContainsString('Stack', $mensaje);
    }

    public function test_un_archivo_que_no_se_puede_leer_avisa_sin_procesar_nada(): void
    {
        $ruta = 'catalogos-ia/2026/10/roto.pdf';
        Storage::disk(self::DISCO)->put($ruta, 'esto no es un PDF');

        $importacion = ImportacionCatalogo::create([
            'linea_id' => 1,
            'archivo_url' => $ruta,
            'tipo_archivo' => 'pdf',
            'estado' => 'cargado',
            'iniciada_por_usuario_id' => $this->adminGeneral()->id,
        ]);

        $this->accion()->ejecutar($importacion);

        $importacion->refresh();
        $this->assertSame('error', $importacion->estado);
        $this->assertStringContainsString('páginas', $importacion->mensaje_error);
        // Ni se subió a la IA ni se cobró nada.
        $this->assertSame(0, $this->lector->vecesQueSubio);
        $this->assertSame(0, $importacion->productosStaging()->count());
    }

    public function test_queda_en_la_bitacora_de_auditoria(): void
    {
        $importacion = $this->importacionConCatalogoDe(25);

        $this->accion()->ejecutar($importacion);

        $registro = Auditoria::withoutGlobalScopes()
            ->where('accion', 'importacion_catalogo.procesada')
            ->latest('id')
            ->firstOrFail();

        $this->assertEquals(25, $registro->datos_nuevos['paginas']);
        $this->assertEquals(4, $registro->datos_nuevos['productos']);
    }

    // ------------------------------------------------------------------
    // La pantalla
    // ------------------------------------------------------------------

    public function test_el_boton_manda_el_trabajo_a_la_cola_y_no_lo_hace_en_la_peticion(): void
    {
        // Es lo que pidió el líder: en una petición web no cabe, el servidor
        // corta a los 30 segundos.
        Queue::fake();

        $importacion = $this->importacionConCatalogoDe(25);

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->call('procesar', $importacion->id)
            ->assertSet('esError', false);

        Queue::assertPushed(ProcesarCatalogoConIa::class,
            fn ($job) => $job->importacionId === $importacion->id);

        $this->assertSame('procesando', $importacion->fresh()->estado);
    }

    public function test_no_se_puede_procesar_dos_veces_al_mismo_tiempo(): void
    {
        Queue::fake();

        $importacion = $this->importacionConCatalogoDe(25);
        $importacion->update(['estado' => 'procesando']);

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->call('procesar', $importacion->id)
            ->assertSet('esError', true);

        Queue::assertNothingPushed();
    }

    public function test_reintentar_limpia_lo_del_intento_anterior(): void
    {
        Queue::fake();

        $importacion = $this->importacionConCatalogoDe(25);
        $importacion->update(['estado' => 'error', 'mensaje_error' => 'algo salió mal']);
        ProductoImportadoStaging::create([
            'importacion_id' => $importacion->id,
            'datos_extraidos' => ['modelo' => 'viejo'],
            'estado' => 'pendiente',
        ]);

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->call('procesar', $importacion->id)
            ->assertSet('esError', false);

        // Si no se limpiara, quedarían productos repetidos de los dos intentos.
        $this->assertSame(0, $importacion->productosStaging()->count());
        $this->assertNull($importacion->fresh()->mensaje_error);
    }

    public function test_un_admin_de_distribuidora_no_puede_mandar_a_procesar(): void
    {
        Queue::fake();

        $this->importacionConCatalogoDe(25);

        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());

        Livewire::test('admin.catalogos-importaciones')->assertForbidden();

        Queue::assertNothingPushed();
    }
}
