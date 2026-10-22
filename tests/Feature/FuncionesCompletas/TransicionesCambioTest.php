<?php

namespace Tests\Feature\FuncionesCompletas;

use App\Mail\EstadoSolicitudCambioMail;
use App\Models\ClienteDirecto;
use App\Models\ConfiguracionDistribuidora;
use App\Models\DispositivoFcm;
use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProductoCampana;
use App\Models\SolicitudCambio;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Vale;
use App\Models\Variante;
use App\Services\Auth\PermisoModuloService;
use App\Services\Cambio\SolicitarCambioAction;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** Flujo de la solicitud de cambio: transiciones, vale, avisos y línea de tiempo. */
class TransicionesCambioTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    public array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 12:00:00');
        Mail::fake();
        $prueba = $this;
        $this->app->instance(EnviadorPush::class, new class($prueba) implements EnviadorPush {
            public function __construct(private $prueba) {}

            public function enviar(array $tokens, string $titulo, string $mensaje, array $datos = []): array
            {
                $this->prueba->pushes[] = compact('tokens', 'titulo', 'datos');

                return [];
            }
        });
        $this->comoPanel(self::ADMIN);
        ConfiguracionDistribuidora::query()->update(['dias_solicitud_cambio' => 12]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function solicitudDeCliente(): array
    {
        $cliente = ClienteDirecto::query()->whereNotNull('usuario_id')->orderBy('id')->firstOrFail();
        $pedido = Pedido::create([
            'distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'CAM-'.uniqid(), 'estado' => 'entregado', 'subtotal' => 900, 'total' => 900,
            'fecha_colocacion' => '2026-09-01 10:00:00', 'fecha_entrega' => '2026-10-01 10:00:00',
            'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
            'tipo' => 'cliente_directo', 'cliente_directo_id' => $cliente->id,
        ]);
        $pc = ProductoCampana::query()->firstOrFail();
        $linea = PedidoDetalle::query()->forceCreate([
            'distribuidora_id' => Tenant::id(), 'pedido_id' => $pedido->id, 'producto_campana_id' => $pc->id,
            'variante_id' => Variante::query()->where('producto_id', $pc->producto_id)->value('id') ?? Variante::query()->value('id'),
            'producto_nombre' => 'Bota Cambio', 'modelo' => 'BC-1', 'talla' => '25', 'color' => 'Café',
            'cantidad' => 2, 'precio_unitario' => 450, 'subtotal' => 900,
        ]);
        $solicitud = app(SolicitarCambioAction::class)->ejecutar(['pedido_detalle_id' => $linea->id, 'motivo' => 'Me queda chico']);
        DispositivoFcm::create(['usuario_id' => $cliente->usuario_id, 'token' => 'tok-'.uniqid(), 'plataforma' => 'android']);

        return [$solicitud, $cliente];
    }

    public function test_autorizar_y_emitir_vale_avisa_al_cliente_en_app_push_y_correo(): void
    {
        [$solicitud, $cliente] = $this->solicitudDeCliente();

        $lw = Livewire::test('cambios.index')
            ->call('prepararTransicion', $solicitud->id, 'autorizada')->call('aplicarTransicion')->assertSet('errorMsg', '')
            ->call('prepararTransicion', $solicitud->id, 'vale_emitido')->assertSet('montoVale', '900.00')
            ->call('aplicarTransicion')->assertSet('errorMsg', '');

        $solicitud->refresh();
        $this->assertSame('vale_emitido', $solicitud->estado);
        $vale = Vale::withoutGlobalScopes()->findOrFail($solicitud->vale_generado_id);
        $this->assertEquals(900, (float) $vale->monto_original);
        $this->assertSame($cliente->id, (int) $vale->cliente_directo_id);

        $this->assertSame(2, Notificacion::withoutGlobalScopes()->where('usuario_id', $cliente->usuario_id)->where('tipo', 'cambio_estado')->count());
        $this->assertNotEmpty(array_filter($this->pushes, fn ($p) => ($p['datos']['tipo'] ?? '') === 'cambio_estado'));
        Mail::assertSent(EstadoSolicitudCambioMail::class, 2);
        $this->assertSame(['solicitada', 'autorizada', 'vale_emitido'], $solicitud->historial()->pluck('estado_nuevo')->all());
    }

    public function test_no_se_permite_una_transicion_invalida_y_el_rechazo_pide_motivo(): void
    {
        [$solicitud] = $this->solicitudDeCliente();

        $this->comoApi(self::ADMIN);
        $this->postJson("/api/solicitudes-cambio/{$solicitud->id}/estado", ['estado' => 'cerrada'])->assertStatus(409);
        $this->postJson("/api/solicitudes-cambio/{$solicitud->id}/estado", ['estado' => 'rechazada'])->assertStatus(422);
        $this->postJson("/api/solicitudes-cambio/{$solicitud->id}/estado", ['estado' => 'rechazada', 'nota' => 'Tiene uso'])
            ->assertOk()->assertJsonPath('data.estado', 'rechazada');
        $this->assertSame('Tiene uso', $solicitud->fresh()->resolucion);
    }

    public function test_el_vale_no_puede_ser_mayor_que_la_linea(): void
    {
        [$solicitud] = $this->solicitudDeCliente();
        $this->comoApi(self::ADMIN);
        $this->postJson("/api/solicitudes-cambio/{$solicitud->id}/estado", ['estado' => 'autorizada'])->assertOk();
        $this->postJson("/api/solicitudes-cambio/{$solicitud->id}/estado", ['estado' => 'vale_emitido', 'monto_vale' => 5000])->assertStatus(422);
        $this->assertSame('autorizada', $solicitud->fresh()->estado);
    }

    public function test_el_cliente_ve_su_linea_de_tiempo_y_otra_distribuidora_no(): void
    {
        [$solicitud, $cliente] = $this->solicitudDeCliente();
        $this->comoApi(self::ADMIN);
        $this->postJson("/api/solicitudes-cambio/{$solicitud->id}/estado", ['estado' => 'autorizada', 'nota' => 'Pasa a recoger'])->assertOk();

        $this->comoApi(Usuario::findOrFail($cliente->usuario_id)->email);
        $this->getJson("/api/solicitudes-cambio/{$solicitud->id}")->assertOk()
            ->assertJsonPath('data.timeline.0.estado', 'solicitada')
            ->assertJsonPath('data.timeline.1.etiqueta', 'Autorizada')
            ->assertJsonPath('data.timeline.1.nota', 'Pasa a recoger');
        $this->postJson("/api/solicitudes-cambio/{$solicitud->id}/estado", ['estado' => 'cerrada'])->assertForbidden();

        $this->comoApi(self::OTRA_ADMIN);
        $this->getJson("/api/solicitudes-cambio/{$solicitud->id}")->assertNotFound();
        $this->postJson("/api/solicitudes-cambio/{$solicitud->id}/estado", ['estado' => 'rechazada', 'nota' => 'x'])->assertNotFound();
    }

    public function test_un_empleado_sin_permiso_no_resuelve_cambios(): void
    {
        [$solicitud] = $this->solicitudDeCliente();
        $staff = $this->staffDe(self::EMPLEADO);
        $staff->update(['permisos_finos' => [PermisoModuloService::CAMBIOS_RESOLVER => false]]);

        $this->comoApi(self::EMPLEADO);
        $this->postJson("/api/solicitudes-cambio/{$solicitud->id}/estado", ['estado' => 'autorizada'])->assertForbidden();
        $this->assertSame('solicitada', $solicitud->fresh()->estado);
    }
}
