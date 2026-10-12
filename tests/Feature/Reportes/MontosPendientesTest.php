<?php

namespace Tests\Feature\Reportes;

use App\Models\CicloCompra;
use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProductoCampana;
use App\Models\RevendedorDistribuidora;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Vale;
use App\Models\Variante;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Services\Reporte\MontosPendientesAction;
use App\Services\Vale\AplicarValeAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-252 (R10, E14-03) — Montos pendientes por cliente y cliente mayorista
 * (foto al día de hoy, ciclo opcional) y vales vigentes.
 */
class MontosPendientesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->como(self::ADMIN);
        // Partimos de cero: el seeder demo puede traer pedidos y vales.
        Pedido::query()->update(['estado' => 'borrador']);
        Vale::query()->update(['estado' => 'bloqueado']);
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

    private function staffId(): int
    {
        return (int) DistribuidoraStaff::query()->value('id');
    }

    private function cliente(int $n = 0): ClienteDirecto
    {
        return ClienteDirecto::query()->orderBy('id')->skip($n)->firstOrFail();
    }

    private function mayorista(int $n = 0): RevendedorDistribuidora
    {
        return RevendedorDistribuidora::query()->with('revendedor')->orderBy('id')->skip($n)->firstOrFail();
    }

    private function pedido(array $duenio, float $total, string $estado = 'colocado', float $anticipo = 0, ?int $ciclo = null): Pedido
    {
        $pedido = Pedido::create(array_merge([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'MP-'.uniqid(),
            'ciclo_compra_id' => $ciclo,
            'estado' => $estado,
            'subtotal' => $total,
            'total' => $total,
            'fecha_colocacion' => $estado === 'borrador' ? null : now(),
            'capturado_por_staff_id' => $this->staffId(),
        ], $duenio));

        if ($anticipo > 0) {
            $pc = ProductoCampana::query()->firstOrFail();
            $variante = Variante::query()->where('producto_id', $pc->producto_id)->firstOrFail();
            PedidoDetalle::query()->forceCreate([
                'distribuidora_id' => Tenant::id(), 'pedido_id' => $pedido->id,
                'producto_campana_id' => $pc->id, 'variante_id' => $variante->id,
                'producto_nombre' => 'Prueba', 'modelo' => 'M', 'talla' => '25', 'color' => 'Negro',
                'cantidad' => 1, 'precio_unitario' => $total, 'subtotal' => $total,
                'anticipo_requerido' => $anticipo,
            ]);
        }

        return $pedido;
    }

    private function deCliente(ClienteDirecto $c): array
    {
        return ['tipo' => 'cliente_directo', 'cliente_directo_id' => $c->id];
    }

    private function deMayorista(RevendedorDistribuidora $a): array
    {
        return ['tipo' => 'revendedor', 'revendedor_distribuidora_id' => $a->id];
    }

    private function vale(float $saldo, array $extra = []): Vale
    {
        return Vale::create(array_merge([
            'distribuidora_id' => Tenant::id(),
            'cliente_directo_id' => $this->cliente()->id,
            'folio' => 'VAL-MP-'.uniqid(),
            'monto_original' => max($saldo, 1),
            'saldo_actual' => $saldo,
            'fecha_emision' => now(),
            'fecha_vencimiento' => now()->addMonth(),
            'estado' => 'activo',
            'creado_por_staff_id' => $this->staffId(),
        ], $extra));
    }

    private function reporte(?int $ciclo = null): array
    {
        return app(MontosPendientesAction::class)->ejecutar($ciclo);
    }

    private function fila(array $seccion, int $id): ?array
    {
        return collect($seccion['filas'])->firstWhere('id', $id);
    }

    public function test_cuenta_pedidos_con_saldo_y_omite_los_pagados_del_todo(): void
    {
        $c = $this->cliente();
        $this->pedido($this->deCliente($c), 1000);
        $pagado = $this->pedido($this->deCliente($c), 400);
        app(RegistrarPagoPedidoAction::class)->ejecutar($pagado, ['tipo' => 'saldo_pedido', 'metodo' => 'efectivo', 'monto' => 400]);

        $fila = $this->fila($this->reporte()['clientes_directos'], $c->id);
        $this->assertSame(1, $fila['pedidos']);
        $this->assertEqualsWithDelta(1000, $fila['pendiente'], 0.001);
    }

    public function test_excluye_borradores_y_cerrados_pero_cuenta_entregados_con_saldo(): void
    {
        $a = $this->mayorista();
        $this->pedido($this->deMayorista($a), 300, 'entregado');
        foreach (['borrador', 'rechazado', 'descartado', 'no_surtido', 'vencido_recoleccion'] as $estado) {
            $this->pedido($this->deMayorista($a), 1000, $estado);
        }

        $fila = $this->fila($this->reporte()['revendedores'], $a->id);
        $this->assertSame(1, $fila['pedidos']);
        $this->assertEqualsWithDelta(300, $fila['pendiente'], 0.001);
    }

    public function test_pagos_y_vales_reducen_el_pendiente(): void
    {
        $a = $this->mayorista();
        $p = $this->pedido($this->deMayorista($a), 1000);
        app(RegistrarPagoPedidoAction::class)->ejecutar($p, ['tipo' => 'total_revendedor', 'metodo' => 'efectivo', 'monto' => 200]);
        $vale = $this->vale(300, ['cliente_directo_id' => null, 'revendedor_distribuidora_id' => $a->id]);
        app(AplicarValeAction::class)->ejecutar($vale, ['monto' => 300, 'pedido_id' => $p->id]);

        $fila = $this->fila($this->reporte()['revendedores'], $a->id);
        $this->assertEqualsWithDelta(500, $fila['pagado'], 0.001);
        $this->assertEqualsWithDelta(500, $fila['pendiente'], 0.001);
    }

    public function test_anticipo_pendiente_de_clientes_directos(): void
    {
        $c = $this->cliente();
        $p = $this->pedido($this->deCliente($c), 1000, anticipo: 300);
        app(RegistrarPagoPedidoAction::class)->ejecutar($p, ['tipo' => 'anticipo', 'metodo' => 'efectivo', 'monto' => 100]);

        $r = $this->reporte()['clientes_directos'];
        $this->assertEqualsWithDelta(200, $this->fila($r, $c->id)['anticipo_pendiente'], 0.001);
        $this->assertEqualsWithDelta(900, $this->fila($r, $c->id)['pendiente'], 0.001);
        $this->assertEqualsWithDelta(200, $r['totales']['anticipo_pendiente'], 0.001);
    }

    public function test_separa_clientes_directos_y_mayoristas_y_ordena_por_pendiente(): void
    {
        [$c1, $c2, $a] = [$this->cliente(0), $this->cliente(1), $this->mayorista()];
        $this->pedido($this->deCliente($c1), 100);
        $this->pedido($this->deCliente($c2), 900);
        $this->pedido($this->deMayorista($a), 500);

        $r = $this->reporte();
        $this->assertSame($c2->id, $r['clientes_directos']['filas'][0]['id']);
        $this->assertSame(2, $r['clientes_directos']['totales']['clientes']);
        $this->assertEqualsWithDelta(1000, $r['clientes_directos']['totales']['pendiente'], 0.001);
        $this->assertSame([$a->id], array_column($r['revendedores']['filas'], 'id'));
        $this->assertArrayNotHasKey('anticipo_pendiente', $r['revendedores']['filas'][0]);
    }

    public function test_filtra_por_ciclo(): void
    {
        Livewire::test('ciclo.index');
        $ciclo = CicloCompra::query()->firstOrFail();
        $c = $this->cliente();
        $this->pedido($this->deCliente($c), 100, ciclo: $ciclo->id);
        $this->pedido($this->deCliente($c), 900);

        $this->assertEqualsWithDelta(100, $this->fila($this->reporte($ciclo->id)['clientes_directos'], $c->id)['pendiente'], 0.001);
    }

    public function test_vales_vigentes_solo_activos_con_saldo_y_sin_vencer(): void
    {
        $vigente = $this->vale(250);
        $this->vale(100, ['fecha_vencimiento' => now()->subDay()]);
        $this->vale(0, ['estado' => 'agotado']);
        $mayorista = $this->vale(50, ['cliente_directo_id' => null, 'revendedor_distribuidora_id' => $this->mayorista()->id]);

        $staffAjeno = DistribuidoraStaff::withoutGlobalScopes()->where('distribuidora_id', '!=', Tenant::id())->first();
        if ($staffAjeno) {
            Vale::withoutGlobalScopes()->insert([
                'distribuidora_id' => $staffAjeno->distribuidora_id, 'folio' => 'VAL-AJENO-MP',
                'monto_original' => 999, 'saldo_actual' => 999, 'fecha_emision' => now(),
                'fecha_vencimiento' => now()->addMonth(), 'estado' => 'activo', 'creado_por_staff_id' => $staffAjeno->id,
            ]);
        }

        $vales = $this->reporte()['vales_vigentes'];
        $this->assertEqualsCanonicalizing([$vigente->folio, $mayorista->folio], array_column($vales['filas'], 'folio'));
        $this->assertEqualsWithDelta(300, $vales['totales']['saldo'], 0.001);
        $this->assertSame('revendedor', collect($vales['filas'])->firstWhere('folio', $mayorista->folio)['propietario']['tipo']);
    }

    public function test_otra_distribuidora_no_ve_estos_montos(): void
    {
        $this->pedido($this->deCliente($this->cliente()), 1000);
        $otra = \App\Models\Distribuidora::create([
            'nombre_comercial' => 'Otra MP', 'slug' => 'otra-mp-'.uniqid(), 'estado' => 'activa',
            'fecha_solicitud' => now(), 'fecha_aprobacion' => now(),
        ]);

        Tenant::forzar($otra->id, function () {
            $r = $this->reporte();
            $this->assertSame([], $r['clientes_directos']['filas']);
            $this->assertSame([], $r['vales_vigentes']['filas']);
        });
    }

    public function test_la_pantalla_muestra_las_secciones_con_cliente_mayorista(): void
    {
        $a = $this->mayorista();
        $this->pedido($this->deMayorista($a), 4321);
        $vale = $this->vale(75);

        $html = $this->get(route('reportes.index'))->assertOk()
            ->assertSee('Montos pendientes')
            ->assertSee('Clientes mayoristas')
            ->assertSee('Vales vigentes')
            ->assertSee($a->revendedor->nombre)
            ->assertSee('$4,321.00')
            ->assertSee($vale->folio)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/>\s*Revendedor(es)?\s*</u', $html);
    }

    public function test_el_empleado_sin_permiso_recibe_403_en_pantalla_y_api(): void
    {
        $usuario = $this->como(self::EMPLEADO);
        $this->get(route('reportes.index'))->assertForbidden();

        Sanctum::actingAs($usuario);
        $this->getJson('/api/reportes/montos-pendientes')->assertForbidden();
    }

    public function test_el_empleado_con_permiso_y_el_admin_usan_el_endpoint(): void
    {
        $c = $this->cliente();
        $this->pedido($this->deCliente($c), 650);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(Tenant::id());
        Usuario::where('email', self::EMPLEADO)->firstOrFail()->givePermissionTo('reportes.ver_financieros');
        $registrar->forgetCachedPermissions();

        foreach ([self::EMPLEADO, self::ADMIN] as $email) {
            Sanctum::actingAs($this->como($email));

            $this->getJson('/api/reportes/montos-pendientes')
                ->assertOk()
                ->assertJsonPath('data.clientes_directos.filas.0.id', $c->id)
                ->assertJsonPath('data.clientes_directos.totales.pedidos', 1)
                ->assertJsonStructure(['data' => ['filtros', 'clientes_directos', 'revendedores', 'vales_vigentes' => ['filas', 'totales']]]);
        }

        $this->getJson('/api/reportes/montos-pendientes?ciclo_id=abc')->assertStatus(422);
    }
}
