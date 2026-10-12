<?php

namespace Tests\Feature\VentaDirecta;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\RevendedorDistribuidora;
use App\Models\StockLocal;
use App\Models\Usuario;
use App\Models\Vale;
use App\Models\ValeMovimiento;
use App\Models\VentaDirecta;
use App\Services\VentaDirecta\DatosComprobanteVentaAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-250 (R8, E12-03) — Aplicar un vale por folio en el punto de venta.
 *
 * - Si la compra cuesta más, se cobra la diferencia.
 * - Si cuesta menos, el saldo restante se queda en el mismo vale.
 * - Un vale vencido no se aplica.
 * - Solo vales de clientes directos; sin cliente elegido, la venta queda a
 *   nombre del dueño del vale.
 */
class ValeEnPuntoVentaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
    }

    /** @return array{0: array, 1: StockLocal} */
    private function renglon(): array
    {
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $this->assertNotNull($renglon);

        return [$renglon, StockLocal::withoutGlobalScopes()->where('variante_id', $renglon['variante_id'])->firstOrFail()];
    }

    private function cliente(int $n = 0): ClienteDirecto
    {
        return ClienteDirecto::query()->orderBy('id')->skip($n)->firstOrFail();
    }

    private function vale(float $saldo, array $extra = []): Vale
    {
        return Vale::create(array_merge([
            'distribuidora_id' => Tenant::id(),
            'cliente_directo_id' => $this->cliente()->id,
            'folio' => 'VAL-PV-'.uniqid(),
            'monto_original' => $saldo,
            'saldo_actual' => $saldo,
            'fecha_emision' => now(),
            'fecha_vencimiento' => now()->addMonth(),
            'estado' => 'activo',
            'creado_por_staff_id' => DistribuidoraStaff::query()->value('id'),
        ], $extra));
    }

    private function cobrarConVale(array $renglon, string $folio, int $piezas = 1)
    {
        $c = Livewire::test('punto-venta.index');
        for ($i = 0; $i < $piezas; $i++) {
            $c->call('agregar', $renglon['clave']);
        }

        return $c->set('valeFolio', $folio)->call('aplicarVale')->assertSet('errorMsg', '')->call('cobrar');
    }

    public function test_vale_menor_al_total_cobra_la_diferencia_y_agota_el_vale(): void
    {
        [$renglon] = $this->renglon();
        $vale = $this->vale(round($renglon['precio'] / 2, 2));

        $this->cobrarConVale($renglon, $vale->folio)->assertSet('errorMsg', '');

        $venta = VentaDirecta::latest('id')->firstOrFail();
        $pago = Pago::where('venta_directa_id', $venta->id)->firstOrFail();

        $this->assertEqualsWithDelta((float) $venta->total - (float) $vale->monto_original, (float) $pago->monto, 0.001);
        $this->assertSame('agotado', $vale->fresh()->estado);
        $this->assertSame((int) $vale->cliente_directo_id, (int) $venta->cliente_directo_id);
        $this->assertSame(1, ValeMovimiento::where('venta_directa_id', $venta->id)->where('tipo', 'aplicacion')->count());
    }

    public function test_vale_mayor_al_total_no_crea_pago_y_conserva_el_saldo(): void
    {
        [$renglon] = $this->renglon();
        $vale = $this->vale($renglon['precio'] + 100);

        $this->cobrarConVale($renglon, mb_strtolower($vale->folio))->assertSet('errorMsg', '');

        $venta = VentaDirecta::latest('id')->firstOrFail();
        $this->assertSame(0, Pago::where('venta_directa_id', $venta->id)->count());
        $this->assertEqualsWithDelta(100, (float) $vale->fresh()->saldo_actual, 0.001);
        $this->assertSame('activo', $vale->fresh()->estado);
    }

    public function test_un_vale_vencido_no_se_aplica(): void
    {
        $vale = $this->vale(100, ['fecha_vencimiento' => now()->subDay()]);

        Livewire::test('punto-venta.index')
            ->set('valeFolio', $vale->folio)
            ->call('aplicarVale')
            ->assertSet('vale', null)
            ->assertSet('errorMsg', 'El vale '.$vale->folio.' está vencido.');
    }

    public function test_rechaza_vale_agotado_de_mayorista_o_inexistente(): void
    {
        $agotado = $this->vale(0, ['estado' => 'agotado']);
        $afiliacion = RevendedorDistribuidora::query()->first();
        $this->assertNotNull($afiliacion, 'El seeder no dejó clientes mayoristas.');
        $mayorista = $this->vale(100, ['cliente_directo_id' => null, 'revendedor_distribuidora_id' => $afiliacion->id]);

        Livewire::test('punto-venta.index')->set('valeFolio', $agotado->folio)->call('aplicarVale')
            ->assertSet('errorMsg', 'El vale '.$agotado->folio.' ya no tiene saldo.');
        Livewire::test('punto-venta.index')->set('valeFolio', $mayorista->folio)->call('aplicarVale')
            ->assertSet('errorMsg', 'El vale '.$mayorista->folio.' es de un cliente mayorista; en el punto de venta solo se aceptan vales de clientes directos.');
        Livewire::test('punto-venta.index')->set('valeFolio', 'NO-EXISTE')->call('aplicarVale')
            ->assertSet('errorMsg', 'No existe un vale con el folio NO-EXISTE.');
    }

    public function test_vale_de_otra_distribuidora_no_se_encuentra(): void
    {
        $staffAjeno = DistribuidoraStaff::withoutGlobalScopes()->where('distribuidora_id', '!=', Tenant::id())->first();
        $this->assertNotNull($staffAjeno, 'El seeder no dejó personal de otra distribuidora.');
        Vale::withoutGlobalScopes()->insert([
            'distribuidora_id' => $staffAjeno->distribuidora_id, 'cliente_directo_id' => null, 'folio' => 'VAL-AJENO',
            'monto_original' => 100, 'saldo_actual' => 100, 'fecha_emision' => now(),
            'fecha_vencimiento' => now()->addMonth(), 'estado' => 'activo', 'creado_por_staff_id' => $staffAjeno->id,
        ]);

        Livewire::test('punto-venta.index')->set('valeFolio', 'VAL-AJENO')->call('aplicarVale')
            ->assertSet('errorMsg', 'No existe un vale con el folio VAL-AJENO.');
    }

    public function test_vale_de_otro_cliente_se_rechaza_sin_crear_venta_ni_tocar_stock(): void
    {
        [$renglon, $stock] = $this->renglon();
        $antes = (int) $stock->cantidad_disponible;
        $vale = $this->vale(100);
        Sanctum::actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());
        $ventas = VentaDirecta::count();

        $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'efectivo',
            'cliente_directo_id' => $this->cliente(1)->id,
            'vale_folio' => $vale->folio,
            'lineas' => [['variante_id' => $renglon['variante_id'], 'producto_campana_id' => $renglon['producto_campana_id'], 'cantidad' => 1]],
        ])->assertStatus(422)->assertJsonPath('message', 'El vale '.$vale->folio.' es de otro cliente. Elige a su dueño o quita el vale.');

        $this->assertSame($ventas, VentaDirecta::count());
        $this->assertSame($antes, (int) $stock->fresh()->cantidad_disponible);
        $this->assertEqualsWithDelta(100, (float) $vale->fresh()->saldo_actual, 0.001);
    }

    public function test_si_falla_el_stock_tampoco_se_descuenta_el_vale(): void
    {
        [$renglon, $stock] = $this->renglon();
        $vale = $this->vale(100);
        Sanctum::actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());

        $respuesta = $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'efectivo',
            'vale_folio' => $vale->folio,
            'lineas' => [['variante_id' => $renglon['variante_id'], 'producto_campana_id' => $renglon['producto_campana_id'], 'cantidad' => (int) $stock->cantidad_disponible + 5]],
        ]);

        $this->assertGreaterThanOrEqual(400, $respuesta->getStatusCode());

        $this->assertEqualsWithDelta(100, (float) $vale->fresh()->saldo_actual, 0.001);
        $this->assertSame(0, ValeMovimiento::where('vale_id', $vale->id)->count());
    }

    public function test_api_con_vale_devuelve_vale_y_por_cobrar(): void
    {
        [$renglon] = $this->renglon();
        $vale = $this->vale(50);
        Sanctum::actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());

        $r = $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'tarjeta',
            'vale_folio' => $vale->folio,
            'lineas' => [['variante_id' => $renglon['variante_id'], 'producto_campana_id' => $renglon['producto_campana_id'], 'cantidad' => 1]],
        ])->assertCreated();

        $total = (float) $r->json('data.total');
        $r->assertJsonPath('data.vale.folio', $vale->folio)
            ->assertJsonPath('data.cliente_directo_id', (int) $vale->cliente_directo_id);
        $this->assertEqualsWithDelta(50, $r->json('data.vale.monto_aplicado'), 0.001);
        $this->assertEqualsWithDelta($total - 50, $r->json('data.por_cobrar'), 0.001);
        $this->assertEqualsWithDelta($total - 50, $r->json('data.pago.monto'), 0.001);
    }

    public function test_api_sin_vale_sigue_igual(): void
    {
        [$renglon] = $this->renglon();
        Sanctum::actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());

        $r = $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'efectivo',
            'lineas' => [['variante_id' => $renglon['variante_id'], 'producto_campana_id' => $renglon['producto_campana_id'], 'cantidad' => 1]],
        ])->assertCreated()->assertJsonPath('data.vale', null);

        $this->assertEqualsWithDelta($r->json('data.total'), $r->json('data.pago.monto'), 0.001);
    }

    public function test_el_comprobante_muestra_el_vale(): void
    {
        [$renglon] = $this->renglon();
        $vale = $this->vale($renglon['precio'] + 10);

        $this->cobrarConVale($renglon, $vale->folio)->assertSet('errorMsg', '');
        $venta = VentaDirecta::latest('id')->firstOrFail();

        $datos = app(DatosComprobanteVentaAction::class)->ejecutar($venta);
        $this->assertSame($vale->folio, $datos['vale']['folio']);
        $this->assertEqualsWithDelta(10, $datos['vale']['saldo_restante'], 0.001);
        $this->assertNull($datos['pago']);

        $this->get(route('ventas-directas.comprobante', $venta->id))->assertOk()->assertSee($vale->folio)->assertSee('Pagado con vale');
    }

    public function test_quitar_vale_vuelve_a_cobrar_el_total(): void
    {
        [$renglon] = $this->renglon();
        $vale = $this->vale(10);

        $c = Livewire::test('punto-venta.index')->call('agregar', $renglon['clave'])
            ->set('valeFolio', $vale->folio)->call('aplicarVale');
        $this->assertEqualsWithDelta($renglon['precio'] - 10, $c->instance()->porCobrar, 0.001);

        $c->call('quitarVale')->assertSet('vale', null);
        $this->assertEqualsWithDelta($renglon['precio'], $c->instance()->porCobrar, 0.001);
    }
}
