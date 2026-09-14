<?php

namespace Tests\Feature\Pedido;

use App\Models\ClienteDirecto;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\MovimientoStock;
use App\Models\Pedido;
use App\Models\Revendedor;
use App\Models\StockLocal;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\CambiarEstadoPedidoService;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E6-03 (TG-113) — "Un pedido de revendedor NO afecta el stock local, aunque
 * el producto exista físicamente."
 *
 * El stock_local solo lo descuenta la venta directa (E7-01), con
 * DescuentoStockVentaDirectaService. Un pedido se surte de fábrica por ciclo
 * de compra, así que no debe tocar la existencia del mostrador en ningún
 * momento de su vida: ni al crearlo, ni al agregar líneas, ni al enviarlo,
 * ni al cambiar de estado hasta entregado, ni al cobrarlo.
 *
 * Hasta esta prueba, eso solo se cumplía porque ningún archivo de pedidos
 * toca stock_local; nada lo protegía de un cambio futuro.
 *
 * Se elige a propósito una variante CON existencia, para que un descuento
 * indebido sí fuera posible. Y se revisan TODAS las filas de stock_local y de
 * movimientos_stock, no solo la de esa variante.
 */
class PedidoNoAfectaStockTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Toda la vida de un pedido después de enviarlo, hasta entregarlo. */
    private const ESTADOS_HASTA_ENTREGA = [
        'en_revision',
        'confirmado',
        'incluido_en_ciclo',
        'solicitado_fabrica',
        'en_transito',
        'recibido_distribuidora',
        'listo_entrega',
        'entregado',
    ];

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

    /**
     * Una variante que se puede pedir (publicada, campaña activa, marcada
     * disponible) y que además tiene existencia en el mostrador.
     *
     * @return array{producto_campana_id:int, variante_id:int, sucursal_id:int}
     */
    private function varianteConExistencia(): array
    {
        $candidatas = DisponibilidadVarianteCampana::withoutGlobalScopes()
            ->where('estado', 'disponible')
            ->whereHas('productoCampana', fn ($q) => $q->withoutGlobalScopes()
                ->where('publicado', true)
                ->whereHas('campana', fn ($c) => $c->withoutGlobalScopes()->where('estado', 'activa')))
            ->orderBy('id')
            ->get();

        foreach ($candidatas as $disponibilidad) {
            $stock = StockLocal::withoutGlobalScopes()
                ->where('variante_id', $disponibilidad->variante_id)
                ->where('cantidad_disponible', '>', 0)
                ->first();

            if ($stock !== null) {
                return [
                    'producto_campana_id' => (int) $disponibilidad->producto_campana_id,
                    'variante_id'         => (int) $disponibilidad->variante_id,
                    'sucursal_id'         => (int) $stock->sucursal_id,
                ];
            }
        }

        $this->fail('El seeder demo no dejó ninguna variante pedible con existencia en stock_local.');
    }

    /** Foto de toda la existencia: [stock_local_id => cantidad_disponible]. */
    private function fotoDelStock(): array
    {
        return StockLocal::withoutGlobalScopes()
            ->orderBy('id')
            ->pluck('cantidad_disponible', 'id')
            ->map(fn ($cantidad) => (int) $cantidad)
            ->all();
    }

    private function totalMovimientosStock(): int
    {
        return MovimientoStock::withoutGlobalScopes()->count();
    }

    /**
     * El dueño arma y envía su pedido desde la app, con su propio token.
     */
    private function armarYEnviarPedido(string $token, string $tipo, int $propietarioId, array $variante): int
    {
        $pedidoId = $this->withToken($token)->postJson('/api/pedidos', [
            'tipo'           => $tipo,
            'propietario_id' => $propietarioId,
            'sucursal_id'    => $variante['sucursal_id'],
        ])->assertCreated()->json('data.id');
        $this->olvidarSesionEnMemoria();

        $this->withToken($token)->postJson("/api/pedidos/{$pedidoId}/lineas", [
            'producto_campana_id' => $variante['producto_campana_id'],
            'variante_id'         => $variante['variante_id'],
            'cantidad'            => 2,
        ])->assertCreated();
        $this->olvidarSesionEnMemoria();

        $this->withToken($token)->postJson("/api/pedidos/{$pedidoId}/enviar")->assertOk();
        $this->olvidarSesionEnMemoria();

        return (int) $pedidoId;
    }

    /**
     * La distribuidora lleva el pedido hasta entregado, cobrándolo en el
     * camino, como en el mostrador.
     */
    private function llevarHastaEntregadoYCobrar(int $pedidoId, bool $conAnticipo): void
    {
        $empleado = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($empleado);
        Tenant::olvidarCache();

        $cambiarEstado = app(CambiarEstadoPedidoService::class);

        foreach (self::ESTADOS_HASTA_ENTREGA as $estado) {
            $pedido = Pedido::withoutGlobalScopes()->findOrFail($pedidoId);
            $cambiarEstado->cambiar($pedido, $estado);

            // Justo cuando llega la mercancía se cobra (anticipo y saldo).
            if ($estado === 'recibido_distribuidora') {
                $this->cobrar($pedidoId, $conAnticipo);
            }
        }

        $this->assertSame('entregado', Pedido::withoutGlobalScopes()->findOrFail($pedidoId)->estado);
    }

    private function cobrar(int $pedidoId, bool $conAnticipo): void
    {
        $pedido = Pedido::withoutGlobalScopes()->findOrFail($pedidoId);
        $resumen = app(RegistrarPagoPedidoAction::class)->resumen($pedido);

        if ($conAnticipo && $resumen['anticipo_pendiente'] > 0) {
            $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
                'tipo'   => 'anticipo',
                'metodo' => 'efectivo',
                'monto'  => $resumen['anticipo_pendiente'],
            ])->assertCreated();

            $pedido = Pedido::withoutGlobalScopes()->findOrFail($pedidoId);
            $resumen = app(RegistrarPagoPedidoAction::class)->resumen($pedido);
        }

        $this->postJson("/api/pedidos/{$pedidoId}/pagos", [
            'tipo'   => 'saldo_pedido',
            'metodo' => 'efectivo',
            'monto'  => $resumen['saldo'],
        ])->assertCreated();
    }

    // ------------------------------------------------------------------

    public function test_un_pedido_de_revendedor_no_toca_el_stock_en_toda_su_vida(): void
    {
        $variante = $this->varianteConExistencia();
        $stockAntes = $this->fotoDelStock();
        $movimientosAntes = $this->totalMovimientosStock();

        $token = $this->iniciarSesion('maria.lopez@revendedor.test');
        $maria = Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();
        $revendedorId = Revendedor::where('usuario_id', $maria->id)->value('id');

        $pedidoId = $this->armarYEnviarPedido($token, 'revendedor', (int) $revendedorId, $variante);

        // Ya enviado: todavía nada.
        $this->assertSame($stockAntes, $this->fotoDelStock());

        $this->llevarHastaEntregadoYCobrar($pedidoId, conAnticipo: false);

        $this->assertSame($stockAntes, $this->fotoDelStock(), 'Un pedido de revendedor modificó stock_local.');
        $this->assertSame($movimientosAntes, $this->totalMovimientosStock(), 'Un pedido de revendedor generó movimientos de stock.');
    }

    /**
     * El código deja la misma regla para cualquier pedido, no solo el de
     * revendedor: un pedido de cliente directo también se surte por ciclo.
     */
    public function test_un_pedido_de_cliente_directo_tampoco_toca_el_stock(): void
    {
        $variante = $this->varianteConExistencia();
        $stockAntes = $this->fotoDelStock();
        $movimientosAntes = $this->totalMovimientosStock();

        $token = $this->iniciarSesion('jose.hernandez@cliente.test');
        $jose = Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail();
        $clienteId = ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $jose->id)->value('id');

        $pedidoId = $this->armarYEnviarPedido($token, 'cliente_directo', (int) $clienteId, $variante);

        $this->assertSame($stockAntes, $this->fotoDelStock());

        $this->llevarHastaEntregadoYCobrar($pedidoId, conAnticipo: true);

        $this->assertSame($stockAntes, $this->fotoDelStock(), 'Un pedido de cliente directo modificó stock_local.');
        $this->assertSame($movimientosAntes, $this->totalMovimientosStock(), 'Un pedido de cliente directo generó movimientos de stock.');
    }

    /**
     * Control: la misma variante SÍ baja con una venta directa. Sin esto, las
     * dos pruebas de arriba pasarían también si la variante elegida no
     * pudiera descontarse por cualquier otro motivo.
     */
    public function test_en_cambio_una_venta_directa_de_esa_misma_variante_si_descuenta(): void
    {
        $variante = $this->varianteConExistencia();

        $stock = StockLocal::withoutGlobalScopes()
            ->where('variante_id', $variante['variante_id'])
            ->where('sucursal_id', $variante['sucursal_id'])
            ->firstOrFail();
        $antes = (int) $stock->cantidad_disponible;

        Sanctum::actingAs(Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();

        $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'efectivo',
            'lineas'      => [[
                'variante_id'         => $variante['variante_id'],
                'producto_campana_id' => $variante['producto_campana_id'],
                'cantidad'            => 1,
            ]],
        ])->assertCreated();

        $this->assertSame($antes - 1, (int) $stock->fresh()->cantidad_disponible);
    }
}
