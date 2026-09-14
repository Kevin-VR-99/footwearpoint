<?php

namespace Tests\Feature\Ciclo;

use App\Models\ClienteDirecto;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Pedido;
use App\Models\Revendedor;
use App\Models\Usuario;
use App\Services\CambiarEstadoPedidoService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E10-03 (TG-120) — Cerrar el ciclo y generar la solicitud consolidada a
 * fábrica, CON PEDIDOS REALES.
 *
 * Las pruebas de CicloCompraTest usan ciclos vacíos, así que nunca revisaron
 * qué les pasa a los pedidos. Al hacerlo se encontró que un pedido enviado
 * queda en 'colocado' y nada lo pasa a 'confirmado', mientras que el ciclo
 * solo mandaba a fábrica 'confirmado' e 'incluido_en_ciclo': ningún pedido
 * real llegaba nunca a 'solicitado_fabrica'.
 *
 * Decisiones del equipo:
 *   1. Al solicitar a fábrica se incluyen los pedidos activos del ciclo:
 *      colocado, en_revision, confirmado, incluido_en_ciclo. Rechazados y
 *      descartados quedan fuera.
 *   2. Se mantienen los 2 pasos: cerrar y luego solicitar a fábrica.
 *   3. El consolidado nunca cuenta rechazados ni descartados.
 */
class CierreCicloConPedidosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarSesionEnMemoria();
    }

    private function olvidarSesionEnMemoria(): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function iniciarSesion(string $email): string
    {
        $token = $this->postJson('/api/auth/login', [
            'email'    => $email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $this->olvidarSesionEnMemoria();

        return $token;
    }

    private function comoEmpleado(): void
    {
        $this->olvidarSesionEnMemoria();
        Sanctum::actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());
    }

    /**
     * Dos variantes distintas que se pueden pedir (publicadas, campaña activa,
     * disponibles).
     *
     * @return array<int, array{producto_campana_id:int, variante_id:int}>
     */
    private function dosVariantesPedibles(): array
    {
        $variantes = DisponibilidadVarianteCampana::withoutGlobalScopes()
            ->where('estado', 'disponible')
            ->whereHas('productoCampana', fn ($q) => $q->withoutGlobalScopes()
                ->where('publicado', true)
                ->whereHas('campana', fn ($c) => $c->withoutGlobalScopes()->where('estado', 'activa')))
            ->orderBy('id')
            ->get()
            ->unique('variante_id')
            ->take(2)
            ->map(fn ($d) => [
                'producto_campana_id' => (int) $d->producto_campana_id,
                'variante_id'         => (int) $d->variante_id,
            ])
            ->values()
            ->all();

        $this->assertCount(2, $variantes, 'El seeder demo no dejó dos variantes pedibles.');

        return $variantes;
    }

    /**
     * El dueño arma y envía su pedido desde la app. Regresa el id.
     *
     * @param array<int, array{0: array, 1: int}> $lineas [variante, cantidad]
     */
    private function enviarPedido(string $token, string $tipo, int $propietarioId, array $lineas): int
    {
        $pedidoId = $this->withToken($token)->postJson('/api/pedidos', [
            'tipo'           => $tipo,
            'propietario_id' => $propietarioId,
            'sucursal_id'    => 1,
        ])->assertCreated()->json('data.id');
        $this->olvidarSesionEnMemoria();

        foreach ($lineas as [$variante, $cantidad]) {
            $this->withToken($token)->postJson("/api/pedidos/{$pedidoId}/lineas", [
                'producto_campana_id' => $variante['producto_campana_id'],
                'variante_id'         => $variante['variante_id'],
                'cantidad'            => $cantidad,
            ])->assertCreated();
            $this->olvidarSesionEnMemoria();
        }

        $this->withToken($token)->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();
        $this->olvidarSesionEnMemoria();

        return (int) $pedidoId;
    }

    private function estadoDe(int $pedidoId): string
    {
        return Pedido::withoutGlobalScopes()->findOrFail($pedidoId)->estado;
    }

    /** [variante_id => piezas] del consolidado que regresa el endpoint. */
    private function piezasPorVariante(array $consolidado): array
    {
        return collect($consolidado)
            ->mapWithKeys(fn ($renglon) => [(int) $renglon['variante_id'] => (int) $renglon['cantidad_total']])
            ->all();
    }

    /**
     * Arma un ciclo con pedidos en todos los casos que importan. Regresa el
     * id del ciclo, los pedidos y las dos variantes.
     */
    private function cicloConPedidos(): array
    {
        [$x, $y] = $this->dosVariantesPedibles();

        $maria = Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();
        $jose = Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail();
        $revendedorId = (int) Revendedor::where('usuario_id', $maria->id)->value('id');
        $clienteId = (int) ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $jose->id)->value('id');

        $tokenMaria = $this->iniciarSesion('maria.lopez@revendedor.test');
        $tokenJose = $this->iniciarSesion('jose.hernandez@cliente.test');

        $pedidos = [
            // Se queda como lo deja el envío: 'colocado'.
            'colocado'    => $this->enviarPedido($tokenMaria, 'revendedor', $revendedorId, [[$x, 2]]),
            // Misma variante X en otro pedido, más la variante Y.
            'en_revision' => $this->enviarPedido($tokenJose, 'cliente_directo', $clienteId, [[$x, 3], [$y, 1]]),
            'confirmado'  => $this->enviarPedido($tokenMaria, 'revendedor', $revendedorId, [[$y, 5]]),
            // Estos NO deben ir a fábrica ni sumar en el consolidado.
            'rechazado'   => $this->enviarPedido($tokenMaria, 'revendedor', $revendedorId, [[$x, 40]]),
            'descartado'  => $this->enviarPedido($tokenJose, 'cliente_directo', $clienteId, [[$y, 70]]),
        ];

        $this->comoEmpleado();

        $cambiar = app(CambiarEstadoPedidoService::class);
        foreach (['en_revision', 'confirmado', 'rechazado', 'descartado'] as $estado) {
            $cambiar->cambiar(Pedido::withoutGlobalScopes()->findOrFail($pedidos[$estado]), $estado);
        }

        $cicloIds = Pedido::withoutGlobalScopes()->whereIn('id', $pedidos)->pluck('ciclo_compra_id')->unique();
        $this->assertCount(1, $cicloIds, 'Los pedidos de la prueba debían caer en el mismo ciclo.');

        return [(int) $cicloIds->first(), $pedidos, $x, $y];
    }

    // ------------------------------------------------------------------

    public function test_al_solicitar_a_fabrica_los_pedidos_activos_pasan_a_solicitado_fabrica(): void
    {
        [$cicloId, $pedidos] = $this->cicloConPedidos();

        $this->postJson("/api/ciclos/{$cicloId}/cerrar")->assertOk();

        // Cerrar no toca pedidos: solo deja de aceptar nuevos (decisión 2).
        $this->assertSame('colocado', $this->estadoDe($pedidos['colocado']));

        $this->postJson("/api/ciclos/{$cicloId}/solicitar-fabrica")
            ->assertOk()
            ->assertJsonPath('data.estado', 'solicitado');

        foreach (['colocado', 'en_revision', 'confirmado'] as $caso) {
            $this->assertSame(
                'solicitado_fabrica',
                $this->estadoDe($pedidos[$caso]),
                "El pedido que estaba en '{$caso}' debió pasar a solicitado_fabrica."
            );

            $this->assertDatabaseHas('historial_estados_pedido', [
                'pedido_id'    => $pedidos[$caso],
                'estado_nuevo' => 'solicitado_fabrica',
            ]);
        }

        $this->assertSame('rechazado', $this->estadoDe($pedidos['rechazado']));
        $this->assertSame('descartado', $this->estadoDe($pedidos['descartado']));
    }

    public function test_un_pedido_incluido_en_ciclo_tambien_se_manda_a_fabrica(): void
    {
        [$cicloId, $pedidos] = $this->cicloConPedidos();

        app(CambiarEstadoPedidoService::class)->cambiar(
            Pedido::withoutGlobalScopes()->findOrFail($pedidos['colocado']),
            'incluido_en_ciclo'
        );

        $this->postJson("/api/ciclos/{$cicloId}/cerrar")->assertOk();
        $this->postJson("/api/ciclos/{$cicloId}/solicitar-fabrica")->assertOk();

        $this->assertSame('solicitado_fabrica', $this->estadoDe($pedidos['colocado']));
    }

    public function test_el_consolidado_suma_por_variante_sin_rechazados_ni_descartados(): void
    {
        [$cicloId, , $x, $y] = $this->cicloConPedidos();

        // X: 2 (colocado) + 3 (en revisión). El rechazado de 40 no cuenta.
        // Y: 1 (en revisión) + 5 (confirmado). El descartado de 70 no cuenta.
        $esperado = [$x['variante_id'] => 5, $y['variante_id'] => 6];

        // Antes de pedir: lo que se va a pedir.
        $antes = $this->getJson("/api/ciclos/{$cicloId}")->assertOk()->json('data.consolidado');
        $this->assertEquals($esperado, $this->piezasPorVariante($antes));

        $this->postJson("/api/ciclos/{$cicloId}/cerrar")->assertOk();
        $solicitud = $this->postJson("/api/ciclos/{$cicloId}/solicitar-fabrica")->assertOk();

        // La solicitud a fábrica lleva exactamente eso.
        $this->assertEquals($esperado, $this->piezasPorVariante($solicitud->json('data.consolidado')));

        // Y después de pedir, el consolidado no se vacía: sigue mostrando lo
        // que se pidió, aunque los pedidos ya estén en solicitado_fabrica.
        $despues = $this->getJson("/api/ciclos/{$cicloId}")->assertOk()->json('data.consolidado');
        $this->assertEquals($esperado, $this->piezasPorVariante($despues));
    }

    public function test_el_consolidado_se_mantiene_hasta_recibir_la_mercancia(): void
    {
        [$cicloId, $pedidos, $x, $y] = $this->cicloConPedidos();
        $esperado = [$x['variante_id'] => 5, $y['variante_id'] => 6];

        $this->postJson("/api/ciclos/{$cicloId}/cerrar")->assertOk();
        $this->postJson("/api/ciclos/{$cicloId}/solicitar-fabrica")->assertOk();
        $this->postJson("/api/ciclos/{$cicloId}/marcar-transito")->assertOk();

        $recibido = $this->postJson("/api/ciclos/{$cicloId}/marcar-recibido")->assertOk();

        $this->assertSame('recibido_distribuidora', $this->estadoDe($pedidos['confirmado']));
        $this->assertEquals($esperado, $this->piezasPorVariante($recibido->json('data.consolidado')));
    }
}
