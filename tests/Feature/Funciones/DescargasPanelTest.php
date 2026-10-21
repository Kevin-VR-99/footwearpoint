<?php

namespace Tests\Feature\Funciones;

use App\Models\Usuario;
use App\Services\Exportar\ExportarListadoXlsx;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cada descarga del panel de la distribuidora responde 200 con el tipo correcto,
 * y la capa "Cargando…" de cada pantalla solo escucha acciones concretas.
 */
class DescargasPanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private function comoAdmin(): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
    }

    public function test_reporte_pdf_y_excel_responden_200_con_su_tipo(): void
    {
        $this->comoAdmin();

        $pdf = $this->get(route('reportes.descargar', ['formato' => 'pdf']));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('attachment;', $pdf->headers->get('Content-Disposition'));

        $xlsx = $this->get(route('reportes.descargar', ['formato' => 'xlsx']));
        $xlsx->assertOk()->assertHeader('Content-Type', self::XLSX);
        $this->assertStringStartsWith('PK', $xlsx->getContent());
    }

    public static function exportaciones(): array
    {
        return [
            'pedidos' => ['pedidos.index', 'pedidos-'],
            'clientes directos' => ['distribuidora.clientes', 'clientes-directos-'],
            'clientes mayoristas' => ['distribuidora.usuarios', 'clientes-mayoristas-'],
        ];
    }

    /** @dataProvider exportaciones */
    public function test_exportaciones_livewire_devuelven_stream_xlsx(string $componente, string $prefijo): void
    {
        $this->comoAdmin();
        $params = [];

        $c = Livewire::test($componente, $params)->call('exportar');
        $c->assertFileDownloaded();
        $descarga = data_get($c->effects, 'download');
        $this->assertStringStartsWith($prefijo, $descarga['name']);
        $this->assertSame(self::XLSX, $descarga['contentType']);
        $this->assertStringStartsWith('PK', base64_decode($descarga['content']));
    }

    public function test_el_excel_de_listado_se_corta_en_el_tope_de_filas(): void
    {
        $filas = (function () {
            for ($i = 0; $i < ExportarListadoXlsx::MAX_FILAS + 50; $i++) {
                yield [$i, 'x'];
            }
        })();
        $hoja = (new ExportarListadoXlsx)->libro('Prueba', ['N', 'X'], $filas)->getActiveSheet();

        $this->assertLessThanOrEqual(ExportarListadoXlsx::MAX_FILAS + 10, $hoja->getHighestRow());
        $this->assertStringContainsString('primeras', (string) $hoja->getCell('A'.$hoja->getHighestRow())->getValue());
    }

    public static function pantallasConCapa(): array
    {
        return [['/stock'], ['/punto-venta'], ['/vales'], ['/cambios'], ['/catalogo/precios'], ['/auditoria']];
    }

    /** @dataProvider pantallasConCapa */
    public function test_la_capa_cargando_inicia_oculta_y_tiene_objetivo(string $ruta): void
    {
        $this->comoAdmin();
        $html = $this->get($ruta)->assertOk()->getContent();

        preg_match_all('/<div[^>]*data-cargando[^>]*>/', $html, $capas);
        $this->assertNotEmpty($capas[0], "Sin capa en {$ruta}");
        foreach ($capas[0] as $capa) {
            $this->assertStringContainsString('wire:target=', $capa);
            $this->assertStringContainsString('wire:loading.delay.long.flex', $capa);
            $this->assertStringContainsString('display: none', $capa);
        }
    }
}
