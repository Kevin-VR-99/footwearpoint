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
        return Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail();
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

    public function test_mientras_la_ia_analiza_la_pantalla_mantiene_viva_la_sesion(): void
    {
        // Gabriel: la sesión caducaba con la pestaña en segundo plano mientras
        // la IA seguía leyendo. El poll con keep-alive no se frena en segundo
        // plano y cada consulta renueva la sesión.
        $importacion = $this->importacionConCatalogoDe(25);
        $importacion->update(['estado' => 'procesando']);

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->assertSeeHtml('wire:poll.5s.keep-alive')
            ->assertSeeHtml('data-mantiene-sesion');
    }

    public function test_sin_analisis_en_curso_no_se_mantiene_viva_la_sesion(): void
    {
        // No se alarga la sesión en general: sin trabajo no hay poll.
        $this->importacionConCatalogoDe(25);

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->assertDontSeeHtml('wire:poll')
            ->assertDontSeeHtml('data-mantiene-sesion');
    }

    public function test_la_consulta_del_poll_renueva_la_sesion(): void
    {
        $importacion = $this->importacionConCatalogoDe(25);
        $importacion->update(['estado' => 'procesando']);

        $this->actingAs($this->adminGeneral());

        // Un refresco (lo que hace el poll) pasa por la sesión y la deja viva.
        Livewire::test('admin.catalogos-importaciones')
            ->call('$refresh')
            ->assertSeeHtml('wire:poll.5s.keep-alive');

        $this->assertAuthenticatedAs($this->adminGeneral());
    }

    public function test_si_un_reinicio_del_worker_corta_la_lectura_queda_en_error_y_se_puede_reintentar(): void
    {
        // Lo que le pasó a «2 Cklass»: el worker se redesplegó a media lectura
        // y, al volver, la tarea ya contaba un intento (tries = 1).
        Queue::fake();

        $importacion = $this->importacionConCatalogoDe(76);
        $importacion->update(['estado' => 'procesando']);

        (new ProcesarCatalogoConIa($importacion->id))
            ->failed(new \Illuminate\Queue\MaxAttemptsExceededException('attempted too many times'));

        $importacion->refresh();
        $this->assertSame('error', $importacion->estado);
        $this->assertStringContainsString('servidor se reinició', $importacion->mensaje_error);

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')
            ->assertSee('Reintentar con IA')
            ->call('procesar', $importacion->id)
            ->assertSet('esError', false);

        Queue::assertPushed(ProcesarCatalogoConIa::class,
            fn ($job) => $job->importacionId === $importacion->id);
        $this->assertSame('procesando', $importacion->fresh()->estado);
    }

    public function test_la_cola_no_da_por_perdido_un_catalogo_que_sigue_leyendose(): void
    {
        // retry_after menor que el timeout = la cola vuelve a entregar una
        // tarea que sigue corriendo y con tries = 1 termina en error.
        $timeout = (new ProcesarCatalogoConIa(1))->timeout;

        $this->assertGreaterThan($timeout, (int) config('queue.connections.database.retry_after'));
    }

    public function test_un_admin_de_distribuidora_no_puede_mandar_a_procesar(): void
    {
        Queue::fake();

        $this->importacionConCatalogoDe(25);

        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());

        Livewire::test('admin.catalogos-importaciones')->assertForbidden();

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------
    // El identificador del archivo subido (TG-277)
    // ------------------------------------------------------------------

    public function test_guarda_en_la_tabla_el_archivo_que_subio_a_la_ia(): void
    {
        $importacion = $this->importacionConCatalogoDe(15);

        // A mitad del proceso tiene que estar anotado, para poder borrarlo
        // si algo se cae.
        $this->lector->fallaEnElBloqueQueEmpiezaEn = 11;

        $this->accion()->ejecutar($importacion);

        // Al terminar se limpia, porque el archivo ya se borro alla.
        $this->assertNull($importacion->fresh()->archivo_ia_id);
        $this->assertTrue($this->lector->borrado);
    }

    public function test_al_reintentar_no_vuelve_a_subir_los_29_mb(): void
    {
        // Era el motivo de pedir la columna: antes esto vivia en el cache y
        // se perdia al reiniciar el worker o al desplegar.
        $importacion = $this->importacionConCatalogoDe(15);
        $importacion->update(['archivo_ia_id' => 'file_ya_subido_antes']);

        $this->accion()->ejecutar($importacion);

        $this->assertSame(0, $this->lector->vecesQueSubio, 'Volvió a subir el catálogo teniéndolo ya arriba.');
    }

    public function test_si_el_borrado_falla_igual_se_limpia_el_identificador(): void
    {
        // Dejarlo puesto haria creer que el archivo sigue alla, cuando en
        // realidad vence solo.
        $importacion = $this->importacionConCatalogoDe(15);
        $this->lector->borrarTruena = true;

        $this->accion()->ejecutar($importacion);

        $this->assertNull($importacion->fresh()->archivo_ia_id);
    }

    public function test_si_la_respuesta_no_cabe_parte_el_bloque_y_no_pierde_productos(): void
    {
        // Con max_tokens el JSON llega cortado. Antes eso tiraba toda la
        // importación; ahora el bloque se parte en dos y se vuelve a pedir.
        $importacion = $this->importacionConCatalogoDe(25);
        $this->lector->cortaSiAbarcaMasDe = 5;

        $this->accion()->ejecutar($importacion);

        $importacion->refresh();
        $this->assertSame('requiere_revision', $importacion->estado);
        $this->assertSame(4, $importacion->productosStaging()->count());

        // Lo de las vueltas cortadas también se cobra y queda anotado.
        $cortadas = collect($this->lector->bloquesPedidos)->filter(fn ($b) => $b['hasta'] - $b['desde'] + 1 > 5)->count();
        $this->assertGreaterThan(0, $cortadas);
        $this->assertGreaterThanOrEqual($cortadas * 16000, (int) $importacion->tokens_salida);
    }

    public function test_una_sola_pagina_que_no_cabe_avisa_con_un_mensaje_claro(): void
    {
        $importacion = $this->importacionConCatalogoDe(3);
        $this->lector->cortaSiAbarcaMasDe = 0;

        $this->accion()->ejecutar($importacion);

        $importacion->refresh();
        $this->assertSame('error', $importacion->estado);
        $this->assertStringContainsString('IA_MAX_TOKENS_BLOQUE', (string) $importacion->mensaje_error);
    }

    public function test_un_reintento_suma_al_gasto_anterior_y_no_lo_borra(): void
    {
        // El tope mensual suma costo_usd: si el reintento lo pisara, lo que
        // ya se pagó en el intento fallido desaparecería de la cuenta.
        $importacion = $this->importacionConCatalogoDe(15);
        $importacion->update(['costo_usd' => 1.5, 'tokens_entrada' => 1000, 'tokens_salida' => 500]);

        $this->accion()->ejecutar($importacion);

        $importacion->refresh();
        $this->assertGreaterThan(1.5, (float) $importacion->costo_usd);
        $this->assertSame(500 + 8000, (int) $importacion->tokens_salida);
    }

    public function test_procesar_solo_encola_si_logra_tomar_la_importacion(): void
    {
        // Doble clic / dos pestañas: la segunda petición ya no la encuentra
        // en "cargado" o "error" y no encola otra lectura pagada.
        Queue::fake();

        $importacion = $this->importacionConCatalogoDe(15);
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.catalogos-importaciones')->call('procesar', $importacion->id)->assertSet('esError', false);
        Livewire::test('admin.catalogos-importaciones')->call('procesar', $importacion->id)->assertSet('esError', true);

        Queue::assertPushed(ProcesarCatalogoConIa::class, 1);
    }
}
