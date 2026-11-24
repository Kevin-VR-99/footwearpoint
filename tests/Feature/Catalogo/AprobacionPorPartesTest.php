<?php

namespace Tests\Feature\Catalogo;

use App\Models\Auditoria;
use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Color;
use App\Models\ImportacionCatalogo;
use App\Models\Marca;
use App\Models\ProductoCampana;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Services\Ia\AprobarProductosImportadosAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-377 — Aprobar un catálogo grande sin que el servidor mate la petición.
 *
 * LO QUE PASÓ DE VERDAD. El 24-nov-2026, en producción, se aprobaron 30
 * productos de un catálogo Dankriz de 9 páginas. La pantalla enseñó
 * "Error 500 · Algo salió mal", pero en el catálogo maestro habían entrado
 * 13. Al volver a presionar, entraron otros 13 y salió el mismo error. A la
 * tercera, los 6 que quedaban entraron sin problema.
 *
 * El log de producción lo dice sin rodeos:
 *
 *     PHP Fatal error: Maximum execution time of 30 seconds exceeded
 *     in vendor/guzzlehttp/guzzle/src/Handler/CurlMultiHandler.php:986
 *
 * Murió esperando al bucket. Cada producto hace cuatro viajes (baja el
 * recorte, sube el original, sube la miniatura, sube la mediana), unos
 * 2.3 s; a los 30 s PHP corta, y 13 × 2.3 ≈ 30.
 *
 * LOS DATOS NUNCA ESTUVIERON MAL: cada producto va en su propia transacción
 * y aprobarUno() se salta lo que ya tiene producto_campana_creado_id, así que
 * nada se duplicó ni quedó a medias. Lo que estaba roto era la PANTALLA, que
 * enseñaba un error donde debía decir "entraron 13, faltan 17".
 *
 * Estas pruebas fijan las dos mitades: que el bucle se detenga solo antes del
 * límite, y que lo diga en vez de morirse.
 */
class AprobacionPorPartesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private ImportacionCatalogo $importacion;

    private int $campanaId;

    private int $categoriaId;

    private int $marcaId;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $campana = Campana::firstOrFail();
        $this->campanaId = $campana->id;
        $this->categoriaId = CategoriaProducto::firstOrFail()->id;
        $this->marcaId = Marca::firstOrFail()->id;

        $this->importacion = ImportacionCatalogo::create([
            'linea_id'                => $campana->linea_id,
            'campana_id'              => $campana->id,
            'archivo_url'             => 'catalogos-ia/2026/11/dankriz.pdf',
            'tipo_archivo'            => 'pdf',
            'estado'                  => 'requiere_revision',
            'paginas'                 => 9,
            'iniciada_por_usuario_id' => $this->adminGeneral()->id,
        ]);
    }

    private function adminGeneral(): Usuario
    {
        return Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail();
    }

    /**
     * Cinco productos revisados y DISTINTOS entre sí: con el mismo código se
     * reutilizarían unos a otros y las cuentas de esta prueba no dirían nada.
     *
     * @return array<int, ProductoImportadoStaging>
     */
    private function cincoRevisados(): array
    {
        $negro = Color::whereRaw('LOWER(nombre) = ?', ['negro'])->firstOrFail()->id;
        $filas = [];

        foreach (range(1, 5) as $i) {
            $filas[] = ProductoImportadoStaging::create([
                'importacion_id'  => $this->importacion->id,
                'datos_extraidos' => [
                    'pagina'          => $i,
                    'marca'           => null,
                    'modelo'          => 'DANKRIZ-'.$i,
                    'nombre'          => null,
                    'color'           => 'MIEL',
                    'color_base_id'   => $negro,
                    'codigo_catalogo' => '033-'.$i,
                    'precio_publico'  => 699.0,
                    'talla_desde'     => '22',
                    'talla_hasta'     => '24',
                    'medios_numeros'  => false,
                    'disponibilidad'  => null,
                ],
                'campos_dudosos' => [],
                'estado'         => 'corregido',
            ]);
        }

        return $filas;
    }

    /**
     * @param  float|null  $presupuesto  0.0 = "ya no queda tiempo", que es la
     *                                   forma determinista de provocar el
     *                                   corte sin dormir el reloj.
     * @return array<string, mixed>
     */
    private function aprobar(?float $presupuesto): array
    {
        $this->actingAs($this->adminGeneral());

        return app(AprobarProductosImportadosAction::class)->ejecutar(
            $this->importacion,
            $this->campanaId,
            $this->categoriaId,
            $this->marcaId,
            $presupuesto,
        );
    }

    // ------------------------------------------------------------------
    // El corte
    // ------------------------------------------------------------------

    public function test_cuando_se_acaba_el_tiempo_se_detiene_y_lo_dice(): void
    {
        $this->cincoRevisados();

        $resumen = $this->aprobar(presupuesto: 0.0);

        $this->assertTrue($resumen['se_corto'], 'La tanda se cortó y el resumen tiene que decirlo.');
        $this->assertSame(1, $resumen['aprobados']);
        $this->assertSame(4, $resumen['faltan']);
    }

    /**
     * Esta es la razón por la que el corte es seguro: parar a la mitad no deja
     * nada colgando, porque cada producto va en su propia transacción.
     */
    public function test_lo_que_entro_antes_del_corte_queda_guardado(): void
    {
        $filas = $this->cincoRevisados();

        $this->aprobar(presupuesto: 0.0);

        $primera = $filas[0]->fresh();
        $this->assertSame('aprobado', $primera->estado);
        $this->assertNotNull($primera->producto_campana_creado_id);
        $this->assertTrue(
            ProductoCampana::whereKey($primera->producto_campana_creado_id)->exists(),
            'El producto que alcanzó a entrar tiene que estar en el catálogo maestro.'
        );

        // Y los otros cuatro NO entraron. Sin esta mitad la prueba pasaría
        // igual aunque el corte no existiera, que es justo lo que no sirve.
        foreach (array_slice($filas, 1) as $fila) {
            $this->assertNull($fila->fresh()->producto_campana_creado_id);
        }
    }

    public function test_los_que_no_se_alcanzaron_siguen_listos_para_aprobar(): void
    {
        $this->cincoRevisados();

        $this->aprobar(presupuesto: 0.0);

        $this->assertSame(
            4,
            $this->importacion->productosStaging()->where('estado', 'corregido')->count(),
            'Los que no se intentaron no se tocan: siguen esperando su turno.'
        );
    }

    /**
     * Lo que hizo el usuario tres veces seguidas en producción, y por qué no
     * se le duplicó nada.
     */
    public function test_volver_a_aprobar_termina_el_trabajo_sin_duplicar(): void
    {
        $this->cincoRevisados();

        $primera = $this->aprobar(presupuesto: 0.0);
        $segunda = $this->aprobar(presupuesto: null);

        $this->assertSame(1, $primera['aprobados']);
        $this->assertSame(4, $segunda['aprobados']);
        $this->assertFalse($segunda['se_corto']);

        $this->assertSame(
            5,
            ProductoCampana::where('campana_id', $this->campanaId)
                ->whereIn('codigo_catalogo', ['033-1', '033-2', '033-3', '033-4', '033-5'])
                ->count(),
            'Cinco productos, cinco publicaciones: ni una de más.'
        );
    }

    /**
     * Antes bastaba con que entrara uno para marcar la importación como
     * aprobada. Con el corte eso sería mentir: la lista de catálogos diría
     * "aprobado" con 4 productos todavía afuera.
     */
    public function test_una_tanda_cortada_no_marca_la_importacion_como_aprobada(): void
    {
        $this->cincoRevisados();

        $this->aprobar(presupuesto: 0.0);
        $this->assertSame('requiere_revision', $this->importacion->fresh()->estado);

        $this->aprobar(presupuesto: null);
        $this->assertSame('aprobado', $this->importacion->fresh()->estado);
    }

    /**
     * Cuando PHP mataba la petición, la auditoría del lote NUNCA se escribía:
     * 26 productos entraron al catálogo y el único registro decía 6.
     */
    public function test_la_auditoria_deja_rastro_de_la_tanda_cortada(): void
    {
        $this->cincoRevisados();

        $this->aprobar(presupuesto: 0.0);

        $registro = Auditoria::where('accion', 'importacion_catalogo.aprobada')
            ->where('entidad_id', $this->importacion->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue($registro->datos_nuevos['se_corto']);
        $this->assertSame(4, $registro->datos_nuevos['faltan']);
        $this->assertSame(1, $registro->datos_nuevos['aprobados']);
    }

    // ------------------------------------------------------------------
    // Que el reloj no estorbe donde no hay reloj
    // ------------------------------------------------------------------

    /**
     * En la consola y en la cola (`queue:work --timeout=1800`)
     * max_execution_time es 0: ahí no hay nada de qué cuidarse y cortar sería
     * un invento. Si esta prueba falla, el corte se está metiendo donde no lo
     * llamaron.
     */
    public function test_sin_limite_de_php_no_se_corta_nada(): void
    {
        $this->cincoRevisados();

        $resumen = $this->aprobar(presupuesto: null);

        $this->assertFalse($resumen['se_corto']);
        $this->assertSame(5, $resumen['aprobados']);
        $this->assertSame(0, $resumen['faltan']);
    }

    /**
     * El seguro contra el peor final posible: que presionar "Aprobar" no haga
     * NADA y el catálogo nunca termine de entrar. Aunque el presupuesto ya
     * esté en cero, siempre pasa uno.
     */
    public function test_siempre_entra_al_menos_uno_aunque_no_quede_tiempo(): void
    {
        $this->cincoRevisados();

        $this->assertGreaterThanOrEqual(1, $this->aprobar(presupuesto: 0.0)['aprobados']);
    }

    // ------------------------------------------------------------------
    // La pantalla
    // ------------------------------------------------------------------

    /**
     * Lo que el usuario tiene que leer. Se finge el resumen cortado porque en
     * consola no hay límite de PHP que disparar: lo que se prueba aquí es la
     * pantalla, no el reloj (ese ya está arriba).
     */
    public function test_la_pantalla_dice_cuantos_faltan_en_vez_de_decir_listo(): void
    {
        $this->cincoRevisados();
        $this->actingAs($this->adminGeneral());

        $this->mock(AprobarProductosImportadosAction::class)
            ->shouldReceive('ejecutar')
            ->once()
            ->andReturn([
                'aprobados'          => 13,
                'productos_nuevos'   => 13,
                'productos_reusados' => 0,
                'variantes'          => 39,
                'saltados'           => [],
                'colores_agregados'  => [],
                'tallas_creadas'     => [],
                'pendientes'         => 0,
                'faltan'             => 17,
                'se_corto'           => true,
            ]);

        Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id])
            ->set('campanaId', $this->campanaId)
            ->set('categoriaPorOmision', $this->categoriaId)
            ->set('marcaPorOmision', $this->marcaId)
            ->call('aprobar')
            ->assertHasNoErrors()
            // Ni verde ni rojo: faltan 17 y eso no es un error.
            ->assertSet('esError', false)
            ->assertSet('mensaje', '')
            ->assertSee('faltan')
            ->assertSee('17')
            ->assertSee('no se repiten')
            ->assertDontSee('Listo. Abajo está el detalle');
    }

    /**
     * Livewire guarda los computados durante toda la petición, y aprobar() ya
     * había leído listosParaAprobar para validar. Sin tirar ese valor, el
     * botón seguía ofreciendo aprobar los que acababan de entrar.
     */
    public function test_el_boton_vuelve_a_contar_los_que_quedan(): void
    {
        $this->cincoRevisados();
        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id])
            ->set('campanaId', $this->campanaId)
            ->set('categoriaPorOmision', $this->categoriaId)
            ->set('marcaPorOmision', $this->marcaId)
            ->call('aprobar')
            ->assertHasNoErrors()
            ->assertSee('Aprobar los revisados (0)')
            ->assertDontSee('Aprobar los revisados (5)');
    }
}
