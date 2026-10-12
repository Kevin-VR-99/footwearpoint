<?php

namespace Tests\Feature\Cambios;

use App\Models\ClienteDirecto;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProductoCampana;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\SolicitudCambio;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Variante;
use App\Services\Cambio\SolicitarCambioAction;
use App\Services\VentaDirecta\RegistrarVentaDirectaService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-254 (R12, E12-01) — Solicitud de cambio de producto dentro del plazo.
 *
 * Plazo configurable por distribuidora (dias_solicitud_cambio), igual para
 * todos los clientes, en días naturales desde la entrega o la venta; el
 * último día cuenta completo. Una solicitud cubre la línea completa y solo
 * puede haber una abierta por línea.
 */
class SolicitarCambioTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->como(self::ADMIN);
        ConfiguracionDistribuidora::query()->update(['dias_solicitud_cambio' => 12, 'dias_gestion_devolucion' => 20]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function como(string|Usuario $usuario): Usuario
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $usuario = $usuario instanceof Usuario ? $usuario : Usuario::where('email', $usuario)->firstOrFail();
        $this->actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        return $usuario;
    }

    private function clienteConCuenta(int $n = 0): ClienteDirecto
    {
        $cliente = ClienteDirecto::query()->whereNotNull('usuario_id')->orderBy('id')->skip($n)->first();
        $this->assertNotNull($cliente, 'El seeder no dejó clientes con cuenta de la app.');

        return $cliente;
    }

    /** Otro cliente de la distribuidora (no necesita cuenta de la app). */
    private function otroCliente(ClienteDirecto $distinto): ClienteDirecto
    {
        return ClienteDirecto::query()->whereKeyNot($distinto->id)->orderBy('id')->firstOrFail();
    }

    private function mayoristaConCuenta(): RevendedorDistribuidora
    {
        $afiliacion = RevendedorDistribuidora::query()
            ->whereIn('revendedor_id', Revendedor::query()->whereNotNull('usuario_id')->select('id'))
            ->first();
        $this->assertNotNull($afiliacion, 'El seeder no dejó clientes mayoristas con cuenta.');

        return $afiliacion;
    }

    private function lineaEntregada(array $duenio, ?string $entrega = '2026-10-01 10:00:00', string $estado = 'entregado'): PedidoDetalle
    {
        $pedido = Pedido::create(array_merge([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'CAM-'.uniqid(),
            'estado' => $estado,
            'subtotal' => 900,
            'total' => 900,
            'fecha_colocacion' => '2026-09-01 10:00:00',
            'fecha_entrega' => $entrega,
            'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
        ], $duenio));

        $pc = ProductoCampana::query()->firstOrFail();

        return PedidoDetalle::query()->forceCreate([
            'distribuidora_id' => Tenant::id(), 'pedido_id' => $pedido->id,
            'producto_campana_id' => $pc->id,
            'variante_id' => Variante::query()->where('producto_id', $pc->producto_id)->value('id') ?? Variante::query()->value('id'),
            'producto_nombre' => 'Bota Cambio', 'modelo' => 'BC-1', 'talla' => '25', 'color' => 'Café',
            'cantidad' => 2, 'precio_unitario' => 450, 'subtotal' => 900,
        ]);
    }

    private function deCliente(ClienteDirecto $c): array
    {
        return ['tipo' => 'cliente_directo', 'cliente_directo_id' => $c->id];
    }

    private function solicitar(array $datos): SolicitudCambio
    {
        return app(SolicitarCambioAction::class)->ejecutar($datos);
    }

    public function test_el_cliente_pide_cambio_desde_la_app_dentro_del_plazo(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $cliente = $this->clienteConCuenta();
        $linea = $this->lineaEntregada($this->deCliente($cliente));

        Sanctum::actingAs($this->como(Usuario::findOrFail($cliente->usuario_id)));

        $this->postJson('/api/solicitudes-cambio', ['pedido_detalle_id' => $linea->id, 'motivo' => 'No me quedó la talla'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'solicitada')
            ->assertJsonPath('data.origen', 'pedido')
            ->assertJsonPath('data.propietario.tipo', 'cliente_directo')
            ->assertJsonPath('data.dias_solicitud_aplicados', 12);

        $s = SolicitudCambio::query()->firstOrFail();
        $this->assertSame('2026-10-13 23:59:59', $s->fecha_limite_solicitud->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-25', $s->fecha_limite_gestion_fabrica->toDateString());
        $this->assertSame((int) $cliente->id, (int) $s->cliente_directo_id);
    }

    public function test_el_ultimo_dia_cuenta_completo_y_despues_se_rechaza(): void
    {
        $cliente = $this->clienteConCuenta();
        $enPlazo = $this->lineaEntregada($this->deCliente($cliente));
        $tarde = $this->lineaEntregada($this->deCliente($cliente));

        Carbon::setTestNow('2026-10-13 23:50:00');
        $this->solicitar(['pedido_detalle_id' => $enPlazo->id, 'motivo' => 'Defecto']);

        Carbon::setTestNow('2026-10-14 00:00:01');
        $this->expectExceptionMessage('El plazo para pedir cambio terminó el 13/10/2026 (12 días desde la entrega).');
        $this->solicitar(['pedido_detalle_id' => $tarde->id, 'motivo' => 'Defecto']);
    }

    public function test_el_plazo_sale_de_la_configuracion_de_la_distribuidora(): void
    {
        ConfiguracionDistribuidora::query()->update(['dias_solicitud_cambio' => 30]);
        Carbon::setTestNow('2026-10-25 10:00:00');
        $linea = $this->lineaEntregada($this->deCliente($this->clienteConCuenta()));

        $s = $this->solicitar(['pedido_detalle_id' => $linea->id, 'motivo' => 'Color distinto']);

        $this->assertSame(30, (int) $s->dias_solicitud_aplicados);
        $this->assertSame('2026-10-31', $s->fecha_limite_solicitud->toDateString());
    }

    public function test_un_pedido_no_entregado_no_admite_cambio(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $linea = $this->lineaEntregada($this->deCliente($this->clienteConCuenta()), null, 'listo_entrega');

        $this->expectExceptionMessage('Solo se puede pedir cambio de un pedido ya entregado.');
        $this->solicitar(['pedido_detalle_id' => $linea->id, 'motivo' => 'x']);
    }

    public function test_un_cliente_no_puede_pedir_cambio_de_lo_ajeno(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $otro = $this->clienteConCuenta();
        $duenio = $this->otroCliente($otro);
        $linea = $this->lineaEntregada($this->deCliente($duenio));

        Sanctum::actingAs($this->como(Usuario::findOrFail($otro->usuario_id)));

        $this->postJson('/api/solicitudes-cambio', ['pedido_detalle_id' => $linea->id, 'motivo' => 'x'])
            ->assertNotFound()
            ->assertJsonPath('message', 'No se encontró ese producto.');
        $this->assertSame(0, SolicitudCambio::query()->count());
    }

    public function test_solo_una_solicitud_abierta_por_linea(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $linea = $this->lineaEntregada($this->deCliente($this->clienteConCuenta()));
        $primera = $this->solicitar(['pedido_detalle_id' => $linea->id, 'motivo' => 'Talla']);

        try {
            $this->solicitar(['pedido_detalle_id' => $linea->id, 'motivo' => 'Otra vez']);
            $this->fail('Debió rechazar la segunda solicitud.');
        } catch (\App\Exceptions\OperacionInvalidaException $e) {
            $this->assertSame('Ese producto ya tiene una solicitud de cambio en trámite.', $e->getMessage());
        }

        // Si la primera se rechaza, se puede volver a pedir.
        $primera->update(['estado' => 'rechazada']);
        $this->assertSame('solicitada', $this->solicitar(['pedido_detalle_id' => $linea->id, 'motivo' => 'Otra vez'])->estado);
    }

    public function test_el_cliente_mayorista_usa_el_mismo_plazo(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $afiliacion = $this->mayoristaConCuenta();
        $linea = $this->lineaEntregada(['tipo' => 'revendedor', 'revendedor_distribuidora_id' => $afiliacion->id]);

        Sanctum::actingAs($this->como(Usuario::findOrFail($afiliacion->revendedor->usuario_id)));

        $this->postJson('/api/solicitudes-cambio', ['pedido_detalle_id' => $linea->id, 'motivo' => 'Defecto de fábrica'])
            ->assertCreated()
            ->assertJsonPath('data.propietario.tipo', 'revendedor')
            ->assertJsonPath('data.dias_solicitud_aplicados', 12);
    }

    public function test_cambio_de_venta_de_mostrador_desde_el_panel(): void
    {
        $this->como('empleado@calzadosramirez.test');
        $cliente = $this->clienteConCuenta();
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $resultado = app(RegistrarVentaDirectaService::class)->registrar(
            [['variante_id' => $renglon['variante_id'], 'producto_campana_id' => $renglon['producto_campana_id'], 'cantidad' => 1]],
            'efectivo',
            $cliente->id,
        );

        Livewire::test('cambios.index')
            ->set('folioVenta', $resultado->venta->folio)
            ->call('buscarVenta')
            ->assertSee('Solicitar cambio')
            ->call('abrirCambio', $resultado->detalles[0]->id)
            ->set('cambioMotivo', 'Le quedó chico')
            ->call('solicitarCambio')
            ->assertSet('errorMsg', '');

        $s = SolicitudCambio::query()->firstOrFail();
        $this->assertSame((int) $resultado->detalles[0]->id, (int) $s->venta_detalle_id);
        $this->assertSame((int) $cliente->id, (int) $s->cliente_directo_id);
    }

    public function test_la_venta_sin_cliente_no_admite_cambio_y_el_cliente_no_pide_cambio_de_ventas(): void
    {
        $this->como('empleado@calzadosramirez.test');
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $resultado = app(RegistrarVentaDirectaService::class)->registrar(
            [['variante_id' => $renglon['variante_id'], 'producto_campana_id' => $renglon['producto_campana_id'], 'cantidad' => 1]],
            'efectivo',
        );

        try {
            $this->solicitar(['venta_detalle_id' => $resultado->detalles[0]->id, 'motivo' => 'x']);
            $this->fail('Debió rechazar la venta sin cliente.');
        } catch (\App\Exceptions\OperacionInvalidaException $e) {
            $this->assertStringContainsString('sin cliente', $e->getMessage());
        }

        Sanctum::actingAs($this->como(Usuario::findOrFail($this->clienteConCuenta()->usuario_id)));
        $this->postJson('/api/solicitudes-cambio', ['venta_detalle_id' => $resultado->detalles[0]->id, 'motivo' => 'x'])
            ->assertNotFound();
    }

    public function test_la_lista_solo_muestra_lo_propio_y_el_personal_ve_todo(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $a = $this->clienteConCuenta();
        $b = $this->otroCliente($a);
        $this->solicitar(['pedido_detalle_id' => $this->lineaEntregada($this->deCliente($a))->id, 'motivo' => 'A']);
        $this->solicitar(['pedido_detalle_id' => $this->lineaEntregada($this->deCliente($b))->id, 'motivo' => 'B']);

        Sanctum::actingAs($this->como(Usuario::findOrFail($a->usuario_id)));
        $this->getJson('/api/solicitudes-cambio')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.motivo', 'A');

        Sanctum::actingAs($this->como(self::ADMIN));
        $this->getJson('/api/solicitudes-cambio')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_otra_distribuidora_no_ve_las_solicitudes(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->solicitar(['pedido_detalle_id' => $this->lineaEntregada($this->deCliente($this->clienteConCuenta()))->id, 'motivo' => 'x']);
        $otra = Distribuidora::where('slug', '!=', 'calzados-ramirez')->firstOrFail();

        Tenant::forzar($otra->id, fn () => $this->assertSame(0, SolicitudCambio::query()->count()));
    }

    public function test_el_pedido_de_la_api_dice_si_se_puede_pedir_cambio(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $cliente = $this->clienteConCuenta();
        $linea = $this->lineaEntregada($this->deCliente($cliente));

        Sanctum::actingAs($this->como(Usuario::findOrFail($cliente->usuario_id)));

        $this->getJson('/api/pedidos/'.$linea->pedido_id)
            ->assertOk()
            ->assertJsonPath('data.fecha_entrega', '2026-10-01T10:00:00-06:00')
            ->assertJsonPath('data.lineas.0.cambio.puede_solicitar', true)
            ->assertJsonPath('data.lineas.0.cambio.solicitud', null);

        $this->postJson('/api/solicitudes-cambio', ['pedido_detalle_id' => $linea->id, 'motivo' => 'x'])->assertCreated();

        $this->getJson('/api/pedidos/'.$linea->pedido_id)
            ->assertJsonPath('data.lineas.0.cambio.puede_solicitar', false)
            ->assertJsonPath('data.lineas.0.cambio.solicitud.estado', 'solicitada');
    }

    public function test_el_panel_pide_cambio_desde_el_detalle_del_pedido_y_lo_lista(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $linea = $this->lineaEntregada($this->deCliente($this->clienteConCuenta()));

        Livewire::test('pedidos.show', ['id' => $linea->pedido_id])
            ->assertSee('Solicitar cambio')
            ->assertSee('Hasta 13/10/2026')
            ->call('abrirCambio', $linea->id)
            ->set('cambioMotivo', 'Costura abierta')
            ->call('solicitarCambio')
            ->assertSet('errorMsg', '')
            ->assertSee('Solicitud solicitada');

        $this->get(route('cambios.index'))->assertOk()->assertSee('Costura abierta')->assertSee('Bota Cambio');
    }

    public function test_valida_la_entrada_de_la_api(): void
    {
        Sanctum::actingAs($this->como(self::ADMIN));

        $this->postJson('/api/solicitudes-cambio', ['motivo' => 'x'])->assertStatus(422);
        $this->postJson('/api/solicitudes-cambio', ['pedido_detalle_id' => 1, 'venta_detalle_id' => 1, 'motivo' => 'x'])->assertStatus(422);
        $this->postJson('/api/solicitudes-cambio', ['pedido_detalle_id' => 1])->assertStatus(422)
            ->assertJsonPath('errors.motivo.0', 'Escribe el motivo del cambio.');
    }
}
