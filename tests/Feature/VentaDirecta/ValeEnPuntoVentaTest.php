<?php

namespace Tests\Feature\VentaDirecta;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\StockLocal;
use App\Models\Usuario;
use App\Models\Vale;
use App\Models\ValeMovimiento;
use App\Models\VentaDirecta;
use App\Services\Vale\AplicarValeAction;
use App\Services\VentaDirecta\RegistrarVentaDirectaService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S5-73: ninguna venta local descuenta saldo de vale.
 *
 * Pantalla, POST /api/ventas-directas, RegistrarVentaDirectaService y
 * POST /api/vales/{id}/aplicar con venta_directa_id rechazan el folio.
 * El canje en pedidos no entra en estas pruebas.
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

    public function test_la_pantalla_no_admite_vale(): void
    {
        $this->assertSame('America/Mexico_City', config('app.timezone'));
        [$renglon] = $this->renglon();

        $html = $this->get(route('punto-venta.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Vale (opcional)', $html);
        $this->assertStringNotContainsString('Folio del vale', $html);
        $this->assertStringNotContainsString('wire:click="aplicarVale"', $html);
        $this->assertStringNotContainsString('wire:click="quitarVale"', $html);
        $this->assertStringNotContainsString('Registrar venta pagada con vale', $html);

        $pantalla = Livewire::test('punto-venta.index')->call('agregar', $renglon['clave']);
        $pantalla->assertDontSee('Vale (opcional)')
            ->assertDontSee('Aplicar')
            ->assertDontSee('Quitar vale');

        foreach (['aplicarVale', 'quitarVale'] as $metodo) {
            try {
                Livewire::test('punto-venta.index')->call($metodo);
                $this->fail('La pantalla todavía expone '.$metodo.'.');
            } catch (\Throwable $e) {
                $this->assertStringContainsString($metodo, $e->getMessage());
            }
        }

        try {
            Livewire::test('punto-venta.index')->set('valeFolio', 'VAL-INYECTADO');
            $this->fail('La pantalla todavía acepta valeFolio.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('valeFolio', $e->getMessage());
        }
    }

    public function test_cobrar_no_descuenta_el_vale_aunque_exista_el_folio(): void
    {
        [$renglon] = $this->renglon();
        $vale = $this->vale($renglon['precio'] + 100);
        $saldo = (float) $vale->saldo_actual;
        $movimientos = ValeMovimiento::where('vale_id', $vale->id)->count();

        Livewire::test('punto-venta.index')
            ->call('agregar', $renglon['clave'])
            ->call('cobrar')
            ->assertSet('errorMsg', '')
            ->assertDontSee('Pagado con vale');

        $venta = VentaDirecta::latest('id')->firstOrFail();
        $pago = Pago::where('venta_directa_id', $venta->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $venta->total, (float) $pago->monto, 0.001);
        $this->assertEqualsWithDelta($saldo, (float) $vale->fresh()->saldo_actual, 0.001);
        $this->assertSame('activo', $vale->fresh()->estado);
        $this->assertSame($movimientos, ValeMovimiento::where('vale_id', $vale->id)->count());
        $this->assertSame(0, ValeMovimiento::where('venta_directa_id', $venta->id)->count());

        $this->get(route('ventas-directas.comprobante', $venta->id))
            ->assertOk()
            ->assertDontSee('Pagado con vale')
            ->assertDontSee($vale->folio);
    }

    public function test_ningun_canal_de_venta_local_descuenta_legacy_anticipo_devolucion_ni_sobrante(): void
    {
        $this->assertSame('America/Mexico_City', config('app.timezone'));
        [$renglon, $stock] = $this->renglon();
        Sanctum::actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());

        $ventaId = (int) $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'efectivo',
            'lineas' => [['variante_id' => $renglon['variante_id'], 'producto_campana_id' => $renglon['producto_campana_id'], 'cantidad' => 1]],
        ])->assertCreated()->assertJsonPath('data.vale', null)->json('data.id');
        $stockTrasVenta = (int) $stock->fresh()->cantidad_disponible;
        $ventas = VentaDirecta::count();
        $linea = ['variante_id' => $renglon['variante_id'], 'producto_campana_id' => $renglon['producto_campana_id'], 'cantidad' => 1];

        foreach ([
            'legacy' => null,
            'anticipo' => Vale::TIPO_ANTICIPO,
            'devolucion' => Vale::TIPO_DEVOLUCION,
            'sobrante' => Vale::TIPO_SOBRANTE,
        ] as $nombre => $tipo) {
            $vale = $this->vale(80, $tipo === null ? [] : ['tipo' => $tipo]);
            $saldo = (float) $vale->saldo_actual;
            $movimientos = ValeMovimiento::where('vale_id', $vale->id)->count();

            $api = $this->postJson('/api/ventas-directas', [
                'metodo_pago' => 'efectivo',
                'vale_folio' => $vale->folio,
                'lineas' => [$linea],
            ])->assertStatus(422);
            $this->assertStringContainsString('no aplica en Venta local', (string) $api->json('message'), $nombre);

            try {
                app(RegistrarVentaDirectaService::class)->registrar([$linea], 'efectivo', null, $vale->folio);
                $this->fail($nombre.' se registró con folio.');
            } catch (\Throwable $e) {
                $this->assertStringContainsString('no aplica en Venta local', $e->getMessage(), $nombre);
            }

            $aplicar = $this->postJson("/api/vales/{$vale->id}/aplicar", [
                'monto' => 10,
                'venta_directa_id' => $ventaId,
            ])->assertStatus(422);
            $this->assertStringContainsString('Venta local', (string) $aplicar->json('message'), $nombre);

            try {
                app(AplicarValeAction::class)->ejecutar($vale->fresh(), ['venta_directa_id' => $ventaId, 'monto' => 10]);
                $this->fail($nombre.' se aplicó a la venta.');
            } catch (\Throwable $e) {
                $this->assertStringContainsString('Venta local', $e->getMessage(), $nombre);
            }

            $this->assertEqualsWithDelta($saldo, (float) $vale->fresh()->saldo_actual, 0.001, $nombre);
            $this->assertSame($movimientos, ValeMovimiento::where('vale_id', $vale->id)->count(), $nombre);
            $this->assertSame('activo', $vale->fresh()->estado, $nombre);
        }

        $this->assertSame($ventas, VentaDirecta::count());
        $this->assertSame($stockTrasVenta, (int) $stock->fresh()->cantidad_disponible);
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
}
