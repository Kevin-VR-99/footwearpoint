<?php

namespace Tests\Feature\Seguridad;

use App\Models\Campana;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Auditoría 21-oct-2026: textos largos dan 422 en español, no un 500 de la BD. */
class LimitesDeTextoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function comoAdminGeneral(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        Sanctum::actingAs(Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail());
    }

    public function test_una_descripcion_demasiado_larga_da_422_en_espanol(): void
    {
        $this->comoAdminGeneral();

        $this->postJson('/api/marcas', ['nombre' => 'Marca larga', 'descripcion' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertJsonPath('errors.descripcion.0', 'La descripción no puede tener más de 2000 caracteres.');
    }

    public function test_el_estado_de_la_temporada_no_se_puede_cambiar_por_api(): void
    {
        $this->comoAdminGeneral();
        $campana = Campana::withoutGlobalScopes()->orderBy('id')->firstOrFail();

        // La temporada vigente se decide por fecha; el estado ya no se edita.
        $this->patchJson("/api/campanas/{$campana->id}", ['estado' => 'inventado'])
            ->assertMethodNotAllowed();
        $this->assertDatabaseHas('campanas', ['id' => $campana->id, 'estado' => $campana->estado]);
    }

    public function test_una_contrasena_enorme_se_rechaza_sin_llegar_a_bcrypt(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'empleado@calzadosramirez.test', 'password' => str_repeat('x', 300)])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'La contraseña no puede tener más de 255 caracteres.');

        Livewire::test('auth.login')
            ->set('email', 'empleado@calzadosramirez.test')
            ->set('password', str_repeat('x', 300))
            ->call('login')
            ->assertHasErrors(['password' => 'max']);
    }
}
