<?php

namespace Tests\Feature\Reportes;

use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Descarga del reporte de la distribuidora en PDF y Excel con los filtros de la pantalla. */
class DescargarReporteTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';
    private const EMPLEADO = 'empleado@calzadosramirez.test';

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $usuario = Usuario::where('email', $email)->firstOrFail();
        $this->actingAs($usuario);
        Tenant::olvidarCache();

        return $usuario;
    }

    private function pago(string $folio, float $monto, string $fecha): void
    {
        Pago::create([
            'distribuidora_id' => Tenant::id(),
            'folio' => $folio,
            'tipo' => 'anticipo',
            'direccion' => 'entrada',
            'metodo' => 'efectivo',
            'monto' => $monto,
            'fecha_pago' => $fecha,
            'estado' => 'aplicado',
            'registrado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
        ]);
    }

    public function test_el_admin_descarga_el_pdf_con_los_filtros(): void
    {
        $this->como(self::ADMIN);
        $this->pago('DR-DENTRO', 200, '2030-01-10 10:00:00');

        $respuesta = $this->get(route('reportes.descargar', ['formato' => 'pdf', 'desde' => '2030-01-01', 'hasta' => '2030-01-31']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
        $this->assertStringContainsString('attachment; filename="reporte-calzados-ramirez-', $respuesta->headers->get('Content-Disposition'));
    }

    public function test_el_admin_descarga_el_excel_y_respeta_el_periodo(): void
    {
        $this->como(self::ADMIN);
        $this->pago('DR-DENTRO', 200, '2030-01-10 10:00:00');
        $this->pago('DR-FUERA', 90, '2030-03-10 10:00:00');

        $respuesta = $this->get(route('reportes.descargar', ['formato' => 'xlsx', 'desde' => '2030-01-01', 'hasta' => '2030-01-31']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $archivo = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        file_put_contents($archivo, $respuesta->getContent());
        $libro = IOFactory::load($archivo);
        @unlink($archivo);

        $this->assertSame('Estado de cuenta', $libro->getSheet(0)->getTitle());
        $movimientos = $libro->getSheetByName('Movimientos')->toArray();
        $folios = array_column($movimientos, 2);
        $this->assertContains('DR-DENTRO', $folios);
        $this->assertNotContains('DR-FUERA', $folios);
        $this->assertStringContainsString('Periodo: 01/01/2030 al 31/01/2030', (string) $movimientos[1][0]);
    }

    public function test_el_empleado_sin_permiso_no_descarga(): void
    {
        $this->como(self::EMPLEADO);

        $this->get(route('reportes.descargar', ['formato' => 'pdf']))->assertForbidden();
        $this->get(route('reportes.descargar', ['formato' => 'xlsx']))->assertForbidden();
    }

    public function test_el_empleado_con_permiso_descarga_ambos(): void
    {
        $this->como(self::EMPLEADO);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(Tenant::id());
        Usuario::where('email', self::EMPLEADO)->firstOrFail()->givePermissionTo('reportes.ver_financieros');
        $registrar->forgetCachedPermissions();
        $this->como(self::EMPLEADO);

        $this->get(route('reportes.descargar', ['formato' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('reportes.descargar', ['formato' => 'xlsx']))->assertOk();
    }

    public function test_sin_sesion_y_con_filtros_invalidos(): void
    {
        $this->get(route('reportes.descargar', ['formato' => 'pdf']))->assertRedirect();

        $this->como(self::ADMIN);
        $this->get('/reportes/descargar/csv')->assertNotFound();
        $this->get(route('reportes.descargar', ['formato' => 'xlsx', 'desde' => 'ayer']))->assertSessionHasErrors('desde');
    }
}
