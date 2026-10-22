<?php

namespace Tests\Feature\FuncionesCompletas;

use App\Models\ConfiguracionDistribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\RevendedorDistribuidora;
use App\Models\Sucursal;
use App\Services\Auth\PermisoModuloService;
use App\Services\Comision\ComisionesService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** Comisiones sobre pedidos pagados, metas del mes y ranking. */
class ComisionesMetasTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    private const PERIODO = '2026-07';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-20 12:00:00');
        $this->comoPanel(self::ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function afiliaciones(): array
    {
        $todas = RevendedorDistribuidora::query()->orderBy('id')->limit(2)->get();
        $this->assertCount(2, $todas);

        return $todas->all();
    }

    private function pedido(RevendedorDistribuidora $a, float $total, float $pagado, string $fecha = '2026-07-10 10:00:00'): Pedido
    {
        $p = Pedido::create([
            'distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'COM-'.uniqid(), 'estado' => 'entregado', 'subtotal' => $total, 'total' => $total,
            'fecha_colocacion' => $fecha, 'tipo' => 'revendedor', 'revendedor_distribuidora_id' => $a->id,
            'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
        ]);
        if ($pagado > 0) {
            Pago::create([
                'distribuidora_id' => Tenant::id(), 'pedido_id' => $p->id, 'folio' => 'PCOM-'.uniqid(),
                'tipo' => 'total_revendedor', 'direccion' => 'entrada', 'metodo' => 'efectivo', 'monto' => $pagado,
                'estado' => 'aplicado', 'registrado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'), 'fecha_pago' => $fecha,
            ]);
        }

        return $p;
    }

    public function test_la_comision_solo_cuenta_pedidos_pagados_del_mes_con_su_porcentaje(): void
    {
        [$a, $b] = $this->afiliaciones();
        $servicio = app(ComisionesService::class);
        $servicio->fijarPorcentajeDefault(5);
        $servicio->fijarPorcentaje($b->id, 10);

        $this->pedido($a, 1000, 1000);          // pagado: cuenta
        $this->pedido($a, 500, 200);            // a medias: no cuenta
        $this->pedido($a, 800, 800, '2026-06-30 10:00:00'); // otro mes: no cuenta
        $this->pedido($b, 3000, 3000);

        $filas = collect($servicio->reporte(self::PERIODO))->keyBy('afiliacion_id');

        $this->assertEquals(1000, $filas[$a->id]['venta_pagada']);
        $this->assertSame(1, $filas[$a->id]['pedidos_pagados']);
        $this->assertEquals(50, $filas[$a->id]['comision']);
        $this->assertFalse($filas[$a->id]['porcentaje_propio']);
        $this->assertEquals(300, $filas[$b->id]['comision']);

        // Ranking: B primero.
        $this->assertSame(1, $filas[$b->id]['lugar']);
        $this->assertSame(2, $filas[$a->id]['lugar']);
    }

    public function test_la_meta_del_mes_muestra_avance_y_valida_negativos(): void
    {
        [$a] = $this->afiliaciones();
        $servicio = app(ComisionesService::class);
        $servicio->fijarMeta($a->id, self::PERIODO, 4000);
        $this->pedido($a, 1000, 1000);

        $fila = collect($servicio->reporte(self::PERIODO))->firstWhere('afiliacion_id', $a->id);
        $this->assertEquals(4000, $fila['meta']);
        $this->assertEquals(25.0, $fila['avance']);

        $this->expectExceptionMessage('negativa');
        $servicio->fijarMeta($a->id, self::PERIODO, -1);
    }

    public function test_porcentaje_fuera_de_rango_se_rechaza_y_el_empleado_no_edita(): void
    {
        [$a] = $this->afiliaciones();
        try {
            app(ComisionesService::class)->fijarPorcentaje($a->id, 120);
            $this->fail('Debió rechazar 120%.');
        } catch (\App\Exceptions\OperacionInvalidaException $e) {
            $this->assertStringContainsString('entre 0 y 100', $e->getMessage());
        }

        $this->staffDe(self::EMPLEADO)->update(['permisos_finos' => [PermisoModuloService::COMISIONES_VER => true]]);
        $this->comoPanel(self::EMPLEADO);
        $this->get(route('comisiones.index'))->assertOk();
        $this->expectException(\App\Exceptions\OperacionInvalidaException::class);
        app(ComisionesService::class)->fijarPorcentaje($a->id, 3);
    }

    public function test_el_empleado_sin_permiso_no_ve_comisiones_por_omision(): void
    {
        $this->comoPanel(self::EMPLEADO);
        $this->get(route('comisiones.index'))->assertForbidden();
        $this->comoApi(self::EMPLEADO);
        $this->getJson('/api/distribuidora/comisiones')->assertForbidden();
    }

    public function test_el_cliente_mayorista_ve_solo_lo_suyo_en_la_app(): void
    {
        $maria = $this->usuario(self::MARIA);
        $suya = RevendedorDistribuidora::query()->whereHas('revendedor', fn ($q) => $q->where('usuario_id', $maria->id))->firstOrFail();
        ConfiguracionDistribuidora::query()->update(['comision_porcentaje_default' => 8]);
        $this->pedido($suya, 2000, 2000);

        $this->comoApi(self::MARIA);
        $this->getJson('/api/revendedor/comisiones?periodo='.self::PERIODO)->assertOk()
            ->assertJsonPath('data.comision', 160)
            ->assertJsonPath('data.lugar', 1)
            ->assertJsonMissingPath('data.ranking');
        $this->getJson('/api/distribuidora/comisiones')->assertForbidden();
    }

    public function test_la_pantalla_guarda_porcentaje_y_meta(): void
    {
        [$a] = $this->afiliaciones();
        Livewire::test('comisiones.index', ['periodo' => self::PERIODO])
            ->set('periodo', self::PERIODO)
            ->set("porcentajes.{$a->id}", '7.5')->set("metas.{$a->id}", '1500')
            ->call('guardarFila', $a->id)->assertSet('errorMsg', '');

        $this->assertEquals(7.5, (float) $a->fresh()->comision_porcentaje);
        $fila = collect(app(ComisionesService::class)->reporte(self::PERIODO))->firstWhere('afiliacion_id', $a->id);
        $this->assertEquals(1500, $fila['meta']);

        // Otra distribuidora no ve a este cliente mayorista.
        $this->comoPanel(self::OTRA_ADMIN);
        $this->assertNull(collect(app(ComisionesService::class)->reporte(self::PERIODO))->firstWhere('afiliacion_id', $a->id));
    }
}
