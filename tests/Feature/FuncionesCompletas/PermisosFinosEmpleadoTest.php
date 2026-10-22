<?php

namespace Tests\Feature\FuncionesCompletas;

use App\Models\Auditoria;
use App\Models\StockLocal;
use App\Services\Auth\PermisoModuloService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** Permisos finos por empleado: valores por omisión, guardado y que el servidor los respete. */
class PermisosFinosEmpleadoTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    private function quitar(string $permiso): void
    {
        $staff = $this->staffDe(self::EMPLEADO);
        $staff->forceFill(['permisos_finos' => [$permiso => false]])->save();
    }

    public function test_sin_configurar_el_empleado_conserva_lo_que_ya_podia_hacer(): void
    {
        $empleado = $this->comoPanel(self::EMPLEADO);
        $permisos = app(PermisoModuloService::class);

        foreach ([PermisoModuloService::PAGOS_REGISTRAR, PermisoModuloService::STOCK_AJUSTAR, PermisoModuloService::COSTOS_VER, PermisoModuloService::CAJA_OPERAR] as $p) {
            $this->assertTrue($permisos->puede($empleado, $p), $p);
        }
        foreach ([PermisoModuloService::VENTAS_CANCELAR, PermisoModuloService::CLIENTES_IMPORTAR, PermisoModuloService::COMISIONES_VER] as $p) {
            $this->assertFalse($permisos->puede($empleado, $p), $p);
        }

        // Y el admin todo.
        $admin = $this->comoPanel(self::ADMIN);
        $this->assertTrue($permisos->puede($admin, PermisoModuloService::VENTAS_CANCELAR));
    }

    public function test_un_revendedor_nunca_tiene_permisos_finos(): void
    {
        $maria = $this->comoApi(self::MARIA);
        $this->assertFalse(app(PermisoModuloService::class)->puede($maria, PermisoModuloService::PAGOS_REGISTRAR));
    }

    public function test_el_admin_prende_y_apaga_permisos_desde_empleados_y_queda_en_bitacora(): void
    {
        $this->comoPanel(self::ADMIN);
        $staff = $this->staffDe(self::EMPLEADO);

        Livewire::test('distribuidora.usuarios')
            ->set("permisosEmpleado.{$staff->id}", [PermisoModuloService::VENTAS_CANCELAR, PermisoModuloService::CAJA_OPERAR])
            ->call('guardarPermisos', $staff->id);

        $finos = PermisoModuloService::finosDe($staff->fresh());
        $this->assertTrue($finos[PermisoModuloService::VENTAS_CANCELAR]);
        $this->assertTrue($finos[PermisoModuloService::CAJA_OPERAR]);
        $this->assertFalse($finos[PermisoModuloService::PAGOS_REGISTRAR]);
        $this->assertTrue(Auditoria::where('accion', 'empleado.permisos_actualizados')->where('entidad_id', $staff->id)->exists());
    }

    public function test_el_empleado_no_puede_mandarse_permisos_desde_la_pantalla_de_empleados(): void
    {
        $this->comoPanel(self::EMPLEADO);
        $this->get(route('distribuidora.empleados'))->assertForbidden();
    }

    public function test_sin_registrar_pagos_la_api_responde_403_y_el_panel_oculta_el_formulario(): void
    {
        $pedidoId = $this->pedidoEnviadoDeMaria();
        $this->quitar(PermisoModuloService::PAGOS_REGISTRAR);

        $this->comoApi(self::EMPLEADO);
        $this->postJson("/api/pedidos/{$pedidoId}/pagos", ['tipo' => 'saldo_pedido', 'metodo' => 'efectivo', 'monto' => 10])
            ->assertForbidden();

        $this->comoPanel(self::EMPLEADO);
        Livewire::test('pedidos.show', ['id' => $pedidoId])->assertDontSee('Registrar pago')
            ->set('pagoMetodo', 'efectivo')->set('pagoMonto', '10')->call('registrarPago')
            ->assertSet('errorMsg', fn ($m) => str_contains($m, 'permiso'));
    }

    public function test_sin_ajustar_stock_no_hay_entradas_ni_ajustes(): void
    {
        $this->quitar(PermisoModuloService::STOCK_AJUSTAR);
        $stock = StockLocal::withoutGlobalScopes()->where('distribuidora_id', $this->ramirez()->id)->where('cantidad_disponible', '>', 0)->firstOrFail();

        $this->comoApi(self::EMPLEADO);
        $this->postJson('/api/stock/ajustes', ['variante_id' => $stock->variante_id, 'tipo' => 'ajuste_negativo', 'cantidad' => 1, 'motivo' => 'Merma'])
            ->assertForbidden();
        $this->postJson('/api/stock/entradas', ['variante_id' => $stock->variante_id, 'cantidad' => 1])->assertForbidden();

        $this->comoPanel(self::EMPLEADO);
        Livewire::test('stock.index')->assertDontSee('Ajuste manual')->assertSee('pídele el permiso');
    }

    public function test_sin_ver_mayoreo_el_catalogo_no_manda_el_precio_mayorista(): void
    {
        $this->comoApi(self::EMPLEADO);
        $this->assertArrayHasKey('precio_mayorista', $this->getJson('/api/catalogo')->assertOk()->json('data.0'));

        $this->quitar(PermisoModuloService::COSTOS_VER);
        $this->comoApi(self::EMPLEADO);
        $this->assertArrayNotHasKey('precio_mayorista', $this->getJson('/api/catalogo')->assertOk()->json('data.0'));
    }
}
