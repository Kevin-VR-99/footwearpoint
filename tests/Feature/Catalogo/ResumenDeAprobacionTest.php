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

    private function fila(ImportacionCatalogo $importacion, string $codigo, mixed $hasta): void
    {
        ProductoImportadoStaging::create([
            'importacion_id'  => $importacion->id,
            'datos_extraidos' => [
                'pagina'          => 1,
                'marca'           => 'Cklass',
                'modelo'          => $codigo,
                'nombre'          => 'Zapato',
                'color'           => 'NEGRO',
                'color_base_id'   => Color::query()->orderBy('id')->firstOrFail()->id,
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
}
