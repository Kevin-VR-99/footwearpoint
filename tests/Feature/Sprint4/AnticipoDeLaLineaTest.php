<?php

namespace Tests\Feature\Sprint4;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\ClienteDirecto;
use App\Models\ConfiguracionDistribuidora;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\Distribuidora;
use App\Models\Marca;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\Usuario;
use App\Models\Variante;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TG-215 (Ola 2, K8) — El anticipo nunca puede ser mayor que el precio.
 *
 * El anticipo es un monto fijo por par que configura la distribuidora, pero
 * si un par cuesta menos que ese monto, se pide el precio del par: cobrar de
 * adelanto más de lo que cuesta no tiene sentido (E8-02).
 *
 * Y solo lo da el cliente directo: el revendedor paga el total en mostrador.
 */
class AnticipoDeLaLineaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const JOSE = 'jose.hernandez@cliente.test';

    private const MARIA = 'maria.lopez@revendedor.test';

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

    private function conAnticipoDe(float $monto): void
    {
        ConfiguracionDistribuidora::withoutGlobalScopes()
            ->where('distribuidora_id', Distribuidora::where('slug', 'calzados-ramirez')->value('id'))
            ->update(['anticipo_por_producto' => $monto]);
    }

    /** Un producto del catálogo demo con el precio que pida la prueba. */
    private function productoDe(float $precio): DisponibilidadVarianteCampana
    {
        $campana = Campana::where('estado', 'activa')->firstOrFail();

        $producto = Producto::create([
            'marca_id' => Marca::firstOrFail()->id,
            'categoria_id' => CategoriaProducto::firstOrFail()->id,
            'modelo' => 'MOD-ANT-'.uniqid(),
            'nombre' => 'Producto para anticipo',
            'activo' => true,
        ]);

        $productoCampana = ProductoCampana::create([
            'producto_id' => $producto->id,
            'campana_id' => $campana->id,
            'codigo_catalogo' => 'ANT-'.uniqid(),
            'precio_publico' => $precio,
            'activo' => true,
        ]);

        $variante = Variante::create([
            'producto_id' => $producto->id,
            'talla_id' => Talla::firstOrFail()->id,
            'color_id' => \App\Models\Color::firstOrFail()->id,
            'sku' => 'SKU-ANT-'.uniqid(),
            'activa' => true,
        ]);

        return DisponibilidadVarianteCampana::create([
            'producto_campana_id' => $productoCampana->id,
            'variante_id' => $variante->id,
            'estado' => 'disponible',
            'fecha_verificacion' => now(),
        ]);
    }

    private function pedidoDe(string $email): int
    {
        $this->como($email);

        return (int) $this->postJson('/api/pedidos')->assertCreated()->json('data.id');
    }

    private function agregar(int $pedidoId, DisponibilidadVarianteCampana $d, int $cantidad = 1, ?float $precio = null)
    {
        $cuerpo = [
            'producto_campana_id' => $d->producto_campana_id,
            'variante_id' => $d->variante_id,
            'cantidad' => $cantidad,
        ];

        if ($precio !== null) {
            $cuerpo['precio_unitario'] = $precio;
        }

        return $this->postJson("/api/pedidos/{$pedidoId}/lineas", $cuerpo);
    }

    private function anticipoDelPedido(int $pedidoId): float
    {
        return (float) Pedido::withoutGlobalScopes()->findOrFail($pedidoId)->detalle()->sum('anticipo_requerido');
    }

    // ------------------------------------------------------------------
    // El anticipo nunca pasa del precio
    // ------------------------------------------------------------------

    public function test_si_el_par_cuesta_menos_que_el_anticipo_se_pide_el_precio_del_par(): void
    {
        $this->conAnticipoDe(100);
        $barato = $this->productoDe(80);

        $pedidoId = $this->pedidoDe(self::JOSE);
        $this->agregar($pedidoId, $barato)->assertCreated();

        $this->assertEqualsWithDelta(80.00, $this->anticipoDelPedido($pedidoId), 0.001);
    }

    public function test_si_el_par_cuesta_mas_se_pide_el_anticipo_configurado(): void
    {
        $this->conAnticipoDe(100);
        $caro = $this->productoDe(500);

        $pedidoId = $this->pedidoDe(self::JOSE);
        $this->agregar($pedidoId, $caro)->assertCreated();

        $this->assertEqualsWithDelta(100.00, $this->anticipoDelPedido($pedidoId), 0.001);
    }

    public function test_el_anticipo_se_multiplica_por_los_pares_pedidos(): void
    {
        $this->conAnticipoDe(100);
        $barato = $this->productoDe(80);

        $pedidoId = $this->pedidoDe(self::JOSE);
        $this->agregar($pedidoId, $barato, cantidad: 3)->assertCreated();

        $this->assertEqualsWithDelta(240.00, $this->anticipoDelPedido($pedidoId), 0.001);
    }

    /** Se mide contra el precio que de verdad se cobra, no contra el de catálogo. */
    public function test_si_el_personal_baja_el_precio_el_anticipo_baja_con_el(): void
    {
        $this->conAnticipoDe(100);
        $producto = $this->productoDe(500);

        $this->como('empleado@calzadosramirez.test');
        $pedidoId = (int) $this->postJson('/api/pedidos', [
            'tipo' => 'cliente_directo',
            // Se busca por su cuenta: desde TG-216 el correo del contacto se
            // vacía al activarla, porque su correo pasa a ser el de acceso.
            'propietario_id' => ClienteDirecto::withoutGlobalScopes()
                ->where('usuario_id', Usuario::where('email', self::JOSE)->value('id'))
                ->value('id'),
            'sucursal_id' => Sucursal::where('es_principal', true)->value('id'),
        ])->assertCreated()->json('data.id');

        $this->agregar($pedidoId, $producto, cantidad: 1, precio: 60)->assertCreated();

        $this->assertEqualsWithDelta(60.00, $this->anticipoDelPedido($pedidoId), 0.001);
    }

    // ------------------------------------------------------------------
    // El revendedor no da anticipo
    // ------------------------------------------------------------------

    public function test_un_pedido_de_revendedor_no_pide_anticipo(): void
    {
        $this->conAnticipoDe(100);
        $producto = $this->productoDe(500);

        $pedidoId = $this->pedidoDe(self::MARIA);
        $this->agregar($pedidoId, $producto, cantidad: 2)->assertCreated();

        $this->assertEqualsWithDelta(0.00, $this->anticipoDelPedido($pedidoId), 0.001);

        $this->como(self::MARIA);
        $this->getJson("/api/pedidos/{$pedidoId}")
            ->assertOk()
            ->assertJsonPath('data.anticipo_requerido', 0)
            ->assertJsonPath('data.anticipo_pendiente', 0);
    }

    public function test_el_cliente_directo_si_ve_su_anticipo_en_la_app(): void
    {
        $this->conAnticipoDe(100);
        $producto = $this->productoDe(500);

        $pedidoId = $this->pedidoDe(self::JOSE);
        $this->agregar($pedidoId, $producto, cantidad: 2)->assertCreated();

        $this->como(self::JOSE);
        $this->getJson("/api/pedidos/{$pedidoId}")
            ->assertOk()
            ->assertJsonPath('data.anticipo_requerido', 200)
            ->assertJsonPath('data.anticipo_pendiente', 200);
    }
}
