<?php

namespace Tests\Feature\Notificacion;

use App\Models\ClienteDirecto;
use App\Models\DispositivoFcm;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\CambiarEstadoPedidoService;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Services\Notificacion\Push\EnviadorPushFirebase;
use App\Services\Pedido\CrearPedidoBorradorAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * E16-01 (TG-135) — Push real cuando el pedido cambia de estado.
 *
 * Criterio de la historia: se notifica al menos en los cambios a "recibido en
 * distribuidora" y "listo para entrega".
 *
 * Firebase se reemplaza por EnviadorPushFalso, que no sale a internet y solo
 * anota qué se mandó. La prueba en un celular de verdad depende de que la app
 * registre su token (parte de Flutter).
 */
class PushCambioEstadoPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private EnviadorPushFalso $push;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->push = new EnviadorPushFalso();
        $this->app->instance(EnviadorPush::class, $this->push);
    }

    private function jose(): Usuario
    {
        // Cliente directo de demo con cuenta de la app.
        return Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail();
    }

    private function registrarCelular(Usuario $usuario, string $token): void
    {
        DispositivoFcm::create([
            'usuario_id'    => $usuario->id,
            'token'         => $token,
            'plataforma'    => 'android',
            'ultimo_uso_at' => now(),
        ]);
    }

    /** Pedido de José, creado por él mismo desde la app. */
    private function pedidoDeJose(): Pedido
    {
        $this->actingAs($this->jose());
        $this->olvidarCaches();

        $sucursal = Sucursal::withoutGlobalScopes()->where('es_principal', true)->firstOrFail();

        return app(CrearPedidoBorradorAction::class)->ejecutar([
            'tipo'           => 'cliente_directo',
            'propietario_id' => 0,
            'sucursal_id'    => $sucursal->id,
        ]);
    }

    /** El cambio de estado lo hace el empleado, como en el mostrador. */
    private function cambiarEstado(Pedido $pedido, string $estado): void
    {
        $this->actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());
        $this->olvidarCaches();

        app(CambiarEstadoPedidoService::class)->cambiar($pedido, $estado);
    }

    private function olvidarCaches(): void
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    // ------------------------------------------------------------------
    // Cuándo sí se manda
    // ------------------------------------------------------------------

    public function test_al_quedar_listo_para_entrega_le_llega_push_al_dueno(): void
    {
        $this->registrarCelular($this->jose(), 'celular-de-jose');
        $pedido = $this->pedidoDeJose();

        $this->cambiarEstado($pedido, 'listo_entrega');

        $this->assertCount(1, $this->push->envios);
        $envio = $this->push->envios[0];

        $this->assertSame(['celular-de-jose'], $envio['tokens']);
        $this->assertStringContainsString($pedido->folio, $envio['titulo']);
        $this->assertStringContainsString('recoger', $envio['mensaje']);

        // Datos para que la app abra el pedido al tocar el aviso.
        $this->assertSame([
            'tipo'         => 'pedido_llegada',
            'entidad_tipo' => 'pedido',
            'entidad_id'   => (string) $pedido->id,
        ], $envio['datos']);
    }

    public function test_al_llegar_a_la_distribuidora_le_llega_push_al_dueno(): void
    {
        $this->registrarCelular($this->jose(), 'celular-de-jose');
        $pedido = $this->pedidoDeJose();

        $this->cambiarEstado($pedido, 'recibido_distribuidora');

        $this->assertCount(1, $this->push->envios);
        $this->assertStringContainsString('llegó', $this->push->envios[0]['mensaje']);
    }

    public function test_si_tiene_varios_celulares_le_llega_a_todos(): void
    {
        $this->registrarCelular($this->jose(), 'celular-1');
        $this->registrarCelular($this->jose(), 'celular-2');
        $pedido = $this->pedidoDeJose();

        $this->cambiarEstado($pedido, 'listo_entrega');

        $this->assertEqualsCanonicalizing(['celular-1', 'celular-2'], $this->push->envios[0]['tokens']);
    }

    // ------------------------------------------------------------------
    // Cuándo no
    // ------------------------------------------------------------------

    public function test_otros_cambios_de_estado_no_mandan_push(): void
    {
        $this->registrarCelular($this->jose(), 'celular-de-jose');
        $pedido = $this->pedidoDeJose();

        $this->cambiarEstado($pedido, 'en_transito');

        $this->assertSame([], $this->push->envios);
    }

    public function test_si_el_dueno_no_tiene_celular_registrado_no_se_intenta_enviar(): void
    {
        $pedido = $this->pedidoDeJose();

        $this->cambiarEstado($pedido, 'listo_entrega');

        $this->assertSame([], $this->push->envios);

        // Pero la notificación de la bandeja sí se crea, como siempre.
        $this->assertTrue(
            Notificacion::withoutGlobalScopes()->where('usuario_id', $this->jose()->id)->exists()
        );
    }

    public function test_no_le_llega_a_los_celulares_de_otra_persona(): void
    {
        $maria = Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();
        $this->registrarCelular($maria, 'celular-de-maria');
        $this->registrarCelular($this->jose(), 'celular-de-jose');
        $pedido = $this->pedidoDeJose();

        $this->cambiarEstado($pedido, 'listo_entrega');

        $this->assertSame(['celular-de-jose'], $this->push->envios[0]['tokens']);
    }

    /**
     * Si la transacción donde cambió el estado se deshace, el aviso no debe
     * haber salido: el cliente vería "tu pedido llegó" de un pedido que en
     * realidad no cambió.
     */
    public function test_si_la_transaccion_se_deshace_no_sale_ningun_push(): void
    {
        $this->registrarCelular($this->jose(), 'celular-de-jose');
        $pedido = $this->pedidoDeJose();

        try {
            DB::transaction(function () use ($pedido) {
                $this->cambiarEstado($pedido, 'recibido_distribuidora');

                throw new RuntimeException('Algo falló después del cambio de estado.');
            });
        } catch (RuntimeException) {
            // Esperado.
        }

        $this->assertSame([], $this->push->envios);
        $this->assertSame('borrador', $pedido->fresh()->estado);
    }

    // ------------------------------------------------------------------
    // Cuando Firebase falla, no se cae nada
    // ------------------------------------------------------------------

    public function test_si_firebase_falla_el_pedido_cambia_de_estado_igual(): void
    {
        $this->registrarCelular($this->jose(), 'celular-de-jose');
        $this->push->fallar = true;
        $pedido = $this->pedidoDeJose();

        $this->cambiarEstado($pedido, 'listo_entrega');

        $this->assertSame('listo_entrega', $pedido->fresh()->estado);
        $this->assertTrue(
            Notificacion::withoutGlobalScopes()->where('usuario_id', $this->jose()->id)->exists()
        );
    }

    public function test_los_celulares_que_firebase_reporta_como_invalidos_se_borran(): void
    {
        $this->registrarCelular($this->jose(), 'celular-vigente');
        $this->registrarCelular($this->jose(), 'celular-ya-sin-app');
        $this->push->tokensInvalidos = ['celular-ya-sin-app'];
        $pedido = $this->pedidoDeJose();

        $this->cambiarEstado($pedido, 'listo_entrega');

        $this->assertDatabaseHas('dispositivos_fcm', ['token' => 'celular-vigente']);
        $this->assertDatabaseMissing('dispositivos_fcm', ['token' => 'celular-ya-sin-app']);
    }

    /**
     * Con el enviador REAL de Firebase pero sin llave (como le pasa a quien
     * del equipo no la tiene): el cambio de estado tiene que funcionar igual.
     */
    public function test_sin_llave_de_firebase_el_sistema_sigue_funcionando(): void
    {
        $this->app->bind(EnviadorPush::class, EnviadorPushFirebase::class);
        config(['firebase.projects.app.credentials' => 'storage/app/firebase/no-existe.json']);
        Log::spy();

        $this->registrarCelular($this->jose(), 'celular-de-jose');
        $pedido = $this->pedidoDeJose();

        $this->cambiarEstado($pedido, 'listo_entrega');

        $this->assertSame('listo_entrega', $pedido->fresh()->estado);

        // Y de verdad intentó enviar, falló por la llave y lo dejó en el log.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($mensaje) => str_contains($mensaje, 'No se pudo mandar el push'))
            ->once();
    }
}

/**
 * Enviador de mentira para las pruebas: no sale a internet, solo anota.
 */
class EnviadorPushFalso implements EnviadorPush
{
    /** @var array<int, array{tokens: string[], titulo: string, mensaje: string, datos: array}> */
    public array $envios = [];

    public bool $fallar = false;

    /** @var string[] */
    public array $tokensInvalidos = [];

    public function enviar(array $tokens, string $titulo, string $mensaje, array $datos = []): array
    {
        if ($this->fallar) {
            throw new RuntimeException('Firebase no respondió.');
        }

        $this->envios[] = compact('tokens', 'titulo', 'mensaje', 'datos');

        return $this->tokensInvalidos;
    }
}
