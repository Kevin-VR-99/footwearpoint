<?php

namespace Tests\Feature\Reportes;

use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\Usuario;
use App\Services\Reporte\EstadoDeCuentaAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstadoDeCuentaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
    }

    public function test_arma_saldo_corrido_con_abonos_cargos_y_suscripcion(): void
    {
        $staffId = (int) DistribuidoraStaff::query()->value('id');
        $this->pago('EC-ANTES', 'anticipo', 'entrada', 80, '2029-12-01 10:00:00', $staffId);
        $this->pago('EC-ABONO', 'anticipo', 'entrada', 200, '2030-01-10 10:00:00', $staffId);
        $this->pago('EC-CARGO', 'reembolso_anticipo', 'salida', 50, '2030-01-12 10:00:00', $staffId);
        $this->pago('EC-PLAN', 'suscripcion', 'entrada', 150, '2030-01-15 10:00:00', $staffId);

        $cuenta = app(EstadoDeCuentaAction::class)->ejecutar('2030-01-01', '2030-01-31', null, null);
        $filas = collect($cuenta['movimientos'])->keyBy('folio');

        $this->assertEquals(200, $filas['EC-ABONO']['abono']);
        $this->assertEquals(0, $filas['EC-ABONO']['cargo']);
        $this->assertEquals(50, $filas['EC-CARGO']['cargo']);
        $this->assertEquals(150, $filas['EC-PLAN']['cargo']);
        $this->assertEquals(0, $filas['EC-PLAN']['abono']);
        $this->assertEquals(round($filas['EC-ABONO']['saldo'] - 50, 2), $filas['EC-CARGO']['saldo']);
        $this->assertEquals(round($filas['EC-CARGO']['saldo'] - 150, 2), $filas['EC-PLAN']['saldo']);
        $this->assertSame('Suscripción FootwearPoint', $filas['EC-PLAN']['concepto']);
    }

    public function test_la_pantalla_muestra_el_logo_y_el_estado_de_cuenta(): void
    {
        $casa = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
        $casa->update(['logotipo_url' => 'https://ejemplo.test/logo-ramirez.png']);
        $staffId = (int) DistribuidoraStaff::query()->value('id');
        $this->pago('EC-PANTALLA', 'anticipo', 'entrada', 200, '2030-02-01 10:00:00', $staffId);

        $this->get(route('reportes.index'))
            ->assertOk()
            ->assertSee('Estado de cuenta')
            ->assertSee('Logo de '.$casa->nombre_comercial)
            ->assertSee('https://ejemplo.test/logo-ramirez.png')
            ->assertSee('EC-PANTALLA')
            ->assertSee('$200.00')
            ->assertSee('Descargar PDF')
            ->assertSee('Descargar Excel')
            ->assertDontSee('window.print()', false);
    }

    private function pago(string $folio, string $tipo, string $direccion, float $monto, string $fecha, int $staffId): void
    {
        Pago::create([
            'distribuidora_id' => Tenant::id(),
            'folio' => $folio,
            'tipo' => $tipo,
            'direccion' => $direccion,
            'metodo' => 'efectivo',
            'monto' => $monto,
            'fecha_pago' => $fecha,
            'estado' => 'aplicado',
            'registrado_por_staff_id' => $staffId,
        ]);
    }
}
