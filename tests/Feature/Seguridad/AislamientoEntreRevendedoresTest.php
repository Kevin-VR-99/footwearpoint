<?php

namespace Tests\Feature\Seguridad;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Vale;
use App\Services\Pedido\CrearPedidoBorradorAction;
use App\Services\Vale\EmitirValeAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-134, último criterio de aceptación: un revendedor no puede ver ni
 * modificar los pedidos de OTRO revendedor de su misma distribuidora.
 *
 * Es un aislamiento distinto al de AislamientoMultiTenantTest: ahí se separa
 * una distribuidora de otra; aquí se separan dos personas DENTRO de la misma
 * distribuidora, que comparten catálogo, sucursal y empleados.
 *
 * El empleado no entra en esta separación: sigue viendo todo lo de su
 * distribuidora, porque captura pedidos en el mostrador para cualquiera.
 */
class AislamientoEntreRevendedoresTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function distribuidoraA(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function sucursalPrincipal(int $distribuidoraId): Sucursal
    {
        return Sucursal::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('es_principal', true)
            ->firstOrFail();
    }

    private function asignarRol(Usuario $usuario, string $rol, int $distribuidoraId): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($distribuidoraId);
        $usuario->assignRole($rol);
        $registrar->forgetCachedPermissions();
    }

    private function crearRevendedor(int $distribuidoraId, string $email): Usuario
    {
        $usuario = Usuario::create([
            'nombre'   => 'Revendedor ' . $email,
            'email'    => $email,
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        $revendedor = Revendedor::create([
            'usuario_id' => $usuario->id,
            'nombre'     => 'Revendedor ' . $email,
            'email'      => $email,
            'estado'     => 'activo',
        ]);

        RevendedorDistribuidora::create([
            'distribuidora_id' => $distribuidoraId,
            'revendedor_id'    => $revendedor->id,
            'estado'           => 'activo',
            'fecha_alta'       => now(),
        ]);

        $this->asignarRol($usuario, 'revendedor', $distribuidoraId);

        return $usuario;
    }

    private function crearClienteDirecto(int $distribuidoraId, string $email): Usuario
    {
        $usuario = Usuario::create([
            'nombre'   => 'Cliente ' . $email,
            'email'    => $email,
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        ClienteDirecto::create([
            'distribuidora_id' => $distribuidoraId,
            'usuario_id'       => $usuario->id,
            'nombre'           => 'Cliente ' . $email,
            'email'            => $email,
            'estado'           => 'activo',
        ]);

        $this->asignarRol($usuario, 'cliente_directo', $distribuidoraId);

        return $usuario;
    }

    private function afiliacionDe(Usuario $usuario): RevendedorDistribuidora
    {
        $revendedor = Revendedor::withoutGlobalScopes()
            ->where('usuario_id', $usuario->id)
            ->firstOrFail();

        return RevendedorDistribuidora::withoutGlobalScopes()
            ->where('revendedor_id', $revendedor->id)
            ->firstOrFail();
    }

    /**
     * Crea un pedido actuando COMO ese usuario, por el camino real.
     *
     * A propósito se mandan un tipo y un propietario_id basura: el sistema
     * debe ignorarlos y usar los del usuario autenticado.
     */
    private function crearPedidoComo(Usuario $usuario, int $distribuidoraId)
    {
        $this->actingAs($usuario);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        return app(CrearPedidoBorradorAction::class)->ejecutar([
            'tipo'           => 'cliente_directo',
            'propietario_id' => 999999,
            'sucursal_id'    => $this->sucursalPrincipal($distribuidoraId)->id,
        ]);
    }

    private function emitirValePara(string $tipo, int $propietarioId): Vale
    {
        $empleado = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();

        $this->actingAs($empleado);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        return app(EmitirValeAction::class)->ejecutar([
            'propietario_tipo' => $tipo,
            'propietario_id'   => $propietarioId,
            'monto_original'   => 500,
            'motivo'           => 'Prueba de aislamiento.',
        ]);
    }

    // ------------------------------------------------------------------
    // El dueño se toma del usuario autenticado, no de la petición
    // ------------------------------------------------------------------

    public function test_el_pedido_queda_a_nombre_del_revendedor_que_lo_crea_aunque_mande_otro_propietario(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $maria = $this->crearRevendedor($distribuidoraA->id, 'maria@revendedor.test');

        $pedido = $this->crearPedidoComo($maria, $distribuidoraA->id);

        // Mandó tipo 'cliente_directo' y propietario_id 999999, y aun así
        // quedó a su nombre: eso es lo que cierra el hueco.
        $this->assertSame('revendedor', $pedido->tipo);
        $this->assertSame($this->afiliacionDe($maria)->id, $pedido->revendedor_distribuidora_id);
        $this->assertNull($pedido->cliente_directo_id);
    }

    public function test_un_cliente_directo_no_puede_crear_un_pedido_a_nombre_de_otro(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $ana = $this->crearClienteDirecto($distribuidoraA->id, 'ana@cliente.test');
        $jose = $this->crearClienteDirecto($distribuidoraA->id, 'jose@cliente.test');

        $fichaDeJose = ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', $jose->id)
            ->firstOrFail();

        $this->actingAs($ana);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $pedido = app(CrearPedidoBorradorAction::class)->ejecutar([
            'tipo'           => 'cliente_directo',
            'propietario_id' => $fichaDeJose->id,
            'sucursal_id'    => $this->sucursalPrincipal($distribuidoraA->id)->id,
        ]);

        $fichaDeAna = ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', $ana->id)
            ->firstOrFail();

        $this->assertSame($fichaDeAna->id, $pedido->cliente_directo_id);
        $this->assertNotSame($fichaDeJose->id, $pedido->cliente_directo_id);
    }

    // ------------------------------------------------------------------
    // Pedidos: un revendedor no ve ni toca los de otro
    // ------------------------------------------------------------------

    public function test_un_revendedor_solo_ve_sus_pedidos_en_el_listado(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $maria = $this->crearRevendedor($distribuidoraA->id, 'maria.lista@revendedor.test');
        $roberto = $this->crearRevendedor($distribuidoraA->id, 'roberto.lista@revendedor.test');

        $pedidoDeMaria = $this->crearPedidoComo($maria, $distribuidoraA->id);
        $pedidoDeRoberto = $this->crearPedidoComo($roberto, $distribuidoraA->id);

        Sanctum::actingAs($maria);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $ids = collect($this->getJson('/api/pedidos')->assertOk()->json('data'))->pluck('id');

        $this->assertContains($pedidoDeMaria->id, $ids);
        $this->assertNotContains($pedidoDeRoberto->id, $ids);
    }

    public function test_un_revendedor_no_puede_abrir_el_pedido_de_otro(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $maria = $this->crearRevendedor($distribuidoraA->id, 'maria.ver@revendedor.test');
        $roberto = $this->crearRevendedor($distribuidoraA->id, 'roberto.ver@revendedor.test');

        $pedidoDeRoberto = $this->crearPedidoComo($roberto, $distribuidoraA->id);

        Sanctum::actingAs($maria);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->getJson("/api/pedidos/{$pedidoDeRoberto->id}")->assertStatus(404);
    }

    public function test_un_revendedor_no_puede_modificar_ni_enviar_el_pedido_de_otro(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $maria = $this->crearRevendedor($distribuidoraA->id, 'maria.mod@revendedor.test');
        $roberto = $this->crearRevendedor($distribuidoraA->id, 'roberto.mod@revendedor.test');

        $pedidoDeRoberto = $this->crearPedidoComo($roberto, $distribuidoraA->id);

        Sanctum::actingAs($maria);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->postJson("/api/pedidos/{$pedidoDeRoberto->id}/lineas", [
            'producto_campana_id' => 1,
            'variante_id'         => 1,
            'cantidad'            => 1,
        ])->assertStatus(404);

        $this->postJson("/api/pedidos/{$pedidoDeRoberto->id}/enviar")->assertStatus(404);

        $this->deleteJson("/api/pedidos/{$pedidoDeRoberto->id}/lineas/1")->assertStatus(404);

        // Y sigue intacto y en borrador.
        $this->assertSame('borrador', $pedidoDeRoberto->fresh()->estado);
    }

    /**
     * Regresión del mostrador: el empleado sí ve los pedidos de todos sus
     * revendedores, porque él los captura.
     */
    public function test_un_empleado_sigue_viendo_los_pedidos_de_todos(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $maria = $this->crearRevendedor($distribuidoraA->id, 'maria.emp@revendedor.test');
        $roberto = $this->crearRevendedor($distribuidoraA->id, 'roberto.emp@revendedor.test');

        $pedidoDeMaria = $this->crearPedidoComo($maria, $distribuidoraA->id);
        $pedidoDeRoberto = $this->crearPedidoComo($roberto, $distribuidoraA->id);

        $empleado = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();

        Sanctum::actingAs($empleado);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $ids = collect($this->getJson('/api/pedidos')->assertOk()->json('data'))->pluck('id');

        $this->assertContains($pedidoDeMaria->id, $ids);
        $this->assertContains($pedidoDeRoberto->id, $ids);
    }

    // ------------------------------------------------------------------
    // Vales
    // ------------------------------------------------------------------

    public function test_un_revendedor_solo_ve_sus_vales(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $maria = $this->crearRevendedor($distribuidoraA->id, 'maria.vale@revendedor.test');
        $roberto = $this->crearRevendedor($distribuidoraA->id, 'roberto.vale@revendedor.test');

        $valeDeMaria = $this->emitirValePara('revendedor', $this->afiliacionDe($maria)->id);
        $valeDeRoberto = $this->emitirValePara('revendedor', $this->afiliacionDe($roberto)->id);

        Sanctum::actingAs($maria);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $ids = collect($this->getJson('/api/vales')->assertOk()->json('data'))->pluck('id');

        $this->assertContains($valeDeMaria->id, $ids);
        $this->assertNotContains($valeDeRoberto->id, $ids);
    }

    public function test_un_revendedor_no_puede_aplicar_el_vale_de_otro(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $maria = $this->crearRevendedor($distribuidoraA->id, 'maria.aplicar@revendedor.test');
        $roberto = $this->crearRevendedor($distribuidoraA->id, 'roberto.aplicar@revendedor.test');

        $valeDeRoberto = $this->emitirValePara('revendedor', $this->afiliacionDe($roberto)->id);
        $pedidoDeMaria = $this->crearPedidoComo($maria, $distribuidoraA->id);

        Sanctum::actingAs($maria);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->postJson("/api/vales/{$valeDeRoberto->id}/aplicar", [
            'monto'     => 100,
            'pedido_id' => $pedidoDeMaria->id,
        ])->assertStatus(404);

        // El saldo de Roberto quedó intacto.
        $this->assertSame(
            (float) $valeDeRoberto->monto_original,
            (float) $valeDeRoberto->fresh()->saldo_actual
        );
    }

    public function test_un_revendedor_no_puede_emitir_vales(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $maria = $this->crearRevendedor($distribuidoraA->id, 'maria.emitir@revendedor.test');

        Sanctum::actingAs($maria);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->postJson('/api/vales', [
            'propietario_tipo' => 'revendedor',
            'propietario_id'   => $this->afiliacionDe($maria)->id,
            'monto_original'   => 5000,
        ])->assertStatus(403);
    }
}
