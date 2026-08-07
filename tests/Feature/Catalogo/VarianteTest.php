<?php

namespace Tests\Feature\Catalogo;

use App\Models\Color;
use App\Models\Producto;
use App\Models\Talla;
use App\Models\Usuario;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VarianteTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function autenticarComoAdmin(): void
    {
        $usuario = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
    }

    public function test_crear_una_variante_genera_su_sku_automatico(): void
    {
        $this->autenticarComoAdmin();
        $producto = Producto::firstOrFail();
        $talla = Talla::firstOrFail();
        $color = Color::firstOrFail();

        $respuesta = $this->postJson('/api/variantes', [
            'producto_id' => $producto->id,
            'talla_id'    => $talla->id,
            'color_id'    => $color->id,
        ])->assertCreated();

        $this->assertNotEmpty($respuesta->json('data.sku'));
        $this->assertDatabaseHas('variantes', [
            'producto_id' => $producto->id,
            'talla_id'    => $talla->id,
            'color_id'    => $color->id,
        ]);
    }

    /**
     * La prueba explícita que pide el documento de tareas (sección 4,
     * Paquete B): "Dos variantes con la misma combinación producto + talla
     * + color son rechazadas (respeta uq_variante_combinacion)."
     *
     * Antes del bugfix en GuardarVarianteRequest, este caso sí se
     * rechazaba, pero con un 500 crudo de base de datos, no un 422 limpio.
     */
    public function test_una_combinacion_producto_talla_color_repetida_es_rechazada(): void
    {
        $this->autenticarComoAdmin();
        $producto = Producto::firstOrFail();
        $talla = Talla::firstOrFail();
        $color = Color::firstOrFail();

        $datos = [
            'producto_id' => $producto->id,
            'talla_id'    => $talla->id,
            'color_id'    => $color->id,
        ];

        $this->postJson('/api/variantes', $datos)->assertCreated();

        // Mismo producto + talla + color otra vez: debe rechazarse limpio.
        $this->postJson('/api/variantes', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors('color_id');

        $this->assertSame(
            1,
            Variante::where('producto_id', $producto->id)
                ->where('talla_id', $talla->id)
                ->where('color_id', $color->id)
                ->count(),
            'No debe haber quedado una segunda fila duplicada.'
        );
    }

    public function test_la_misma_talla_y_color_en_otro_producto_si_se_permite(): void
    {
        $this->autenticarComoAdmin();
        $productos = Producto::take(2)->get();

        if ($productos->count() < 2) {
            $this->markTestSkipped('Se necesitan al menos 2 productos sembrados para esta prueba.');
        }

        $talla = Talla::firstOrFail();
        $color = Color::firstOrFail();

        $this->postJson('/api/variantes', [
            'producto_id' => $productos[0]->id, 'talla_id' => $talla->id, 'color_id' => $color->id,
        ])->assertCreated();

        // Misma talla y color, pero OTRO producto — no debe chocar.
        $this->postJson('/api/variantes', [
            'producto_id' => $productos[1]->id, 'talla_id' => $talla->id, 'color_id' => $color->id,
        ])->assertCreated();
    }

    public function test_sin_autenticar_no_se_puede_crear_una_variante(): void
    {
        $this->postJson('/api/variantes', [
            'producto_id' => 1, 'talla_id' => 1, 'color_id' => 1,
        ])->assertStatus(401);
    }
}
