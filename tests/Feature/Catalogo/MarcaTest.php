<?php

namespace Tests\Feature\Catalogo;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E4-11 / TG-137
 * El cupo del plan aplica a líneas (GestionarLineaAction), no a marcas.
 */
class MarcaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function autenticarComoAdmin(): void
    {
        $usuario = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        Sanctum::actingAs($usuario);
    }

    public function test_crear_una_marca_no_depende_del_cupo_de_lineas_del_plan(): void
    {
        $this->autenticarComoAdmin();

        $this->postJson('/api/marcas', ['nombre' => 'Marca Sin Tope De Plan'])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Marca Sin Tope De Plan')
            ->assertJsonPath('data.activa', true);

        $this->assertDatabaseHas('marcas', ['nombre' => 'Marca Sin Tope De Plan']);
    }

    public function test_crear_marca_sin_suscripcion_activa_tambien_se_permite(): void
    {
        $this->autenticarComoAdmin();

        $this->postJson('/api/marcas', ['nombre' => 'Marca Sin Plan'])
            ->assertCreated();

        $this->assertDatabaseHas('marcas', ['nombre' => 'Marca Sin Plan']);
    }

    public function test_sin_autenticar_no_se_puede_crear_una_marca(): void
    {
        $this->postJson('/api/marcas', ['nombre' => 'Intento Sin Sesion'])
            ->assertStatus(401);
    }
}