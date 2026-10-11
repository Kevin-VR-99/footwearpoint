<?php

namespace Tests\Feature\Catalogo;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * E4-11 / TG-137
 * El cupo del plan aplica a líneas (GestionarLineaAction), no a marcas.
 */
class MarcaTest extends TestCase
{
    use CreaAdminGeneral;
    use RefreshDatabase;

    protected bool $seed = true;

    /**
     * Desde TG-213 el catálogo lo escribe solo el admin general: es uno solo
     * para todo FootwearPoint y no pertenece a ninguna distribuidora.
     */
    private function autenticarComoAdmin(): void
    {
        Sanctum::actingAs($this->adminGeneral());
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