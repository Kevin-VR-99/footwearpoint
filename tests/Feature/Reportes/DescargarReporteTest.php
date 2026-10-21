<?php

namespace Tests\Feature\Reportes;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\RevendedorDistribuidora;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Vale;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** Datos como en producción: pedidos de clientes y mayoristas, pagos y vales de ambos tipos de propietario. */
    private function sembrarComoProduccion(): void
    {
        $staff = (int) DistribuidoraStaff::query()->value('id');
        $cliente = ClienteDirecto::query()->orderBy('id')->firstOrFail();
        $mayorista = RevendedorDistribuidora::query()->with('revendedor')->orderBy('id')->firstOrFail();
        foreach ([['cliente_directo', 'cliente_directo_id', $cliente->id, 'colocado'], ['revendedor', 'revendedor_distribuidora_id', $mayorista->id, 'entregado']] as $i => [$tipo, $campo, $id, $estado]) {
            $pedido = Pedido::create([
                'distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::query()->value('id'),
                'folio' => 'DRP-'.$i, 'tipo' => $tipo, $campo => $id, 'estado' => $estado,
                'subtotal' => 1500, 'total' => 1500, 'fecha_colocacion' => now(), 'capturado_por_staff_id' => $staff,
            ]);
            Pago::create([
                'distribuidora_id' => Tenant::id(), 'pedido_id' => $pedido->id, 'folio' => 'DRPAG-'.$i,
                'tipo' => 'anticipo', 'direccion' => 'entrada', 'metodo' => 'efectivo', 'monto' => 300,
                'fecha_pago' => now(), 'estado' => 'aplicado', 'registrado_por_staff_id' => $staff,
            ]);
        }
        foreach (['cliente_directo_id' => $cliente->id, 'revendedor_distribuidora_id' => $mayorista->id] as $campo => $id) {
            Vale::create([
                'distribuidora_id' => Tenant::id(), $campo => $id, 'folio' => 'VAL-DR-'.$campo,
                'monto_original' => 250, 'saldo_actual' => 250, 'fecha_emision' => now(),
                'fecha_vencimiento' => now()->addMonth(), 'estado' => 'activo', 'creado_por_staff_id' => $staff,
            ]);
        }
        $this->clienteNombre = $cliente->nombre;
        $this->mayoristaNombre = $mayorista->revendedor->nombre;
    }

    private string $clienteNombre = '';

    private string $mayoristaNombre = '';

    /** Filtros que manda el botón de la pantalla de reportes (y descarga sin filtros). */
    public static function filtrosDeLaPantalla(): array
    {
        return [
            'sin filtros' => [[]],
            'periodo' => [['desde' => now()->subMonth()->toDateString(), 'hasta' => now()->addDay()->toDateString()]],
            'estado' => [['estado' => 'colocado']],
            'todos' => [['desde' => now()->subMonth()->toDateString(), 'hasta' => now()->addDay()->toDateString(), 'estado' => 'entregado', 'ciclo' => 1, 'temporada' => 1, 'marca' => 1]],
        ];
    }

    #[DataProvider('filtrosDeLaPantalla')]
    public function test_pdf_y_excel_con_vales_y_datos_reales_no_fallan(array $filtros): void
    {
        $this->como(self::ADMIN);
        $this->sembrarComoProduccion();

        $pdf = $this->get(route('reportes.descargar', ['formato' => 'pdf'] + $filtros))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $xlsx = $this->get(route('reportes.descargar', ['formato' => 'xlsx'] + $filtros))->assertOk();
        $archivo = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        file_put_contents($archivo, $xlsx->getContent());
        $libro = IOFactory::load($archivo);
        @unlink($archivo);
        $vales = $libro->getSheetByName('Vales vigentes')->toArray();
        $propietarios = array_column($vales, 1);
        $this->assertContains($this->clienteNombre.' (Cliente)', $propietarios);
        $this->assertContains($this->mayoristaNombre.' (Cliente mayorista)', $propietarios);
        foreach ($libro->getAllSheets() as $hoja) {
            foreach ($hoja->toArray() as $fila) {
                foreach ($fila as $valor) {
                    $this->assertNotSame('Array', $valor);
                }
            }
        }
    }

    public function test_filtros_como_arreglo_en_la_url_no_truenan(): void
    {
        $this->como(self::ADMIN);
        $this->sembrarComoProduccion();

        foreach (['pdf', 'xlsx'] as $formato) {
            $this->get('/reportes/descargar/'.$formato.'?estado[]=colocado&estado[]=entregado')->assertSessionHasErrors('estado');
            $this->get('/reportes/descargar/'.$formato.'?desde[]=2030-01-01&ciclo[]=1')->assertSessionHasErrors(['desde', 'ciclo']);
        }
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
