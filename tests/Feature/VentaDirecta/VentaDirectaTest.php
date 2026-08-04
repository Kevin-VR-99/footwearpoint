<?php

namespace Tests\Feature\VentaDirecta;

use App\Models\ProductoCampana;
use App\Models\StockLocal;
use App\Models\Usuario;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VentaDirectaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function autenticarComoEmpleado(): void
    {
        $usuario = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
    }

    /**
     * Toma una publicación de catálogo del seeder y una variante de su producto.
     *
     * @return array{0: ProductoCampana, 1: Variante}
     */
    private function publicacionConVariante(): array
    {
        $publicacion = ProductoCampana::withoutGlobalScopes()->orderBy('id')->firstOrFail();

        $variante = Variante::withoutGlobalScopes()
            ->where('producto_id', $publicacion->producto_id)
            ->orderBy('id')
            ->firstOrFail();

        return [$publicacion, $variante];
    }

    private function cargarStock(int $varianteId, int $cantidad): void
    {
        $this->postJson('/api/stock/entradas', [
            'variante_id' => $varianteId,
            'cantidad' => $cantidad,
        ])->assertCreated();
    }

    public function test_una_venta_descuenta_stock_y_deja_movimiento_ligado_al_detalle(): void
    {
        $this->autenticarComoEmpleado();
        [$publicacion, $variante] = $this->publicacionConVariante();

        $this->cargarStock($variante->id, 10);

        $respuesta = $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'efectivo',
            'lineas' => [
                ['variante_id' => $variante->id, 'producto_campana_id' => $publicacion->id, 'cantidad' => 3],
            ],
        ]);

        $respuesta->assertCreated()
            ->assertJsonPath('data.estado', 'completada')
            ->assertJsonPath('data.lineas.0.cantidad', 3)
            ->assertJsonStructure([
                'data' => ['folio', 'subtotal', 'descuento', 'total', 'desglose_iva', 'pago', 'lineas'],
                'message',
            ]);

        $esperado = round(3 * (float) $publicacion->precio_minorista_sugerido, 2);
        $this->assertSame($esperado, $respuesta->json('data.total'));

        // El desglose de IVA es aritmético sobre el total, no una columna.
        $base = round($esperado / 1.16, 2);
        $this->assertSame($base, $respuesta->json('data.desglose_iva.base_gravable'));
        $this->assertSame(round($esperado - $base, 2), $respuesta->json('data.desglose_iva.iva'));

        $stock = StockLocal::withoutGlobalScopes()->where('variante_id', $variante->id)->firstOrFail();
        $this->assertSame(7, (int) $stock->cantidad_disponible);

        $this->assertDatabaseHas('movimientos_stock', [
            'tipo' => 'venta',
            'cantidad' => 3,
            'existencia_anterior' => 10,
            'existencia_posterior' => 7,
            'venta_detalle_id' => $respuesta->json('data.lineas.0.id'),
        ]);
    }

    public function test_la_venta_registra_su_pago_de_contado(): void
    {
        $this->autenticarComoEmpleado();
        [$publicacion, $variante] = $this->publicacionConVariante();

        $this->cargarStock($variante->id, 5);

        $respuesta = $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'efectivo',
            'lineas' => [
                ['variante_id' => $variante->id, 'producto_campana_id' => $publicacion->id, 'cantidad' => 1],
            ],
        ])->assertCreated();

        $respuesta
            ->assertJsonPath('data.pago.tipo', 'venta_directa')
            ->assertJsonPath('data.pago.direccion', 'entrada')
            ->assertJsonPath('data.pago.metodo', 'efectivo')
            ->assertJsonPath('data.pago.estado', 'aplicado')
            ->assertJsonPath('data.pago.monto', $respuesta->json('data.total'));

        // chk_pago_origen: la venta directa nunca lleva pedido_id.
        $this->assertDatabaseHas('pagos', [
            'id' => $respuesta->json('data.pago.id'),
            'venta_directa_id' => $respuesta->json('data.id'),
            'pedido_id' => null,
            'tipo' => 'venta_directa',
            'direccion' => 'entrada',
        ]);
    }

    public function test_una_venta_sin_stock_suficiente_es_rechazada_y_no_deja_rastro(): void
    {
        $this->autenticarComoEmpleado();
        [$publicacion, $variante] = $this->publicacionConVariante();

        $this->cargarStock($variante->id, 2);

        $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'efectivo',
            'lineas' => [
                ['variante_id' => $variante->id, 'producto_campana_id' => $publicacion->id, 'cantidad' => 5],
            ],
        ])->assertStatus(409)->assertJsonStructure(['message']);

        // La transacción completa se revirtió: ni venta, ni pago, ni movimiento
        // de venta, ni existencia tocada.
        $this->assertDatabaseCount('ventas_directas', 0);
        $this->assertDatabaseCount('pagos', 0);
        $this->assertDatabaseMissing('movimientos_stock', ['tipo' => 'venta']);

        $stock = StockLocal::withoutGlobalScopes()->where('variante_id', $variante->id)->firstOrFail();
        $this->assertSame(2, (int) $stock->cantidad_disponible);
    }

    public function test_si_una_linea_falla_no_se_descuenta_ninguna_otra(): void
    {
        $this->autenticarComoEmpleado();
        [$publicacion, $primera] = $this->publicacionConVariante();

        $segunda = Variante::withoutGlobalScopes()
            ->where('producto_id', $publicacion->producto_id)
            ->where('id', '!=', $primera->id)
            ->first();

        if ($segunda === null) {
            $this->markTestSkipped('El seeder no dejó dos variantes del mismo producto.');
        }

        $this->cargarStock($primera->id, 10);
        $this->cargarStock($segunda->id, 1);

        $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'efectivo',
            'lineas' => [
                ['variante_id' => $primera->id, 'producto_campana_id' => $publicacion->id, 'cantidad' => 2],
                ['variante_id' => $segunda->id, 'producto_campana_id' => $publicacion->id, 'cantidad' => 9],
            ],
        ])->assertStatus(409);

        $stockPrimera = StockLocal::withoutGlobalScopes()->where('variante_id', $primera->id)->firstOrFail();
        $this->assertSame(10, (int) $stockPrimera->cantidad_disponible);
    }

    public function test_la_misma_variante_repetida_en_dos_lineas_es_rechazada(): void
    {
        $this->autenticarComoEmpleado();
        [$publicacion, $variante] = $this->publicacionConVariante();

        $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'efectivo',
            'lineas' => [
                ['variante_id' => $variante->id, 'producto_campana_id' => $publicacion->id, 'cantidad' => 1],
                ['variante_id' => $variante->id, 'producto_campana_id' => $publicacion->id, 'cantidad' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('lineas.0.variante_id');
    }

    public function test_el_metodo_de_pago_es_obligatorio_y_debe_ser_un_valor_del_enum(): void
    {
        $this->autenticarComoEmpleado();
        [$publicacion, $variante] = $this->publicacionConVariante();

        $linea = [
            ['variante_id' => $variante->id, 'producto_campana_id' => $publicacion->id, 'cantidad' => 1],
        ];

        $this->postJson('/api/ventas-directas', ['lineas' => $linea])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metodo_pago');

        $this->postJson('/api/ventas-directas', ['metodo_pago' => 'paypal', 'lineas' => $linea])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metodo_pago');
    }

    public function test_una_variante_de_otra_distribuidora_responde_404(): void
    {
        $this->autenticarComoEmpleado();
        [$publicacion] = $this->publicacionConVariante();

        $this->postJson('/api/ventas-directas', [
            'metodo_pago' => 'efectivo',
            'lineas' => [
                ['variante_id' => 999999, 'producto_campana_id' => $publicacion->id, 'cantidad' => 1],
            ],
        ])->assertStatus(404);
    }

    public function test_sin_autenticar_no_se_puede_vender(): void
    {
        $this->postJson('/api/ventas-directas', ['metodo_pago' => 'efectivo', 'lineas' => []])
            ->assertStatus(401);
    }
}