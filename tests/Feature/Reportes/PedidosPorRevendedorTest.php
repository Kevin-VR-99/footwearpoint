<?php

namespace Tests\Feature\Reportes;

use App\Models\CicloCompra;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\RevendedorDistribuidora;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Vale;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Services\Reporte\PedidosPorRevendedorAction;
use App\Services\Vale\AplicarValeAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-251 (R9, E14-05) — Total de pedidos y montos por cliente mayorista
 * (revendedor en el código), con filtro por fecha de colocación.
 */
class PedidosPorRevendedorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->como(self::ADMIN);
        // Partimos de cero: el seeder demo puede traer pedidos de mayoristas.
        Pedido::query()->whereNotNull('revendedor_distribuidora_id')->update(['estado' => 'borrador', 'fecha_colocacion' => null]);
    }

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

    private function afiliacion(int $n): RevendedorDistribuidora
    {
        $afiliacion = RevendedorDistribuidora::query()->with('revendedor')->orderBy('id')->skip($n)->first();
        $this->assertNotNull($afiliacion, 'El seeder no dejó suficientes clientes mayoristas.');

        return $afiliacion;
    }

    private function staffId(): int
    {
        return (int) DistribuidoraStaff::query()->value('id');
    }

    private function pedido(RevendedorDistribuidora $a, float $total, string $fecha = '2026-09-15 12:00:00', string $estado = 'colocado', ?int $ciclo = null): Pedido
    {
        return Pedido::create([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'PRT-'.uniqid(),
            'tipo' => 'revendedor',
            'revendedor_distribuidora_id' => $a->id,
            'ciclo_compra_id' => $ciclo,
            'estado' => $estado,
            'subtotal' => $total,
            'total' => $total,
            'fecha_colocacion' => $estado === 'borrador' ? null : $fecha,
            'capturado_por_staff_id' => $this->staffId(),
        ]);
    }

    private function reporte(?string $desde = null, ?string $hasta = null, ?int $ciclo = null): array
    {
        return app(PedidosPorRevendedorAction::class)->ejecutar($desde, $hasta, $ciclo);
    }

    private function fila(array $r, RevendedorDistribuidora $a): ?array
    {
        return collect($r['filas'])->firstWhere('revendedor_distribuidora_id', $a->id);
    }

    public function test_agrupa_y_suma_por_cliente_mayorista_ordenado_por_monto(): void
    {
        [$a, $b] = [$this->afiliacion(0), $this->afiliacion(1)];
        $this->pedido($a, 1000);
        $this->pedido($a, 500);
        $this->pedido($b, 2000);

        $r = $this->reporte();

        $this->assertSame($b->id, $r['filas'][0]['revendedor_distribuidora_id']);
        $this->assertSame(2, $this->fila($r, $a)['pedidos']);
        $this->assertEqualsWithDelta(1500, $this->fila($r, $a)['monto'], 0.001);
        $this->assertSame(3, $r['totales']['pedidos']);
        $this->assertEqualsWithDelta(3500, $r['totales']['monto'], 0.001);
        $this->assertSame($a->revendedor->nombre, $this->fila($r, $a)['nombre']);
    }

    public function test_no_cuenta_pedidos_de_clientes_directos(): void
    {
        $antes = $this->reporte()['totales']['pedidos'];
        Pedido::create([
            'distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'PRT-CD-'.uniqid(), 'tipo' => 'cliente_directo',
            'cliente_directo_id' => ClienteDirecto::query()->value('id'),
            'estado' => 'colocado', 'subtotal' => 300, 'total' => 300,
            'fecha_colocacion' => now(), 'capturado_por_staff_id' => $this->staffId(),
        ]);

        $this->assertSame($antes, $this->reporte()['totales']['pedidos']);
    }

    public function test_filtra_por_fecha_de_colocacion_incluyendo_los_dias_limite(): void
    {
        $a = $this->afiliacion(0);
        $this->pedido($a, 100, '2026-09-01 00:05:00');
        $this->pedido($a, 200, '2026-09-30 23:50:00');
        $this->pedido($a, 400, '2026-10-01 10:00:00');
        $this->pedido($a, 800, '2026-08-31 23:59:00');

        $r = $this->reporte('2026-09-01', '2026-09-30');

        $this->assertSame(2, $this->fila($r, $a)['pedidos']);
        $this->assertEqualsWithDelta(300, $this->fila($r, $a)['monto'], 0.001);
    }

    public function test_filtra_por_ciclo(): void
    {
        $a = $this->afiliacion(0);
        // La pantalla del ciclo abre uno si no hay (como en PermisosModuloReportesCicloTest).
        Livewire::test('ciclo.index');
        $ciclo = CicloCompra::query()->first();
        $this->assertNotNull($ciclo, 'El seeder no dejó ciclos.');
        $this->pedido($a, 100, ciclo: $ciclo->id);
        $this->pedido($a, 900);

        $this->assertEqualsWithDelta(100, $this->fila($this->reporte(ciclo: $ciclo->id), $a)['monto'], 0.001);
    }

    public function test_excluye_borradores_y_pedidos_cerrados(): void
    {
        $a = $this->afiliacion(0);
        $this->pedido($a, 100, estado: 'entregado');
        foreach (['borrador', 'rechazado', 'descartado', 'no_surtido', 'vencido_recoleccion'] as $estado) {
            $this->pedido($a, 1000, estado: $estado);
        }

        $fila = $this->fila($this->reporte(), $a);
        $this->assertSame(1, $fila['pedidos']);
        $this->assertEqualsWithDelta(100, $fila['monto'], 0.001);
    }

    public function test_pagado_suma_pagos_y_vales_y_pendiente_lo_que_falta(): void
    {
        $a = $this->afiliacion(0);
        $p1 = $this->pedido($a, 1000);
        $p2 = $this->pedido($a, 500);

        app(RegistrarPagoPedidoAction::class)->ejecutar($p1, ['tipo' => 'total_revendedor', 'metodo' => 'efectivo', 'monto' => 300]);

        $vale = Vale::create([
            'distribuidora_id' => Tenant::id(), 'revendedor_distribuidora_id' => $a->id,
            'folio' => 'VAL-RPT-'.uniqid(), 'monto_original' => 500, 'saldo_actual' => 500,
            'fecha_emision' => now(), 'fecha_vencimiento' => now()->addMonth(), 'estado' => 'activo',
            'creado_por_staff_id' => $this->staffId(),
        ]);
        app(AplicarValeAction::class)->ejecutar($vale, ['monto' => 500, 'pedido_id' => $p2->id]);

        $fila = $this->fila($this->reporte(), $a);
        $this->assertEqualsWithDelta(1500, $fila['monto'], 0.001);
        $this->assertEqualsWithDelta(800, $fila['pagado'], 0.001);
        $this->assertEqualsWithDelta(700, $fila['pendiente'], 0.001);
    }

    public function test_otra_distribuidora_no_ve_estos_pedidos(): void
    {
        $this->pedido($this->afiliacion(0), 1000);
        $otra = Distribuidora::create([
            'nombre_comercial' => 'Otra RPT', 'slug' => 'otra-rpt-'.uniqid(), 'estado' => 'activa',
            'fecha_solicitud' => now(), 'fecha_aprobacion' => now(),
        ]);

        Tenant::forzar($otra->id, function () {
            $this->assertSame([], $this->reporte()['filas']);
        });
    }

    public function test_la_pantalla_muestra_cliente_mayorista_y_no_revendedor(): void
    {
        $a = $this->afiliacion(0);
        $this->pedido($a, 1234.5);

        $html = $this->get(route('reportes.index'))->assertOk()
            ->assertSee('Pedidos por cliente mayorista')
            ->assertSee($a->revendedor->nombre)
            ->assertSee('$1,234.50')
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/>\s*Revendedor(es)?\s*</u', $html);

        Livewire::test('reportes.index')->set('desde', '2026-10-01')
            ->assertSee('Sin pedidos de clientes mayoristas en el periodo');
    }

    public function test_el_empleado_sin_permiso_recibe_403_en_pantalla_y_api(): void
    {
        $usuario = $this->como(self::EMPLEADO);
        $this->get(route('reportes.index'))->assertForbidden();

        Sanctum::actingAs($usuario);
        $this->getJson('/api/reportes/pedidos-por-cliente-mayorista')->assertForbidden();
    }

    public function test_el_empleado_con_permiso_y_el_admin_usan_el_endpoint(): void
    {
        $a = $this->afiliacion(0);
        $this->pedido($a, 700);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(Tenant::id());
        Usuario::where('email', self::EMPLEADO)->firstOrFail()->givePermissionTo('reportes.ver_financieros');
        $registrar->forgetCachedPermissions();

        foreach ([self::EMPLEADO, self::ADMIN] as $email) {
            $usuario = $this->como($email);
            Sanctum::actingAs($usuario);

            $this->getJson('/api/reportes/pedidos-por-cliente-mayorista?desde=2026-09-01&hasta=2026-09-30')
                ->assertOk()
                ->assertJsonPath('data.totales.pedidos', 1)
                ->assertJsonPath('data.filas.0.revendedor_distribuidora_id', $a->id)
                ->assertJsonPath('data.filtros.desde', '2026-09-01');
        }
    }

    public function test_el_endpoint_valida_las_fechas(): void
    {
        Sanctum::actingAs(Usuario::where('email', self::ADMIN)->firstOrFail());

        $this->getJson('/api/reportes/pedidos-por-cliente-mayorista?desde=2026-09-30&hasta=2026-09-01')
            ->assertStatus(422)
            ->assertJsonPath('message', 'La fecha "hasta" no puede ser anterior a "desde".');
    }
}
