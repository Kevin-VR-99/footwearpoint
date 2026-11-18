<?php

namespace Tests\Feature\Sprint5;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProductoCampana;
use App\Models\SolicitudCambio;
use App\Models\StockLocal;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Variante;
use App\Models\VentaDirecta;
use App\Models\VentaDirectaDetalle;
use App\Services\Cliente\AltaRapidaClienteDirecto;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-407 [G53] — Unificar cambios de mostrador con cambios de pedidos en un solo flujo en Cambios.
 */
class TG407CambiosUnificadosTest extends TestCase
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

    private function venta(string $folio = 'VD-TG407-0001', ?int $clienteId = null): VentaDirecta
    {
        $pc = ProductoCampana::query()->firstOrFail();
        $variante = Variante::query()->where('producto_id', $pc->producto_id)->first() ?? Variante::query()->firstOrFail();
        $stock = StockLocal::query()->firstOrFail();

        $venta = VentaDirecta::create([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::value('id'),
            'folio' => $folio,
            'cliente_directo_id' => $clienteId,
            'fecha_venta' => now()->subDay(),
            'subtotal' => 350,
            'total' => 350,
            'estado' => 'completada',
            'registrada_por_staff_id' => DistribuidoraStaff::value('id'),
        ]);

        VentaDirectaDetalle::query()->forceCreate([
            'venta_directa_id' => $venta->id,
            'distribuidora_id' => Tenant::id(),
            'stock_local_id' => $stock->id,
            'producto_campana_id' => $pc->id,
            'variante_id' => $variante->id,
            'producto_nombre' => 'Zapato Confort TG407',
            'modelo' => 'ZC-407',
            'talla' => '26',
            'color' => 'Miel',
            'cantidad' => 1,
            'precio_unitario' => 350,
            'subtotal' => 350,
        ]);

        return $venta;
    }

    private function pedido(string $folio = 'PED-TG407-0001'): Pedido
    {
        $cliente = ClienteDirecto::query()->firstOrFail();
        $pedido = Pedido::create([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::value('id'),
            'folio' => $folio,
            'tipo' => 'cliente_directo',
            'cliente_directo_id' => $cliente->id,
            'estado' => 'entregado',
            'fecha_entrega' => now()->subDay(),
            'subtotal' => 400,
            'total' => 400,
            'capturado_por_staff_id' => DistribuidoraStaff::value('id'),
        ]);

        $pc = ProductoCampana::query()->firstOrFail();
        PedidoDetalle::query()->forceCreate([
            'distribuidora_id' => Tenant::id(),
            'pedido_id' => $pedido->id,
            'producto_campana_id' => $pc->id,
            'variante_id' => Variante::query()->value('id'),
            'producto_nombre' => 'Bota Piel TG407',
            'modelo' => 'BP-407',
            'talla' => '25',
            'color' => 'Negro',
            'cantidad' => 1,
            'precio_unitario' => 400,
            'subtotal' => 400,
        ]);

        return $pedido;
    }

    public function test_flujo_unificado_no_tiene_pestanas_pero_muestra_ambos_paneles(): void
    {
        $html = $this->get(route('cambios.index'))->assertOk()->getContent();

        // La pestaña Mostrador desapareció del tablist
        $this->assertStringNotContainsString('role="tablist"', $html);

        // Pero la función de mostrador y pedidos se conservan juntas
        $this->assertStringContainsString('data-espacio-panel="pedidos"', $html);
        $this->assertStringContainsString('data-espacio-panel="mostrador"', $html);
        $this->assertStringContainsString('Buscar pedido por folio', $html);
        $this->assertStringContainsString('Cambio de una venta de mostrador', $html);
        $this->assertStringContainsString('Solicitudes de cambio', $html);
    }

    public function test_buscar_venta_por_folio_vd(): void
    {
        $cliente = ClienteDirecto::query()->firstOrFail();
        $venta = $this->venta('VD-TG407-0010', $cliente->id);

        Livewire::test('cambios.index')
            ->set('folioVenta', '  '.$venta->folio.'  ')
            ->call('buscarVenta')
            ->assertSet('ventaId', $venta->id)
            ->assertSet('errorMsg', '')
            ->assertSee('Venta '.$venta->folio)
            ->assertSee($cliente->nombre);
    }

    public function test_buscar_venta_por_telefono_del_cliente(): void
    {
        $cliente = ClienteDirecto::query()->whereNotNull('telefono')->firstOrFail();
        $venta = $this->venta('VD-TG407-0020', $cliente->id);

        // Búsqueda con teléfono formateado con espacios
        $telFormateado = substr($cliente->telefono, 0, 2).' '.substr($cliente->telefono, 2, 4).' '.substr($cliente->telefono, 6);

        Livewire::test('cambios.index')
            ->set('folioVenta', $telFormateado)
            ->call('buscarVenta')
            ->assertSet('ventaId', $venta->id)
            ->assertSet('errorMsg', '')
            ->assertSee('Venta '.$venta->folio)
            ->assertSee($cliente->nombre);
    }

    public function test_buscar_venta_por_telefono_inexistente_o_sin_ventas(): void
    {
        // Teléfono de 10 dígitos pero sin ningún cliente registrado
        Livewire::test('cambios.index')
            ->set('folioVenta', '9988776655')
            ->call('buscarVenta')
            ->assertSet('ventaId', null)
            ->assertSet('errorMsg', 'No se encontró ningún cliente con ese teléfono.');

        // Cliente registrado pero sin ninguna venta directa
        $clienteSinVentas = ClienteDirecto::create([
            'distribuidora_id' => Tenant::id(),
            'nombre' => 'Cliente Sin Ventas TG407',
            'telefono' => '9911223344',
            'estado' => 'activo',
        ]);

        Livewire::test('cambios.index')
            ->set('folioVenta', '9911223344')
            ->call('buscarVenta')
            ->assertSet('ventaId', null)
            ->assertSet('errorMsg', 'Ese cliente no tiene ventas registradas.');
    }

    public function test_venta_sin_cliente_muestra_aviso_y_permite_alta_rapida_para_solicitar_cambio(): void
    {
        $ventaSinCliente = $this->venta('VD-TG407-0030', null);
        $this->assertNull($ventaSinCliente->cliente_directo_id);

        $linea = $ventaSinCliente->detalle()->firstOrFail();

        // 1. Al buscar la venta, se detecta que no tiene cliente y se muestra el bloque de alta rápida
        $pantalla = Livewire::test('cambios.index')
            ->set('folioVenta', $ventaSinCliente->folio)
            ->call('buscarVenta')
            ->assertSet('ventaId', $ventaSinCliente->id)
            ->assertSee('Esta venta se registró sin cliente')
            ->assertSee('Sin cliente');

        // 2. Dar de alta rápidamente al cliente
        $pantalla->set('primeraCompra', 'si')
            ->set('altaNombre', 'Comprador Nuevo TG407')
            ->set('altaTelefono', '55 7766 5544')
            ->call('crearAltaRapida', app(AltaRapidaClienteDirecto::class))
            ->assertSet('errorMsg', '');

        // 3. La venta en BD se actualizó con el nuevo cliente
        $ventaSinCliente->refresh();
        $this->assertNotNull($ventaSinCliente->cliente_directo_id);
        $this->assertSame('Comprador Nuevo TG407', $ventaSinCliente->clienteDirecto->nombre);

        // 4. Con el cliente asignado, se puede tramitar el cambio sin error
        $pantalla->call('abrirCambio', $linea->id)
            ->set('cambioMotivo', 'La talla no le quedó al cliente')
            ->call('solicitarCambio')
            ->assertSet('errorMsg', '')
            ->assertSee('Solicitud de cambio registrada');

        $solicitud = SolicitudCambio::query()->where('venta_detalle_id', $linea->id)->first();
        $this->assertNotNull($solicitud);
        $this->assertSame($ventaSinCliente->cliente_directo_id, $solicitud->cliente_directo_id);
        $this->assertSame('La talla no le quedó al cliente', $solicitud->motivo);
    }

    public function test_venta_sin_cliente_permite_asignar_cliente_existente(): void
    {
        $existente = ClienteDirecto::query()->firstOrFail();
        $ventaSinCliente = $this->venta('VD-TG407-0040', null);

        $pantalla = Livewire::test('cambios.index')
            ->set('folioVenta', $ventaSinCliente->folio)
            ->call('buscarVenta')
            ->assertSet('ventaId', $ventaSinCliente->id)
            ->assertSee('Esta venta se registró sin cliente');

        // Seleccionar cliente existente
        $pantalla->set('altaClienteDirectoId', (string) $existente->id);

        $ventaSinCliente->refresh();
        $this->assertSame($existente->id, $ventaSinCliente->cliente_directo_id);
    }

    public function test_historial_conserva_solicitudes_anteriores_de_pedidos_y_mostrador(): void
    {
        $cliente = ClienteDirecto::query()->firstOrFail();
        $pedido = $this->pedido('PED-TG407-HIST');
        $venta = $this->venta('VD-TG407-HIST', $cliente->id);

        $pDetalle = $pedido->detalle()->firstOrFail();
        $vDetalle = $venta->detalle()->firstOrFail();

        $base = [
            'distribuidora_id' => Tenant::id(),
            'cliente_directo_id' => $cliente->id,
            'estado' => 'solicitada',
            'fecha_solicitud' => now(),
            'fecha_entrega_original' => now()->subDay(),
            'fecha_limite_solicitud' => now()->addDays(5),
            'dias_solicitud_aplicados' => 8,
            'dias_gestion_fabrica_aplicados' => 20,
            'fecha_limite_gestion_fabrica' => now()->addDays(20),
        ];

        $solPedido = SolicitudCambio::create($base + [
            'pedido_detalle_id' => $pDetalle->id,
            'motivo' => 'Motivo cambio pedido TG407',
        ]);

        $solVenta = SolicitudCambio::create($base + [
            'venta_detalle_id' => $vDetalle->id,
            'motivo' => 'Motivo cambio mostrador TG407',
        ]);

        // En el flujo unificado, ambas solicitudes son visibles sin alternar pestañas
        $pantalla = Livewire::test('cambios.index');
        $ids = $pantalla->instance()->solicitudes->pluck('id')->all();

        $this->assertContains($solPedido->id, $ids);
        $this->assertContains($solVenta->id, $ids);

        $pantalla->assertSee('Motivo cambio pedido TG407')
            ->assertSee('Motivo cambio mostrador TG407')
            ->assertSee('Pedido '.$pedido->folio)
            ->assertSee('Venta '.$venta->folio);
    }
}
