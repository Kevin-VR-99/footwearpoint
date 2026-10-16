<?php

namespace Tests\Feature\Directorio;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Las categorías del directorio ya no se usan en el panel ni en la API.
 * Las tablas se conservan; las pantallas y las rutas de administración no.
 */
class CategoriasDirectorioTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    public function test_la_pantalla_de_categorias_regresa_al_admin(): void
    {
        $admin = Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $this->actingAs($admin);

        $this->get(route('admin.categorias-directorio'))
            ->assertRedirect('/admin');
    }

    public function test_la_api_de_categorias_del_directorio_ya_no_existe(): void
    {
        $admin = Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $this->actingAs($admin);

        $this->getJson('/api/admin/categorias-directorio')->assertNotFound();
        $this->postJson('/api/admin/categorias-directorio', ['nombre' => 'Botas'])->assertNotFound();
    }
}
