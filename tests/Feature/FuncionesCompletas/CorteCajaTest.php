<?php

namespace Tests\Feature\FuncionesCompletas;

use App\Models\CajaSesion;
use App\Models\Pago;
use App\Models\VentaDirecta;
use App\Services\Auth\PermisoModuloService;
use App\Services\Caja\CajaService;
use App\Services\VentaDirecta\AnularVentaDirectaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** Corte de caja: apertura, cobros ligados, retiros, cancelaciones y la cuenta final. */
class CorteCajaTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    private function vender(string $metodo, int $piezas = 1): VentaDirecta
    {
        $pv = Livewire::test('punto-venta.index');
        $renglon = $pv->instance()->disponibles->first(fn ($r) => $r['disponible'] >= $piezas);
        for ($i = 0; $i < $piezas; $i++) {
            $pv->call('agregar', $renglon['clave']);
        }
        $pv->set('metodo_pago', $metodo)->call('cobrar')->assertSet('errorMsg', '');

        return VentaDirecta::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    public function test_el_corte_cuadra_fondo_cobros_retiros_y_cancelaciones_por_metodo(): void
    {
        $this->comoPanel(self::ADMIN);
        $cajas = app(CajaService::class);
        $caja = $cajas->abrir(500);

        $v1 = $this->vender('efectivo');
        $v2 = $this->vender('tarjeta');
        $v3 = $this->vender('efectivo');
        $cajas->registrarMovimiento('retiro', 100, 'Depósito al banco');
        $cajas->registrarMovimiento('ingreso', 50, 'Cambio');
        app(AnularVentaDirectaAction::class)->ejecutar($v3->id, 'Se arrepintió');

        $this->assertSame(3, Pago::where('caja_sesion_id', $caja->id)->where('direccion', 'entrada')->count());

        $r = $cajas->resumen($caja->fresh());
        $efectivoEsperado = round(500 + (float) $v1->total + (float) $v3->total - (float) $v3->total - 100 + 50, 2);
        $this->assertEquals($efectivoEsperado, $r['metodos']['efectivo']['esperado']);
        $this->assertEquals((float) $v2->total, $r['metodos']['tarjeta']['esperado']);
        $this->assertEquals(0.0, $r['metodos']['transferencia']['esperado']);
        $this->assertSame(1, $r['canceladas']);

        // Faltan 20 pesos de efectivo.
        $cerrada = $cajas->cerrar(['efectivo' => $efectivoEsperado - 20, 'tarjeta' => (float) $v2->total]);
        $this->assertSame('cerrada', $cerrada->estado);
        $this->assertEquals(-20.0, (float) $cerrada->diferencia);
        $this->assertEquals($efectivoEsperado, $cerrada->esperado['efectivo']);

        // Lo que se cobre después ya no entra a esa caja.
        $this->vender('efectivo');
        $this->assertNull(Pago::withoutGlobalScopes()->latest('id')->first()->caja_sesion_id);

        $this->get(route('caja.pdf', $caja->id))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_no_se_retira_mas_efectivo_del_que_hay_ni_montos_negativos(): void
    {
        $this->comoPanel(self::ADMIN);
        Livewire::test('caja.index')->set('fondo', '-5')->call('abrir')->assertHasErrors('fondo');
        Livewire::test('caja.index')->set('fondo', '200')->call('abrir')->assertSee('Caja abierta');

        Livewire::test('caja.index')->set('movTipo', 'retiro')->set('movMonto', '250')->set('movMotivo', 'Demasiado')
            ->call('registrarMovimiento')->assertSet('errorMsg', fn ($m) => str_contains($m, 'No puedes retirar'));

        Livewire::test('caja.index')->set('contado.efectivo', '-1')->call('cerrar')->assertHasErrors('contado.efectivo');
        $this->assertSame(1, CajaSesion::where('estado', 'abierta')->count());
    }

    public function test_una_sola_caja_abierta_por_persona(): void
    {
        $this->comoPanel(self::EMPLEADO);
        app(CajaService::class)->abrir(0);
        Livewire::test('caja.index')->call('abrir')->assertSee('Caja abierta');
        $this->expectExceptionMessage('Ya tienes una caja abierta');
        app(CajaService::class)->abrir(0);
    }

    public function test_sin_permiso_de_caja_no_entra_y_otra_distribuidora_no_ve_el_corte(): void
    {
        $this->comoPanel(self::ADMIN);
        $caja = app(CajaService::class)->abrir(10);

        $this->staffDe(self::EMPLEADO)->forceFill(['permisos_finos' => [PermisoModuloService::CAJA_OPERAR => false]])->save();
        $this->comoPanel(self::EMPLEADO);
        $this->get(route('caja.index'))->assertForbidden();

        $this->comoPanel(self::OTRA_ADMIN);
        $this->get(route('caja.pdf', $caja->id))->assertNotFound();
    }
}
