<?php

namespace Tests\Feature\Pedido;

use App\Models\ClienteDirecto;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
use App\Models\Pedido;
use App\Models\ProductoCampana;
use App\Models\RevendedorDistribuidora;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TG-166 — Al crear un pedido desde la app, el servidor decide la sucursal,
 * el dueño y el precio; no confía en lo que mande el celular.
 *
 * Antes:
 *  - la app mandaba sucursal_id = 1 fijo, que solo existe en la demo;
 *  - la API exigía propietario_id aunque luego lo ignoraba;
 *  - sin precio, todo pedido se cobraba a precio mayorista, también el del
 *    cliente directo, que además lo veía en la respuesta;
 *  - la app podía mandar el precio_unitario que quisiera.
 *
 * Cuentas demo: María (revendedora), José (cliente directo) y el empleado.
 */
class PedidoDesdeAppTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';
    private const JOSE = 'jose.hernandez@cliente.test';
    private const EMPLEADO = 'empleado@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function como(string $email): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', $email)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function sucursalPrincipal(): Sucursal
    {
        return Sucursal::withoutGlobalScopes()
            ->where('distribuidora_id', Distribuidora::where('slug', 'calzados-ramirez')->value('id'))
            ->where('es_principal', true)
            ->firstOrFail();
    }

    /** Una variante que se puede pedir, con precio mayorista y minorista distintos. */
    private function variantePedible(): DisponibilidadVarianteCampana
    {
        $disponibilidad = DisponibilidadVarianteCampana::withoutGlobalScopes()
            ->where('estado', 'disponible')
            ->whereHas('productoCampana', fn ($q) => $q->withoutGlobalScopes()
                ->where('publicado', true)
                ->whereColumn('precio_mayorista', '<>', 'precio_minorista_sugerido')
                ->whereHas('campana', fn ($c) => $c->withoutGlobalScopes()->where('estado', 'activa')))
            ->orderBy('id')
            ->first();

        $this->assertNotNull($disponibilidad, 'El seeder demo no dejó una variante pedible con dos precios distintos.');

        return $disponibilidad;
    }

    private function productoCampana(DisponibilidadVarianteCampana $variante): ProductoCampana
    {
        return ProductoCampana::withoutGlobalScopes()->findOrFail($variante->producto_campana_id);
    }

    /** Pedido nuevo desde la app, sin mandar sucursal ni dueño. */
    private function pedidoDesdeLaApp(array $extra = []): int
    {
        return (int) $this->postJson('/api/pedidos', $extra)->assertCreated()->json('data.id');
    }

    private function agregarLinea(int $pedidoId, DisponibilidadVarianteCampana $variante, array $extra = [])
    {
        return $this->postJson("/api/pedidos/{$pedidoId}/lineas", $extra + [
            'producto_campana_id' => $variante->producto_campana_id,
            'variante_id'         => $variante->variante_id,
            'cantidad'            => 1,
        ])->assertCreated();
    }

    private function pedido(int $id): Pedido
    {
        return Pedido::withoutGlobalScopes()->with('detalle')->findOrFail($id);
    }

    // ------------------------------------------------------------------
    // Sucursal y dueño
    // ------------------------------------------------------------------

    public function test_la_revendedora_crea_su_pedido_sin_mandar_sucursal_ni_duenio(): void
    {
        $this->como(self::MARIA);

        $pedido = $this->pedido($this->pedidoDesdeLaApp());

        $afiliacionMaria = RevendedorDistribuidora::withoutGlobalScopes()
            ->whereHas('revendedor', fn ($q) => $q->where('usuario_id', Usuario::where('email', self::MARIA)->value('id')))
            ->value('id');

        $this->assertSame('revendedor', $pedido->tipo);
        $this->assertSame((int) $afiliacionMaria, (int) $pedido->revendedor_distribuidora_id);
        $this->assertSame($this->sucursalPrincipal()->id, (int) $pedido->sucursal_id);
    }

    public function test_el_cliente_directo_crea_su_pedido_sin_mandar_sucursal_ni_duenio(): void
    {
        $this->como(self::JOSE);

        $pedido = $this->pedido($this->pedidoDesdeLaApp());

        $fichaJose = ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', self::JOSE)->value('id'))
            ->value('id');

        $this->assertSame('cliente_directo', $pedido->tipo);
        $this->assertSame((int) $fichaJose, (int) $pedido->cliente_directo_id);
        $this->assertSame($this->sucursalPrincipal()->id, (int) $pedido->sucursal_id);
    }

    /** La versión actual de la app manda un 1 fijo: tiene que seguir funcionando. */
    public function test_si_la_app_manda_una_sucursal_se_ignora(): void
    {
        $this->como(self::MARIA);

        $pedido = $this->pedido($this->pedidoDesdeLaApp(['sucursal_id' => 999999, 'propietario_id' => 999999]));

        $this->assertSame($this->sucursalPrincipal()->id, (int) $pedido->sucursal_id);
    }

    public function test_si_la_distribuidora_no_tiene_sucursal_principal_activa_da_un_mensaje_claro(): void
    {
        $this->sucursalPrincipal()->update(['activa' => false]);
        $this->como(self::MARIA);

        $this->postJson('/api/pedidos')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sucursal_id']);
    }

    /** El mostrador captura pedidos para cualquiera: ahí sí tiene que decir para quién y en qué sucursal. */
    public function test_el_empleado_sigue_teniendo_que_mandar_sucursal_y_duenio(): void
    {
        $this->como(self::EMPLEADO);

        $this->postJson('/api/pedidos', ['tipo' => 'cliente_directo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['propietario_id', 'sucursal_id']);
    }

    // ------------------------------------------------------------------
    // Precio
    // ------------------------------------------------------------------

    public function test_el_cliente_directo_paga_el_precio_minorista(): void
    {
        $variante = $this->variantePedible();
        $this->como(self::JOSE);

        $pedidoId = $this->pedidoDesdeLaApp();
        $respuesta = $this->agregarLinea($pedidoId, $variante);

        $minorista = (float) $this->productoCampana($variante)->precio_minorista_sugerido;

        $this->assertEqualsWithDelta($minorista, (float) $this->pedido($pedidoId)->detalle->first()->precio_unitario, 0.001);
        $this->assertEqualsWithDelta($minorista, (float) $this->pedido($pedidoId)->total, 0.001);

        // Y el precio de costo del revendedor no le llega en ningún lado.
        $mayorista = (float) $this->productoCampana($variante)->precio_mayorista;
        $this->assertNotContains($mayorista, collect($respuesta->json('data.lineas'))->pluck('precio_unitario')->map(fn ($p) => (float) $p));
    }

    public function test_la_revendedora_paga_el_precio_mayorista(): void
    {
        $variante = $this->variantePedible();
        $this->como(self::MARIA);

        $pedidoId = $this->pedidoDesdeLaApp();
        $this->agregarLinea($pedidoId, $variante);

        $this->assertEqualsWithDelta(
            (float) $this->productoCampana($variante)->precio_mayorista,
            (float) $this->pedido($pedidoId)->detalle->first()->precio_unitario,
            0.001
        );
    }

    /** Antes: la app podía pedir un par a $1. */
    public function test_el_precio_que_mande_la_app_se_ignora(): void
    {
        $variante = $this->variantePedible();

        $this->como(self::JOSE);
        $deJose = $this->pedidoDesdeLaApp();
        $this->agregarLinea($deJose, $variante, ['precio_unitario' => 1]);

        $this->como(self::MARIA);
        $deMaria = $this->pedidoDesdeLaApp();
        $this->agregarLinea($deMaria, $variante, ['precio_unitario' => 1]);

        $pc = $this->productoCampana($variante);
        $this->assertEqualsWithDelta((float) $pc->precio_minorista_sugerido, (float) $this->pedido($deJose)->detalle->first()->precio_unitario, 0.001);
        $this->assertEqualsWithDelta((float) $pc->precio_mayorista, (float) $this->pedido($deMaria)->detalle->first()->precio_unitario, 0.001);
    }

    /** En el mostrador, un pedido de cliente directo también va a precio minorista. */
    public function test_el_empleado_captura_un_pedido_de_cliente_directo_a_precio_minorista(): void
    {
        $variante = $this->variantePedible();
        $this->como(self::EMPLEADO);

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo'           => 'cliente_directo',
            'propietario_id' => ClienteDirecto::where('email', 'ana.garcia@cliente.test')->value('id'),
            'sucursal_id'    => $this->sucursalPrincipal()->id,
        ])->assertCreated()->json('data.id');

        $this->agregarLinea($pedidoId, $variante);

        $this->assertEqualsWithDelta(
            (float) $this->productoCampana($variante)->precio_minorista_sugerido,
            (float) $this->pedido($pedidoId)->detalle->first()->precio_unitario,
            0.001
        );
    }

    /** El personal sí puede poner otro precio, como ya podía. */
    public function test_el_empleado_si_puede_poner_otro_precio(): void
    {
        $variante = $this->variantePedible();
        $this->como(self::EMPLEADO);

        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo'           => 'cliente_directo',
            'propietario_id' => ClienteDirecto::where('email', 'ana.garcia@cliente.test')->value('id'),
            'sucursal_id'    => $this->sucursalPrincipal()->id,
        ])->assertCreated()->json('data.id');

        $this->agregarLinea($pedidoId, $variante, ['precio_unitario' => 450]);

        $this->assertEqualsWithDelta(450.0, (float) $this->pedido($pedidoId)->detalle->first()->precio_unitario, 0.001);
    }
}
