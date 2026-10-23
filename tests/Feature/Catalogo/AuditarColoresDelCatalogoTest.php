<?php

namespace Tests\Feature\Catalogo;

use App\Models\Auditoria;
use App\Models\Color;
use App\Models\ImportacionCatalogo;
use App\Models\ProductoImportadoStaging;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Guardar los colores del catálogo deja constancia en la auditoría:
 * quién, qué mapa había, qué mapa quedó y cuántas filas cambiaron.
 */
class AuditarColoresDelCatalogoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private ImportacionCatalogo $importacion;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->importacion = ImportacionCatalogo::create([
            'linea_id'                => 3,
            'archivo_url'             => 'catalogos-ia/colores.pdf',
            'tipo_archivo'            => 'pdf',
            'estado'                  => 'requiere_revision',
            'paginas'                 => 4,
            'iniciada_por_usuario_id' => $this->admin()->id,
        ]);
    }

    private function admin(): Usuario
    {
        return Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();
    }

    private function color(string $nombre): Color
    {
        return Color::where('nombre', $nombre)->firstOrFail();
    }

    private function fila(string $color, ?int $base = null, string $estado = 'pendiente'): ProductoImportadoStaging
    {
        return ProductoImportadoStaging::create([
            'importacion_id'  => $this->importacion->id,
            'datos_extraidos' => ['pagina' => 1, 'codigo_catalogo' => 'X-1', 'color' => $color, 'color_base_id' => $base],
            'campos_dudosos'  => [],
            'estado'          => $estado,
        ]);
    }

    private function guardar(array $mapa)
    {
        $this->actingAs($this->admin());

        return Livewire::test('admin.revisar-catalogo', ['importacion' => $this->importacion->id])
            ->set('coloresBase', $mapa)
            ->call('guardarColores');
    }

    private function ultima(): Auditoria
    {
        return Auditoria::withoutGlobalScopes()
            ->where('accion', 'importacion_catalogo.colores_asignados')
            ->latest('id')->firstOrFail();
    }

    /**
     * MySQL reordena las claves de una columna JSON (por longitud y luego
     * por bytes) y MariaDB no; se ordenan por nombre para comparar igual en
     * los dos motores.
     */
    private function ordenado(array $mapa): array
    {
        ksort($mapa);

        return $mapa;
    }

    public function test_registra_mapa_previo_nuevo_y_filas_cambiadas(): void
    {
        $negro = $this->color('Negro');
        $blanco = $this->color('Blanco');

        $this->fila('MULTICOLOR', $blanco->id);
        $this->fila('MULTICOLOR', $blanco->id);
        $this->fila('HUESO');
        $this->fila('MIEL', $blanco->id);
        $this->fila('HUESO', null, 'descartado'); // no cuenta

        $this->guardar([
            'MULTICOLOR' => $negro->id,
            'HUESO'      => $blanco->id,
            'MIEL'       => $blanco->id, // ya lo tenía: no cambia
        ]);

        $a = $this->ultima();

        $this->assertSame($this->admin()->id, $a->usuario_id);
        $this->assertSame('importacion_catalogo', $a->entidad_tipo);
        $this->assertSame($this->importacion->id, $a->entidad_id);

        $this->assertSame([
            'HUESO'      => ['color' => null, 'color_base_id' => null],
            'MIEL'       => ['color' => 'Blanco', 'color_base_id' => $blanco->id],
            'MULTICOLOR' => ['color' => 'Blanco', 'color_base_id' => $blanco->id],
        ], $this->ordenado($a->datos_previos['colores']));

        $this->assertSame([
            'HUESO'      => ['color' => 'Blanco', 'color_base_id' => $blanco->id],
            'MIEL'       => ['color' => 'Blanco', 'color_base_id' => $blanco->id],
            'MULTICOLOR' => ['color' => 'Negro', 'color_base_id' => $negro->id],
        ], $this->ordenado($a->datos_nuevos['colores']));

        $this->assertSame(3, $a->datos_nuevos['filas_cambiadas']);
        $this->assertSame(['HUESO' => 1, 'MULTICOLOR' => 2], $a->datos_nuevos['filas_cambiadas_por_nombre']);
        $this->assertSame(4, $a->datos_nuevos['filas_resueltas']);
    }

    public function test_sin_cambios_igual_registra_con_cero(): void
    {
        $negro = $this->color('Negro');
        $this->fila('NEGRO', $negro->id);

        $this->guardar(['NEGRO' => $negro->id]);

        $a = $this->ultima();
        $this->assertSame(0, $a->datos_nuevos['filas_cambiadas']);
        $this->assertSame([], $a->datos_nuevos['filas_cambiadas_por_nombre']);
        $this->assertSame($a->datos_previos['colores'], $a->datos_nuevos['colores']);
    }

    public function test_los_colores_sin_elegir_quedan_en_el_mapa_sin_cambiar(): void
    {
        $negro = $this->color('Negro');
        $this->fila('MALVA');
        $this->fila('NEGRO');

        $this->guardar(['MALVA' => '', 'NEGRO' => $negro->id]);

        $a = $this->ultima();
        $this->assertSame(['color' => null, 'color_base_id' => null], $a->datos_nuevos['colores']['MALVA']);
        $this->assertSame(['NEGRO' => 1], $a->datos_nuevos['filas_cambiadas_por_nombre']);
    }
}