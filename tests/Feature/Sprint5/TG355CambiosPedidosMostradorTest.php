<?php

namespace Tests\Feature\Sprint5;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\MovimientoStock;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProductoCampana;
use App\Models\SolicitudCambio;
use App\Models\SolicitudCambioHistorial;
use App\Models\StockLocal;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Vale;
use App\Models\ValeMovimiento;
use App\Models\Variante;
use App\Models\VentaDirecta;
use App\Models\VentaDirectaDetalle;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** TG-355 (G32) — Cambios: espacios Pedidos y Mostrador separados; partidas informativas. */
class TG355CambiosPedidosMostradorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->como('admin@calzadosramirez.test');
    }

    private function como(string $email): void
    {
        $this->actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function pedido(string $estado, string $folio): Pedido
    {
        $pedido = Pedido::create([
            'distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::value('id'),
            'folio' => $folio, 'tipo' => 'cliente_directo', 'cliente_directo_id' => ClienteDirecto::value('id'),
            'estado' => $estado, 'subtotal' => 900, 'total' => 900,
            'fecha_entrega' => $estado === 'entregado' ? now()->subDay() : null,
            'capturado_por_staff_id' => DistribuidoraStaff::value('id'),
        ]);
        $pc = ProductoCampana::query()->firstOrFail();
        foreach ([['Bota TG355', 'BT-355', '25', 'Café', 2], ['Tenis TG355', 'TN-355', '27', 'Negro', 1]] as [$n, $m, $t, $c, $q]) {
            PedidoDetalle::query()->forceCreate([
                'distribuidora_id' => Tenant::id(), 'pedido_id' => $pedido->id, 'producto_campana_id' => $pc->id,
                'variante_id' => Variante::query()->where('producto_id', $pc->producto_id)->value('id') ?? Variante::query()->value('id'),
                'producto_nombre' => $n, 'modelo' => $m, 'talla' => $t, 'color' => $c,
                'cantidad' => $q, 'precio_unitario' => 300, 'subtotal' => 300 * $q,
            ]);
        }

        return $pedido;
    }

    private function venta(string $folio = 'VD-TG355-0001'): VentaDirecta
    {
        return VentaDirecta::create([
            'distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::value('id'),
            'folio' => $folio, 'cliente_directo_id' => ClienteDirecto::value('id'),
            'subtotal' => 0, 'total' => 0, 'registrada_por_staff_id' => DistribuidoraStaff::value('id'),
        ]);
    }

    /** Solicitudes sintéticas, una por origen, creadas directo en BD (sin SolicitarCambioAction). */
    private function solicitudes(): array
    {
        $linea = $this->pedido('entregado', 'PED-TG355-SOL')->detalle()->firstOrFail();
        $venta = $this->venta('VD-TG355-SOL');
        $vd = VentaDirectaDetalle::query()->forceCreate([
            'venta_directa_id' => $venta->id, 'stock_local_id' => StockLocal::query()->value('id'), 'producto_campana_id' => $linea->producto_campana_id,
            'variante_id' => $linea->variante_id, 'producto_nombre' => 'Sandalia Mostrador TG355', 'modelo' => 'SM-355',
            'talla' => '24', 'color' => 'Rojo', 'cantidad' => 1, 'precio_unitario' => 100, 'subtotal' => 100,
        ] + (in_array('distribuidora_id', (new VentaDirectaDetalle)->getFillable(), true) ? ['distribuidora_id' => Tenant::id()] : []));
        $base = ['distribuidora_id' => Tenant::id(), 'cliente_directo_id' => ClienteDirecto::value('id'),
            'estado' => 'solicitada', 'fecha_solicitud' => now(), 'fecha_entrega_original' => now()->subDay(), 'fecha_limite_solicitud' => now()->addDays(5), 'dias_solicitud_aplicados' => 8,
            'dias_gestion_fabrica_aplicados' => 30, 'fecha_limite_gestion_fabrica' => now()->addDays(30)];
        $sp = SolicitudCambio::query()->forceCreate($base + ['pedido_detalle_id' => $linea->id, 'motivo' => 'Motivo pedido TG355']);
        $sv = SolicitudCambio::query()->forceCreate($base + ['venta_detalle_id' => $vd->id, 'motivo' => 'Motivo mostrador TG355']);

        return [$sp, $sv];
    }

    private function datos(): array
    {
        $modelos = [Pedido::class, PedidoDetalle::class, VentaDirecta::class, SolicitudCambio::class,
            SolicitudCambioHistorial::class, Vale::class, ValeMovimiento::class, Pago::class,
            StockLocal::class, MovimientoStock::class];
        $datos = [];
        foreach ($modelos as $modelo) {
            $tabla = (new $modelo)->getTable();
            $datos[$tabla] = DB::table($tabla)->get()->map(fn ($fila) => json_encode($fila))->sort()->values()->all();
        }

        return $datos;
    }

    public function test_pedido_entregado_muestra_partidas_historicas_informativas(): void
    {
        $pedido = $this->pedido('entregado', 'PED-TG355-0001');
        $ids = $pedido->detalle()->orderBy('id')->pluck('id')->all();
        $pantalla = Livewire::test('cambios.index')->assertSet('espacio', 'pedidos')
            ->set('folioPedido', $pedido->folio)->call('buscarPedido')
            ->assertSee('data-partidas-pedido="'.$pedido->id.'"', false)
            ->assertSee('Partidas del pedido entregado.');
        $this->assertSame($ids, $pantalla->instance()->partidasPedido->pluck('id')->all());
        $html = $pantalla->html();
        $tabla = substr($html, strpos($html, 'data-partidas-pedido'));
        $pos = 0;
        foreach (['Bota TG355', 'BT-355', '25', 'Café', '2', 'Tenis TG355', 'TN-355', '27', 'Negro', '1'] as $txt) {
            $nuevo = strpos($tabla, $txt, $pos);
            $this->assertNotFalse($nuevo, $txt);
            $pos = $nuevo;
        }
        $this->assertStringNotContainsString('$300', $html);
        $this->assertStringNotContainsString('abrirCambio', substr($html, strpos($html, 'data-partidas-pedido'), 3000));
    }

    public function test_pedido_no_entregado_solo_muestra_estado(): void
    {
        $pedido = $this->pedido('listo_entrega', 'PED-TG355-0002');
        $pantalla = Livewire::test('cambios.index')->set('folioPedido', $pedido->folio)->call('buscarPedido')
            ->assertSet('pedidoEncontradoId', $pedido->id)
            ->assertSee('Las partidas se muestran cuando el pedido está entregado.')
            ->assertDontSee('Bota TG355')->assertDontSee('data-partidas-pedido', false);
        $this->assertCount(0, $pantalla->instance()->partidasPedido);
    }

    public function test_pedido_entregado_ajeno_no_expone_partidas(): void
    {
        $pedido = $this->pedido('entregado', 'PED-TG355-0003');
        $this->como('admin@boutiquedelcalzado.test');
        Livewire::test('cambios.index')->set('folioPedido', $pedido->folio)->call('buscarPedido')
            ->assertSet('pedidoEncontradoId', null)
            ->assertSet('errorBusquedaPedido', 'No se encontró un pedido con ese folio.')
            ->assertDontSee('Bota TG355');
    }

    public function test_listado_se_separa_por_origen_al_alternar_y_conserva_busquedas(): void
    {
        [$sp, $sv] = $this->solicitudes();
        $venta = $this->venta();
        $pedido = $this->pedido('entregado', 'PED-TG355-0004');
        $pantalla = Livewire::test('cambios.index');
        $this->assertSame([$sp->id], $pantalla->instance()->solicitudes->pluck('id')->all());
        $pantalla->assertSee('Motivo pedido TG355')->assertDontSee('Motivo mostrador TG355')
            ->set('folioPedido', $pedido->folio)->call('buscarPedido')
            ->call('cambiarEspacio', 'mostrador')->assertSet('espacio', 'mostrador')
            ->assertSee('Motivo mostrador TG355')->assertDontSee('Motivo pedido TG355')
            ->set('folioVenta', $venta->folio)->call('buscarVenta')->assertSet('ventaId', $venta->id)
            ->assertSet('pedidoEncontradoId', $pedido->id);
        $this->assertSame([$sv->id], $pantalla->instance()->solicitudes->pluck('id')->all());
        $pantalla->call('cambiarEspacio', 'pedidos')->assertSet('pedidoEncontradoId', $pedido->id)
            ->assertSet('ventaId', $venta->id)->assertSet('folioVenta', $venta->folio);
        $this->assertSame([$sp->id], $pantalla->instance()->solicitudes->pluck('id')->all());
    }

    public function test_espacio_invalido_se_rechaza_o_vuelve_a_pedidos(): void
    {
        Livewire::test('cambios.index')->call('cambiarEspacio', 'otro')->assertStatus(422);
        Livewire::test('cambios.index')->set('espacio', 'otro')->assertSet('espacio', 'pedidos');
    }

    public function test_alternar_y_consultar_no_modifica_datos_de_negocio(): void
    {
        $this->solicitudes();
        $pedido = $this->pedido('entregado', 'PED-TG355-0005');
        $antes = $this->datos();
        Livewire::test('cambios.index')->set('folioPedido', $pedido->folio)->call('buscarPedido')
            ->call('cambiarEspacio', 'mostrador')->set('folioVenta', 'VD-TG355-SOL')->call('buscarVenta')
            ->call('cambiarEspacio', 'pedidos')->set('estado', 'solicitada')
            ->set('folioPedido', 'NO-EXISTE')->call('buscarPedido');
        $this->assertSame($antes, $this->datos());
    }

    public function test_desde_pedidos_no_hay_accion_de_venta_ni_de_nota(): void
    {
        $pedido = $this->pedido('entregado', 'PED-TG355-0006');
        $html = Livewire::test('cambios.index')->set('folioPedido', $pedido->folio)->call('buscarPedido')->html();
        $panel = substr($html, strpos($html, 'data-espacio-panel="pedidos"'));
        $panel = substr($panel, 0, strpos($panel, 'data-espacio-panel="mostrador"'));
        foreach (['Solicitar cambio', 'Enviar solicitud', 'punto-venta', 'Emitir', 'vale'] as $txt) {
            $this->assertStringNotContainsString($txt, $panel);
        }
    }

    public function test_mostrador_sigue_accesible_e_integro(): void
    {
        $venta = $this->venta();
        $html = Livewire::test('cambios.index')->call('cambiarEspacio', 'mostrador')
            ->set('folioVenta', $venta->folio)->call('buscarVenta')->assertSet('ventaId', $venta->id)
            ->assertSee('Cambio de una venta de mostrador')->assertSee('Venta '.$venta->folio)->html();
        $this->assertStringContainsString('data-espacio="mostrador"', $html);
        $this->get(route('cambios.index'))->assertOk()->assertSee('Pedidos')->assertSee('Mostrador')
            ->assertSee('Cambio de una venta de mostrador');
    }
}
