<?php

namespace Tests\Feature\Catalogo;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\Color;
use App\Models\ImportacionCatalogo;
use App\Models\Marca;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Services\Ia\AprobarProductosImportadosAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-282 — Que el resumen de la aprobación diga la verdad.
 *
 * Reportado al aprobar el Cklass: "Productos aprobados: 138 · Nuevos: 148".
 * Imposible, y en otra corrida fue peor: "Aprobados: 0 · Nuevos: 15".
 *
 * La causa: los contadores suben dentro de aprobarUno, antes de que la fila
 * pueda fallar. Cuando falla, la transacción revierte la base pero el
 * contador en memoria se queda con el incremento, porque el resumen viaja
 * por referencia. El resumen contaba los intentos, no lo que entró.
 */
class ResumenDeAprobacionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function importacion(): ImportacionCatalogo
    {
        return ImportacionCatalogo::create([
            'linea_id'                => 1,
            'archivo_url'             => 'catalogos-ia/resumen.pdf',
            'tipo_archivo'            => 'pdf',
            'estado'                  => 'requiere_revision',
            'paginas'                 => 2,
            'iniciada_por_usuario_id' => Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail()->id,
        ]);
    }

    private function fila(ImportacionCatalogo $importacion, string $codigo, mixed $hasta, ?int $colorId = null): void
    {
        ProductoImportadoStaging::create([
            'importacion_id'  => $importacion->id,
            'datos_extraidos' => [
                'pagina'          => 1,
                'marca'           => 'Cklass',
                'modelo'          => $codigo,
                'nombre'          => 'Zapato',
                'color'           => $colorId === null ? 'NEGRO' : 'BEIGE',
                'color_base_id'   => $colorId ?? Color::query()->orderBy('id')->firstOrFail()->id,
                'codigo_catalogo' => $codigo,
                'precio_publico'  => 599.0,
                'talla_desde'     => '2',
                'talla_hasta'     => $hasta,
                'medios_numeros'  => true,
                'disponibilidad'  => null,
                'dudosos'         => [],
            ],
            'campos_dudosos' => [],
            'estado'         => 'corregido',
        ]);
    }

    private function aprobar(ImportacionCatalogo $importacion): array
    {
        return app(AprobarProductosImportadosAction::class)->ejecutar(
            $importacion,
            campanaId: Campana::query()->orderBy('id')->firstOrFail()->id,
            categoriaPorOmision: CategoriaProducto::query()->orderBy('id')->firstOrFail()->id,
            marcaPorOmision: Marca::query()->orderBy('id')->firstOrFail()->id,
        );
    }

    public function test_lo_que_se_salta_no_se_cuenta_como_producto_nuevo(): void
    {
        $importacion = $this->importacion();

        $this->fila($importacion, 'OK-001', '6 1/2');   // entra
        $this->fila($importacion, 'MAL-002', 'UNITALLA'); // se salta: sin tallas

        $resumen = $this->aprobar($importacion);

        $this->assertSame(1, $resumen['aprobados']);
        $this->assertCount(1, $resumen['saltados']);

        // Lo que fallaba: aquí salía 2, contando el que no entró.
        $this->assertSame(1, $resumen['productos_nuevos']);

        // Y las cuentas cuadran con lo que hay en la base.
        $this->assertSame(
            $resumen['aprobados'] + count($resumen['saltados']),
            $resumen['productos_nuevos'] + $resumen['productos_reusados'] + count($resumen['saltados']),
        );
    }

    /** Las medias tallas en fracción ya no tiran el producto. */
    public function test_una_talla_escrita_como_fraccion_si_entra(): void
    {
        $importacion = $this->importacion();
        $this->fila($importacion, 'FRAC-001', '6 1/2');

        $resumen = $this->aprobar($importacion);

        $this->assertSame(1, $resumen['aprobados'], json_encode($resumen['saltados'], JSON_UNESCAPED_UNICODE));

        // De 2 a 6.5 con medias son diez tallas.
        $this->assertSame(10, $resumen['variantes']);
    }

    /**
     * El caso de los "DUO" del Cklass: el catalogo repite el codigo por
     * color, dos filas de la misma pagina con el mismo codigo. Once
     * productos se reportaban como saltados y su color se perdia.
     */
    public function test_el_mismo_modelo_en_otro_color_se_agrega_en_vez_de_saltarse(): void
    {
        $importacion = $this->importacion();

        $otroColor = Color::query()->orderBy('id')->skip(1)->first();

        $this->fila($importacion, 'DUO-613', '6');
        $this->fila($importacion, 'DUO-613', '6', $otroColor->id);

        $resumen = $this->aprobar($importacion);

        // Las dos entran: antes la segunda salia en "saltados".
        $this->assertSame(2, $resumen['aprobados']);
        $this->assertSame([], $resumen['saltados']);
        $this->assertCount(1, $resumen['colores_agregados']);

        // Una sola publicacion, con los dos colores disponibles.
        $publicaciones = \App\Models\ProductoCampana::withoutGlobalScopes()
            ->where('codigo_catalogo', 'DUO-613')->get();

        $this->assertCount(1, $publicaciones, 'No debe crear dos publicaciones con el mismo codigo.');

        $colores = \App\Models\DisponibilidadVarianteCampana::withoutGlobalScopes()
            ->where('producto_campana_id', $publicaciones->first()->id)
            ->join('variantes', 'variantes.id', '=', 'disponibilidad_variante_campana.variante_id')
            ->distinct()->pluck('variantes.color_id');

        $this->assertCount(2, $colores, 'Los dos colores deben quedar disponibles en la publicacion.');
    }

    /** La fila queda ligada, si no publicar-fotos-ia no la encuentra. */
    public function test_la_fila_del_color_agregado_queda_ligada_a_la_publicacion(): void
    {
        $importacion = $this->importacion();
        $otroColor = Color::query()->orderBy('id')->skip(1)->first();

        $this->fila($importacion, 'DUO-164', '6');
        $this->fila($importacion, 'DUO-164', '6', $otroColor->id);

        $this->aprobar($importacion);

        foreach ($importacion->productosStaging as $fila) {
            $this->assertNotNull($fila->producto_campana_creado_id, 'La fila quedo huerfana.');
        }
    }
}
