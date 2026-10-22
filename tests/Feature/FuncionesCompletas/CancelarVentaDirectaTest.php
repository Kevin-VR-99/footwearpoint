<?php

namespace Tests\Feature\FuncionesCompletas;

use App\Models\Auditoria;
use App\Models\MovimientoStock;
use App\Models\Pago;
use App\Models\StockLocal;
use App\Models\VentaDirecta;
use App\Services\Auth\PermisoModuloService;
use App\Services\Reporte\ResumenOperativoAction;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** Cancelar una venta de mostrador: stock de regreso, devolución, bitácora y permisos. */
class CancelarVentaDirectaTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    /** Vende 2 piezas del primer renglón del punto de venta y regresa [venta, stock, existencia antes]. */
    private function vender(string $email = self::ADMIN, string $metodo = 'efectivo'): array
    {
        $this->comoPanel($email);
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $stock = StockLocal::withoutGlobalScopes()->where('distribuidora_id', $this->ramirez()->id)
            ->where('variante_id', $renglon['variante_id'])->firstOrFail();
        $antes = (int) $stock->cantidad_disponible;

        Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])->call('agregar', $renglon['clave'])
            ->set('metodo_pago', $metodo)->call('cobrar')->assertSet('errorMsg', '');

        return [VentaDirecta::withoutGlobalScopes()->latest('id')->firstOrFail(), $stock, $antes];
    }

    public function test_el_admin_cancela_y_regresa_stock_dinero_y_queda_en_bitacora(): void
    {
        [$venta, $stock, $antes] = $this->vender();
        $this->assertSame($antes - 2, (int) $stock->fresh()->cantidad_disponible);

        Livewire::test('ventas.index')
            ->call('abrirCancelar', $venta->id)
            ->set('motivo', 'El cliente se arrepintió')
            ->call('cancelar')
            ->assertSet('errorMsg', '')
            ->assertSee('cancelada');

        $venta->refresh();
        $this->assertSame('anulada', $venta->estado);
        $this->assertNotNull($venta->anulada_at);
        $this->assertSame($antes, (int) $stock->fresh()->cantidad_disponible);

        $this->assertDatabaseHas('movimientos_stock', ['stock_local_id' => $stock->id, 'tipo' => 'devolucion', 'cantidad' => 2]);

        $reembolso = Pago::withoutGlobalScopes()->where('venta_directa_id', $venta->id)->where('tipo', 'reembolso_venta')->firstOrFail();
        $this->assertSame('salida', $reembolso->direccion);
        $this->assertSame('efectivo', $reembolso->metodo);
        $this->assertEquals((float) $venta->total, (float) $reembolso->monto);

        $this->assertTrue(Auditoria::where('accion', 'venta_mostrador.anulada')->where('entidad_id', $venta->id)->exists());

        // No se puede cancelar dos veces ni devolver dos veces el stock.
        $this->comoApi(self::ADMIN);
        $this->postJson("/api/ventas-directas/{$venta->id}/anular", ['motivo' => 'Otra vez'])->assertStatus(409);
        $this->assertSame($antes, (int) $stock->fresh()->cantidad_disponible);
    }

    public function test_la_venta_cancelada_no_suma_en_el_reporte(): void
    {
        [$venta] = $this->vender();
        $resumen = fn () => Tenant::forzar($this->ramirez()->id, fn () => app(ResumenOperativoAction::class)->ejecutar());
        $antes = $resumen()['ventas_directas'];

        $this->comoApi(self::ADMIN);
        $this->postJson("/api/ventas-directas/{$venta->id}/anular", ['motivo' => 'Cobro duplicado'])->assertOk()
            ->assertJsonPath('data.estado', 'anulada');

        $despues = $resumen()['ventas_directas'];
        $this->assertSame($antes['total'] - 1, $despues['total']);
        $this->assertSame(1, $despues['anuladas']);
    }

    public function test_el_empleado_sin_permiso_no_puede_cancelar_y_con_permiso_si(): void
    {
        [$venta, $stock, $antes] = $this->vender(self::EMPLEADO);

        Livewire::test('ventas.index')->assertDontSeeHtml('data-cancelar-venta="'.$venta->id.'"')
            ->call('abrirCancelar', $venta->id)->assertSet('cancelandoId', null);

        $this->comoApi(self::EMPLEADO);
        $this->postJson("/api/ventas-directas/{$venta->id}/anular", ['motivo' => 'Sin permiso'])->assertForbidden();
        $this->assertSame('completada', $venta->fresh()->estado);

        $this->staffDe(self::EMPLEADO)->forceFill(['permisos_finos' => [PermisoModuloService::VENTAS_CANCELAR => true]])->save();
        $this->postJson("/api/ventas-directas/{$venta->id}/anular", ['motivo' => 'Con permiso'])->assertOk();
        $this->assertSame($antes, (int) $stock->fresh()->cantidad_disponible);
    }

    public function test_otra_distribuidora_no_puede_cancelar_la_venta(): void
    {
        [$venta] = $this->vender();

        $this->comoApi(self::OTRA_ADMIN);
        $this->postJson("/api/ventas-directas/{$venta->id}/anular", ['motivo' => 'Ajena total'])->assertNotFound();
        $this->assertSame('completada', $venta->fresh()->estado);
        $this->assertSame(0, MovimientoStock::withoutGlobalScopes()->where('tipo', 'devolucion')->count());
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        [$venta] = $this->vender();

        Livewire::test('ventas.index')->call('abrirCancelar', $venta->id)->set('motivo', 'no')->call('cancelar')
            ->assertHasErrors('motivo');
        $this->assertSame('completada', $venta->fresh()->estado);
    }
}
