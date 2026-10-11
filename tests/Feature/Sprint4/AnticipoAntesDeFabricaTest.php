<?php

namespace Tests\Feature\Sprint4;

use App\Models\Auditoria;
use App\Models\CicloCompra;
use App\Models\ClienteDirecto;
use App\Models\ConfiguracionDistribuidora;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\DispositivoFcm;
use App\Models\Distribuidora;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Models\Usuario;
use App\Models\Vale;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Services\Vale\AplicarValeAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-215 (Ola 2, K8) — Sin anticipo, el pedido no se le pide a la fábrica.
 *
 * Mandar a fábrica un pedido de cliente directo que no dio adelanto es
 * comprarle mercancía a la fábrica por cuenta de la distribuidora. En vez de
 * perderse, ese pedido pasa solo al siguiente ciclo y entra cuando pague.
 *
 * Los pedidos de revendedor no cambian: nunca piden anticipo.
 */
class AnticipoAntesDeFabricaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const JOSE = 'jose.hernandez@cliente.test';

    private const MARIA = 'maria.lopez@revendedor.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    /** Los avisos push que habrían salido a Firebase. */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $pushes = &$this->pushes;
        $this->app->instance(EnviadorPush::class, new class($pushes) implements EnviadorPush {
            public function __construct(private array &$pushes) {}

            public function enviar(array $tokens, string $titulo, string $mensaje, array $datos = []): array
            {
                $this->pushes[] = compact('tokens', 'titulo', 'mensaje');

                return [];
            }
        });
    }

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function conAnticipoDe(float $monto): void
    {
        ConfiguracionDistribuidora::withoutGlobalScopes()
            ->where('distribuidora_id', Distribuidora::where('slug', 'calzados-ramirez')->value('id'))
            ->update(['anticipo_por_producto' => $monto]);
    }

    private function variantePedible(): DisponibilidadVarianteCampana
    {
        return DisponibilidadVarianteCampana::query()->where('estado', 'disponible')->orderBy('id')->firstOrFail();
    }

    /** Alguien arma y envía su pedido desde la app. */
    private function pedidoEnviadoPor(string $email, int $cantidad = 1): int
    {
        $this->como($email);

        $pedidoId = (int) $this->postJson('/api/pedidos')->assertCreated()->json('data.id');
        $variante = $this->variantePedible();

        $this->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $variante->producto_campana_id,
            'variante_id' => $variante->variante_id,
            'cantidad' => $cantidad,
        ])->assertCreated();

        $this->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();

        return $pedidoId;
    }

    private function pedido(int $id): Pedido
    {
        return Pedido::withoutGlobalScopes()->findOrFail($id);
    }

    /** Cierra el ciclo del pedido y lo solicita a fábrica. */
    private function solicitarAFabrica(int $cicloId): array
    {
        $this->como(self::EMPLEADO);

        $this->postJson("/api/ciclos/{$cicloId}/cerrar")->assertOk();

        return $this->postJson("/api/ciclos/{$cicloId}/solicitar-fabrica")->assertOk()->json('data');
    }

    private function pagarAnticipo(int $pedidoId): void
    {
        $this->como(self::EMPLEADO);

        $falta = app(\App\Services\Pedido\RegistrarPagoPedidoAction::class)
            ->resumen($this->pedido($pedidoId))['anticipo_pendiente'];

        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo' => 'anticipo',
            'metodo' => 'efectivo',
            'monto' => $falta,
        ])->assertCreated();
    }

    // ------------------------------------------------------------------
    // Sin anticipo, no va a fábrica
    // ------------------------------------------------------------------

    public function test_un_pedido_sin_anticipo_no_se_manda_y_pasa_al_siguiente_ciclo(): void
    {
        $this->conAnticipoDe(100);
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE);
        $cicloOriginal = (int) $this->pedido($pedidoId)->ciclo_compra_id;
        $estadoAntes = $this->pedido($pedidoId)->estado;

        $this->solicitarAFabrica($cicloOriginal);

        $pedido = $this->pedido($pedidoId);

        $this->assertSame($estadoAntes, $pedido->estado, 'No se le debe tocar el estado.');
        $this->assertNotSame($cicloOriginal, (int) $pedido->ciclo_compra_id, 'Debió pasar al siguiente ciclo.');
        $this->assertSame('abierto', CicloCompra::withoutGlobalScopes()->findOrFail($pedido->ciclo_compra_id)->estado);
    }

    public function test_no_entra_al_consolidado_del_ciclo_que_se_solicito(): void
    {
        $this->conAnticipoDe(100);
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE, cantidad: 3);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $datos = $this->solicitarAFabrica($cicloId);

        $this->assertSame([], $datos['consolidado']);
    }

    public function test_pagado_a_medias_cuenta_como_no_pagado(): void
    {
        $this->conAnticipoDe(100);
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE, cantidad: 2);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        // Debe $200 de anticipo y solo paga $120.
        $this->como(self::EMPLEADO);
        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo' => 'anticipo', 'metodo' => 'efectivo', 'monto' => 120,
        ])->assertCreated();

        $this->solicitarAFabrica($cicloId);

        $this->assertNotSame($cicloId, (int) $this->pedido($pedidoId)->ciclo_compra_id);
    }

    public function test_si_sigue_sin_pagar_vuelve_a_pasar_al_siguiente(): void
    {
        $this->conAnticipoDe(100);
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE);
        $primerCiclo = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->solicitarAFabrica($primerCiclo);
        $segundoCiclo = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->solicitarAFabrica($segundoCiclo);
        $tercerCiclo = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->assertNotSame($segundoCiclo, $tercerCiclo);
        $this->assertNotSame($primerCiclo, $tercerCiclo);
    }

    public function test_el_ciclo_original_se_puede_finalizar_sin_ese_pedido(): void
    {
        $this->conAnticipoDe(100);
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->solicitarAFabrica($cicloId);

        $this->como(self::EMPLEADO);
        $this->postJson("/api/ciclos/{$cicloId}/marcar-transito")->assertOk();
        $this->postJson("/api/ciclos/{$cicloId}/marcar-recibido")->assertOk();
        $this->postJson("/api/ciclos/{$cicloId}/finalizar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'finalizado');
    }

    public function test_queda_registrado_en_la_auditoria(): void
    {
        $this->conAnticipoDe(100);
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE, cantidad: 2);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->solicitarAFabrica($cicloId);

        $registro = Auditoria::withoutGlobalScopes()
            ->where('accion', 'pedido.pospuesto_por_anticipo')
            ->where('entidad_id', $pedidoId)
            ->latest('id')
            ->first();

        $this->assertNotNull($registro);
        $this->assertSame($cicloId, (int) $registro->datos_previos['ciclo_compra_id']);
        $this->assertEqualsWithDelta(200.00, (float) $registro->datos_nuevos['anticipo_pendiente'], 0.001);
    }

    // ------------------------------------------------------------------
    // Con el anticipo cubierto, sí va
    // ------------------------------------------------------------------

    public function test_si_ya_pago_el_anticipo_si_se_manda_a_fabrica(): void
    {
        $this->conAnticipoDe(100);
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE, cantidad: 2);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->pagarAnticipo($pedidoId);

        $datos = $this->solicitarAFabrica($cicloId);

        $this->assertSame('solicitado_fabrica', $this->pedido($pedidoId)->estado);
        $this->assertSame($cicloId, (int) $this->pedido($pedidoId)->ciclo_compra_id);
        $this->assertNotEmpty($datos['consolidado']);
    }

    /** El anticipo también se puede cubrir con un vale (TG-167). */
    public function test_si_lo_cubrio_con_un_vale_tambien_se_manda(): void
    {
        $this->conAnticipoDe(100);
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE, cantidad: 2);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->como(self::EMPLEADO);
        $valeId = (int) $this->postJson('/api/vales', [
            'propietario_tipo' => 'cliente_directo',
            'propietario_id' => ClienteDirecto::withoutGlobalScopes()
                ->where('usuario_id', Usuario::where('email', self::JOSE)->value('id'))
                ->value('id'),
            'monto_original' => 200,
            'motivo' => 'Prueba TG-215',
        ])->assertCreated()->json('data.id');

        app(AplicarValeAction::class)->ejecutar(Vale::findOrFail($valeId), [
            'monto' => 200,
            'pedido_id' => $pedidoId,
        ]);

        $this->solicitarAFabrica($cicloId);

        $this->assertSame('solicitado_fabrica', $this->pedido($pedidoId)->estado);
    }

    public function test_un_pedido_de_revendedor_siempre_se_manda(): void
    {
        $this->conAnticipoDe(100);
        $pedidoId = $this->pedidoEnviadoPor(self::MARIA, cantidad: 2);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->solicitarAFabrica($cicloId);

        $this->assertSame('solicitado_fabrica', $this->pedido($pedidoId)->estado);
        $this->assertSame($cicloId, (int) $this->pedido($pedidoId)->ciclo_compra_id);
    }

    /** Si la distribuidora no cobra anticipo, todo sigue como siempre. */
    public function test_con_anticipo_en_cero_todos_los_pedidos_se_mandan(): void
    {
        $this->conAnticipoDe(0);
        $deJose = $this->pedidoEnviadoPor(self::JOSE, cantidad: 2);
        $deMaria = $this->pedidoEnviadoPor(self::MARIA);
        $cicloId = (int) $this->pedido($deJose)->ciclo_compra_id;

        $this->solicitarAFabrica($cicloId);

        $this->assertSame('solicitado_fabrica', $this->pedido($deJose)->estado);
        $this->assertSame('solicitado_fabrica', $this->pedido($deMaria)->estado);
    }

    // ------------------------------------------------------------------
    // Avisos
    // ------------------------------------------------------------------

    public function test_al_cliente_con_cuenta_se_le_avisa(): void
    {
        $this->conAnticipoDe(100);
        $jose = Usuario::where('email', self::JOSE)->firstOrFail();
        DispositivoFcm::create([
            'usuario_id' => $jose->id,
            'token' => 'celular-de-jose',
            'plataforma' => 'android',
            'ultimo_uso_at' => now(),
        ]);

        $pedidoId = $this->pedidoEnviadoPor(self::JOSE, cantidad: 2);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;
        $this->pushes = [];

        $this->solicitarAFabrica($cicloId);

        $aviso = Notificacion::withoutGlobalScopes()
            ->where('usuario_id', $jose->id)
            ->where('tipo', 'pedido_anticipo_pendiente')
            ->first();

        $this->assertNotNull($aviso);
        $this->assertStringContainsString('no entró al pedido a fábrica', $aviso->mensaje);
        $this->assertStringContainsString('$200.00', $aviso->mensaje);

        $this->assertCount(1, $this->pushes);
        $this->assertSame(['celular-de-jose'], $this->pushes[0]['tokens']);
    }

    public function test_al_cliente_sin_cuenta_no_se_le_manda_nada(): void
    {
        $this->conAnticipoDe(100);

        // Se le quita la cuenta a la ficha del cliente: tenerla es opcional.
        $jose = Usuario::where('email', self::JOSE)->firstOrFail();
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $jose->id)->update(['usuario_id' => null]);
        $this->pushes = [];

        $this->solicitarAFabrica($cicloId);

        $this->assertSame(0, Notificacion::withoutGlobalScopes()->where('tipo', 'pedido_anticipo_pendiente')->count());
        $this->assertSame([], $this->pushes);
    }

    // ------------------------------------------------------------------
    // La pantalla avisa lo mismo
    // ------------------------------------------------------------------

    public function test_la_pantalla_del_ciclo_avisa_cuales_se_quedan_fuera(): void
    {
        $this->conAnticipoDe(100);
        $pedidoId = $this->pedidoEnviadoPor(self::JOSE, cantidad: 2);
        $cicloId = (int) $this->pedido($pedidoId)->ciclo_compra_id;

        $this->como(self::EMPLEADO);
        $this->postJson("/api/ciclos/{$cicloId}/cerrar")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();

        Livewire::test('ciclo.index')
            ->set('cicloId', $cicloId)
            ->assertSee('no se enviará')
            ->assertSee($this->pedido($pedidoId)->folio)
            ->assertSee('200.00');
    }
}
