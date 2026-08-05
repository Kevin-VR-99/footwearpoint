<?php

namespace Tests\Feature\Stock;

use App\Models\Usuario;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function autenticarComoEmpleado(): void
    {
        $usuario = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
    }

    private function primeraVariante(): Variante
    {
        return Variante::withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    public function test_una_entrada_incrementa_la_existencia_y_deja_movimiento(): void
    {
        $this->autenticarComoEmpleado();
        $variante = $this->primeraVariante();

        $antes = (int) ($this->getJson("/api/stock?variante_id={$variante->id}")
            ->json('data.0.cantidad_disponible') ?? 0);

        $respuesta = $this->postJson('/api/stock/entradas', [
            'variante_id' => $variante->id,
            'cantidad' => 5,
            'motivo' => 'Compra a fábrica',
        ]);

        $respuesta->assertCreated()
            ->assertJsonPath('data.tipo', 'entrada')
            ->assertJsonPath('data.existencia_anterior', $antes)
            ->assertJsonPath('data.existencia_posterior', $antes + 5);

        $this->assertDatabaseHas('stock_local', [
            'variante_id' => $variante->id,
            'cantidad_disponible' => $antes + 5,
        ]);

        $this->assertDatabaseHas('movimientos_stock', [
            'tipo' => 'entrada',
            'cantidad' => 5,
            'existencia_posterior' => $antes + 5,
        ]);
    }

    public function test_un_ajuste_negativo_mayor_que_la_existencia_es_rechazado(): void
    {
        $this->autenticarComoEmpleado();
        $variante = $this->primeraVariante();

        $this->postJson('/api/stock/entradas', [
            'variante_id' => $variante->id,
            'cantidad' => 2,
        ])->assertCreated();

        $this->postJson('/api/stock/ajustes', [
            'variante_id' => $variante->id,
            'tipo' => 'ajuste_negativo',
            'cantidad' => 9999,
            'motivo' => 'Merma',
        ])->assertStatus(409)->assertJsonStructure(['message']);
    }

    public function test_el_ajuste_exige_motivo(): void
    {
        $this->autenticarComoEmpleado();
        $variante = $this->primeraVariante();

        $this->postJson('/api/stock/ajustes', [
            'variante_id' => $variante->id,
            'tipo' => 'ajuste_positivo',
            'cantidad' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('motivo');
    }

    public function test_una_variante_de_otra_distribuidora_responde_404(): void
    {
        $this->autenticarComoEmpleado();

        $this->postJson('/api/stock/entradas', [
            'variante_id' => 999999,
            'cantidad' => 1,
        ])->assertStatus(404);
    }

    public function test_sin_autenticar_no_se_puede_consultar_stock(): void
    {
        $this->getJson('/api/stock')->assertStatus(401);
    }
}