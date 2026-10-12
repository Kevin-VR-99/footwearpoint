<?php

namespace Tests\Feature\Catalogo;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Color;
use App\Models\ImportacionCatalogo;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\ProductoImportadoStaging;
use App\Models\Talla;
use App\Models\Usuario;
use App\Models\Variante;
use App\Services\Ia\AprobarProductosImportadosAction;
use App\Services\Ia\ColoresDelCatalogo;
use App\Services\Ia\RangoDeTallas;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-240 (A10 / E5-04) — Los productos revisados pasan al catálogo maestro.
 *
 * El criterio pide que "el producto siga el mismo flujo que uno creado
 * manualmente", así que aprobar tiene que dejar la cadena completa:
 * producto, variantes por talla, producto en la temporada y disponibilidad.
 * Sin eso, el producto quedaría sin tallas y nadie podría pedirlo.
 */
class AprobarProductosImportadosTest extends TestCase
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
            'archivo_url'             => 'catalogos-ia/2026/10/confort.pdf',
            'tipo_archivo'            => 'pdf',
            'estado'                  => 'requiere_revision',
            'paginas'                 => 76,
            'iniciada_por_usuario_id' => $this->adminGeneral()->id,
        ]);
    }

    private function adminGeneral(): Usuario
    {
        return Usuario::where('email', \Database\Seeders\AdminGeneralSeeder::EMAIL)->firstOrFail();
    }

    private function colorNegro(): Color
    {
        return Color::whereRaw('LOWER(nombre) = ?', ['negro'])->firstOrFail();
    }

    /** Un producto revisado, listo para aprobar. */
    private function revisado(array $datos = []): ProductoImportadoStaging
    {
        return ProductoImportadoStaging::create([
            'importacion_id'  => $this->importacion->id,
            'datos_extraidos' => $datos + [
                'pagina'          => 3,
                'marca'           => null,
                'modelo'          => null,
                'nombre'          => null,
                'color'           => 'MIEL',
                'color_base_id'   => $this->colorNegro()->id,
                'codigo_catalogo' => '033-39',
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

    private function aprobar(): array
    {
        $this->actingAs($this->adminGeneral());

        return app(AprobarProductosImportadosAction::class)->ejecutar(
            $this->importacion,
            $this->campanaId,
            $this->categoriaId,
            $this->marcaId,
        );
    }

    // ------------------------------------------------------------------
    // La cadena completa
    // ------------------------------------------------------------------

    public function test_aprobar_deja_el_producto_listo_para_pedirse(): void
    {
        $fila = $this->revisado();

        $resumen = $this->aprobar();

        $this->assertSame(1, $resumen['aprobados']);

        $fila->refresh();
        $this->assertNotNull($fila->producto_creado_id);
        $this->assertNotNull($fila->producto_campana_creado_id);
        $this->assertSame('aprobado', $fila->estado);

        $producto = Producto::findOrFail($fila->producto_creado_id);

        // Tallas 22, 23 y 24: una variante por cada una.
        $this->assertSame(3, $producto->variantes()->count());

        // Y cada variante con su disponibilidad en la temporada: sin eso no
        // se puede pedir.
        $this->assertSame(
            3,
            \App\Models\DisponibilidadVarianteCampana::where('producto_campana_id', $fila->producto_campana_creado_id)->count()
        );
    }

    public function test_el_nombre_del_catalogo_se_guarda_y_el_color_base_agrupa(): void
    {
        $fila = $this->revisado();

        $this->aprobar();

        $variante = Variante::where('producto_id', $fila->fresh()->producto_creado_id)->firstOrFail();

        $this->assertSame('MIEL', $variante->nombre_color_comercial);
        $this->assertSame($this->colorNegro()->id, (int) $variante->color_id);
    }

    public function test_sin_modelo_usa_el_codigo_y_el_nombre_lleva_la_categoria(): void
    {
        // El cliente ve el nombre en la app: el código solo no se entiende.
        $fila = $this->revisado();

        $this->aprobar();

        $producto = Producto::findOrFail($fila->fresh()->producto_creado_id);
        $categoria = CategoriaProducto::findOrFail($this->categoriaId);

        $this->assertSame('033-39', $producto->modelo);
        $this->assertSame($categoria->nombre.' 033-39', $producto->nombre);
    }

    public function test_la_marca_del_catalogo_gana_sobre_la_del_lote(): void
    {
        $otra = Marca::where('id', '!=', $this->marcaId)->firstOrFail();

        $this->revisado(['marca' => $otra->nombre]);

        $this->aprobar();

        $this->assertSame($otra->id, (int) Producto::latest('id')->value('marca_id'));
    }

    public function test_si_la_marca_no_existe_usa_la_del_lote_y_no_inventa_marcas(): void
    {
        $antes = Marca::count();

        $this->revisado(['marca' => 'Marca Que No Existe']);

        $this->aprobar();

        $this->assertSame($antes, Marca::count(), 'Se inventó una marca.');
        $this->assertSame($this->marcaId, (int) Producto::latest('id')->value('marca_id'));
    }

    public function test_la_categoria_del_producto_gana_sobre_la_del_lote(): void
    {
        $otra = CategoriaProducto::where('id', '!=', $this->categoriaId)->firstOrFail();

        $fila = $this->revisado(['categoria_id' => $otra->id]);

        $this->aprobar();

        $this->assertSame($otra->id, (int) Producto::findOrFail($fila->fresh()->producto_creado_id)->categoria_id);
    }

    // ------------------------------------------------------------------
    // El producto que ya existe (lo señaló el líder)
    // ------------------------------------------------------------------

    public function test_si_el_producto_ya_existe_se_reutiliza_y_no_se_duplica(): void
    {
        $existente = Producto::create([
            'marca_id'     => $this->marcaId,
            'categoria_id' => $this->categoriaId,
            'modelo'       => '033-39',
            'nombre'       => 'Nombre que ya tenia',
            'activo'       => true,
        ]);

        $antes = Producto::count();

        $this->revisado();

        $resumen = $this->aprobar();

        $this->assertSame($antes, Producto::count(), 'Duplicó el producto.');
        $this->assertSame(1, $resumen['productos_reusados']);
        $this->assertSame(0, $resumen['productos_nuevos']);
        // No se le pisan sus datos.
        $this->assertSame('Nombre que ya tenia', $existente->fresh()->nombre);
    }

    public function test_a_un_producto_que_ya_existe_se_le_agregan_las_tallas_que_le_faltan(): void
    {
        $existente = Producto::create([
            'marca_id' => $this->marcaId, 'categoria_id' => $this->categoriaId,
            'modelo' => '033-39', 'nombre' => 'Ya existia', 'activo' => true,
        ]);

        // Ya tiene la 22; el catálogo trae de la 22 a la 24.
        app(\App\Services\Catalogo\GestionarVarianteAction::class)->crear([
            'producto_id' => $existente->id,
            'talla_id'    => Talla::where('valor', '22')->firstOrFail()->id,
            'color_id'    => $this->colorNegro()->id,
        ]);

        $this->revisado();
        $this->aprobar();

        $this->assertSame(3, $existente->fresh()->variantes()->count());
    }

    public function test_si_ya_estaba_en_esa_temporada_se_salta_y_no_se_cambia_el_precio(): void
    {
        // Ese precio lo puso alguien: cambiarlo en silencio seria peor que
        // no hacer nada.
        $existente = Producto::create([
            'marca_id' => $this->marcaId, 'categoria_id' => $this->categoriaId,
            'modelo' => '033-39', 'nombre' => 'Ya existia', 'activo' => true,
        ]);

        $yaEnTemporada = ProductoCampana::create([
            'producto_id'     => $existente->id,
            'campana_id'      => $this->campanaId,
            'codigo_catalogo' => 'VIEJO-033-39',
            'precio_publico'  => 499.0,
            'activo'          => true,
        ]);

        $this->revisado();

        $resumen = $this->aprobar();

        $this->assertSame(0, $resumen['aprobados']);
        $this->assertNotEmpty($resumen['saltados']);
        $this->assertStringContainsString('ya estaba en esta temporada', $resumen['saltados'][0]);
        $this->assertSame('499.00', (string) $yaEnTemporada->fresh()->precio_publico);
    }

    public function test_si_otro_producto_ya_usa_ese_codigo_en_la_temporada_se_salta(): void
    {
        $otro = Producto::create([
            'marca_id' => $this->marcaId, 'categoria_id' => $this->categoriaId,
            'modelo' => 'OTRO-MODELO', 'nombre' => 'Otro', 'activo' => true,
        ]);

        ProductoCampana::create([
            'producto_id' => $otro->id, 'campana_id' => $this->campanaId,
            'codigo_catalogo' => '033-39', 'precio_publico' => 599.0, 'activo' => true,
        ]);

        $this->revisado();

        $resumen = $this->aprobar();

        $this->assertSame(0, $resumen['aprobados']);
        $this->assertStringContainsString('ya lo usa otro producto', $resumen['saltados'][0]);
    }

    public function test_aprobar_dos_veces_no_duplica_nada(): void
    {
        $this->revisado();

        $this->aprobar();
        $productos = Producto::count();
        $variantes = Variante::count();

        $this->aprobar();

        $this->assertSame($productos, Producto::count());
        $this->assertSame($variantes, Variante::count());
    }

    // ------------------------------------------------------------------
    // Tallas
    // ------------------------------------------------------------------

    public function test_las_tallas_que_no_existen_se_crean_y_se_reportan(): void
    {
        // Un catálogo de niño trae menores a 22, y la tabla empieza ahí:
        // saltarlas dejaría productos aprobados sin tallas.
        $this->assertNull(Talla::where('valor', '19')->first());

        $this->revisado(['talla_desde' => '19', 'talla_hasta' => '20']);

        $resumen = $this->aprobar();

        $this->assertNotNull(Talla::where('valor', '19')->first());
        $this->assertArrayHasKey('19', $resumen['tallas_creadas']);
        $this->assertArrayHasKey('20', $resumen['tallas_creadas']);
    }

    public function test_un_rango_que_no_se_entiende_no_inventa_tallas(): void
    {
        $antes = Talla::count();

        $this->revisado(['talla_desde' => 'veintidos', 'talla_hasta' => 'veinticuatro']);

        $resumen = $this->aprobar();

        $this->assertSame($antes, Talla::count());
        $this->assertSame(0, $resumen['aprobados']);
        $this->assertStringContainsString('rango de tallas', $resumen['saltados'][0]);
    }

    public function test_el_rango_de_tallas_entiende_los_casos_reales(): void
    {
        $rango = new RangoDeTallas;

        // Impuls: "22 AL 27" con medias.
        $this->assertSame(['22', '22.5', '23', '23.5', '24'], $rango->valores('22', '24', true));
        // Confort: "2 - 6 enteros".
        $this->assertSame(['2', '3', '4', '5', '6'], $rango->valores('2', '6', false));
        // Una sola talla es válido.
        $this->assertSame(['25'], $rango->valores('25', '25'));
        // Al revés, con letras o absurdo: no se inventa nada.
        $this->assertSame([], $rango->valores('27', '22'));
        $this->assertSame([], $rango->valores('veintidos', '27'));
        $this->assertSame([], $rango->valores('1', '500'));
    }

    // ------------------------------------------------------------------
    // Lo que no se aprueba
    // ------------------------------------------------------------------

    public function test_lo_que_nadie_reviso_no_se_aprueba(): void
    {
        // Si no, la revisión de A9 no serviría de nada.
        $pendiente = $this->revisado();
        $pendiente->update(['estado' => 'pendiente']);

        $resumen = $this->aprobar();

        $this->assertSame(0, $resumen['aprobados']);
        $this->assertSame(1, $resumen['pendientes']);
        $this->assertSame('pendiente', $pendiente->fresh()->estado);
    }

    public function test_lo_descartado_no_entra_al_catalogo(): void
    {
        $descartado = $this->revisado();
        $descartado->update(['estado' => 'descartado']);

        $this->assertSame(0, $this->aprobar()['aprobados']);
    }

    public function test_sin_color_resuelto_no_se_aprueba(): void
    {
        $fila = $this->revisado();
        $datos = $fila->datos_extraidos;
        unset($datos['color_base_id']);
        $fila->update(['datos_extraidos' => $datos]);

        $resumen = $this->aprobar();

        $this->assertSame(0, $resumen['aprobados']);
        $this->assertStringContainsString('color de la paleta', $resumen['saltados'][0]);
    }

    // ------------------------------------------------------------------
    // Los colores, una vez por color distinto
    // ------------------------------------------------------------------

    public function test_agrupa_los_colores_distintos_y_no_pregunta_por_producto(): void
    {
        $this->revisado(['color' => 'MIEL', 'codigo_catalogo' => 'A-1']);
        $this->revisado(['color' => 'MIEL', 'codigo_catalogo' => 'A-2']);
        $this->revisado(['color' => 'HUESO', 'codigo_catalogo' => 'A-3']);

        $colores = app(ColoresDelCatalogo::class)->distintos($this->importacion);

        $this->assertCount(2, $colores);
        $this->assertSame(2, $colores['MIEL']['productos']);
        $this->assertSame(1, $colores['HUESO']['productos']);
    }

    public function test_reutiliza_el_color_base_que_ya_se_decidio_antes(): void
    {
        // Asi la segunda importacion casi no pregunta nada. "Miel" no sirve
        // para esta prueba: el catalogo demo ya lo trae, que es justamente la
        // regla funcionando.
        $producto = Producto::create([
            'marca_id' => $this->marcaId, 'categoria_id' => $this->categoriaId,
            'modelo' => 'PREVIO', 'nombre' => 'Previo', 'activo' => true,
        ]);

        app(\App\Services\Catalogo\GestionarVarianteAction::class)->crear([
            'producto_id'            => $producto->id,
            'talla_id'               => Talla::where('valor', '23')->firstOrFail()->id,
            'color_id'               => $this->colorNegro()->id,
            'nombre_color_comercial' => 'Arena quemada',
        ]);

        [$sugerido, $origen] = app(ColoresDelCatalogo::class)->sugerir('ARENA QUEMADA');

        $this->assertSame($this->colorNegro()->id, $sugerido);
        $this->assertSame('ya se usó antes', $origen);
    }

    public function test_el_catalogo_demo_ya_le_enseno_a_que_color_base_va_miel(): void
    {
        // No es casualidad: el catalogo sembrado ya usa "Miel", asi que la
        // primera regla lo resuelve sin preguntarle a nadie.
        [$sugerido, $origen] = app(ColoresDelCatalogo::class)->sugerir('MIEL');

        $this->assertNotNull($sugerido);
        $this->assertSame('ya se usó antes', $origen);
    }

    public function test_toma_la_propuesta_de_la_ia_si_es_un_color_de_la_paleta(): void
    {
        // Un color que nadie ha usado todavia: ahi si manda la IA.
        [$sugerido, $origen] = app(ColoresDelCatalogo::class)->sugerir('TIERRA SIENA', 'Café');

        $this->assertSame(Color::whereRaw('LOWER(nombre) = ?', ['café'])->value('id'), $sugerido);
        $this->assertSame('lo propuso la IA', $origen);
    }

    public function test_guardar_los_colores_los_deja_en_todos_los_productos_de_ese_color(): void
    {
        $a = $this->revisado(['color' => 'HUESO', 'codigo_catalogo' => 'A-1']);
        $b = $this->revisado(['color' => 'HUESO', 'codigo_catalogo' => 'A-2']);

        $beige = Color::whereRaw('LOWER(nombre) = ?', ['beige'])->firstOrFail();

        $resueltos = app(ColoresDelCatalogo::class)
            ->asignar($this->importacion, ['HUESO' => $beige->id]);

        $this->assertSame(2, $resueltos);
        $this->assertSame($beige->id, $a->fresh()->datos_extraidos['color_base_id']);
        $this->assertSame($beige->id, $b->fresh()->datos_extraidos['color_base_id']);
    }

    // ------------------------------------------------------------------
    // La pantalla
    // ------------------------------------------------------------------

    public function test_la_pantalla_aprueba_y_enseña_lo_que_paso(): void
    {
        $this->revisado();

        $this->actingAs($this->adminGeneral());

        $componente = Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id])
            ->set('campanaId', $this->campanaId)
            ->set('categoriaPorOmision', $this->categoriaId)
            ->set('marcaPorOmision', $this->marcaId)
            ->call('aprobar')
            ->assertHasNoErrors()
            ->assertSet('esError', false);

        $this->assertSame(1, $componente->get('resumenAprobacion')['aprobados']);
    }

    public function test_la_pantalla_exige_temporada_categoria_y_marca(): void
    {
        $this->revisado();

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id])
            ->set('campanaId', null)
            ->set('categoriaPorOmision', null)
            ->set('marcaPorOmision', null)
            ->call('aprobar')
            ->assertHasErrors(['campanaId', 'categoriaPorOmision', 'marcaPorOmision']);
    }

    public function test_la_pantalla_no_deja_aprobar_con_colores_sin_resolver(): void
    {
        $fila = $this->revisado();
        $datos = $fila->datos_extraidos;
        unset($datos['color_base_id']);
        $fila->update(['datos_extraidos' => $datos]);

        $this->actingAs($this->adminGeneral());

        Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id])
            ->set('campanaId', $this->campanaId)
            ->set('categoriaPorOmision', $this->categoriaId)
            ->set('marcaPorOmision', $this->marcaId)
            ->call('aprobar')
            ->assertSet('esError', true);

        $this->assertSame(0, ProductoCampana::where('codigo_catalogo', '033-39')->count());
    }

    public function test_un_admin_de_distribuidora_no_puede_aprobar(): void
    {
        $this->revisado();

        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());

        Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id])
            ->assertForbidden();
    }
}
