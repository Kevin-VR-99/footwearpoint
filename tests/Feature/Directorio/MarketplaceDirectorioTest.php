<?php

namespace Tests\Feature\Directorio;

use App\Models\CategoriaDirectorio;
use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Services\Distribuidora\AprobarDistribuidoraAction;
use App\Services\Distribuidora\CambiarVisibilidadMarketplaceAction;
use App\Services\Distribuidora\CrearDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-197 (G16) — El directorio público (marketplace) muestra y filtra por
 * categorías, y el admin general decide qué distribuidoras aparecen (E2-05).
 *
 * Datos del seeder: Calzados Ramírez (activa y visible) tiene Caballero,
 * Casual y Dama; las otras tres categorías no tienen distribuidoras.
 */
class MarketplaceDirectorioTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Usuario $adminGeneral;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->adminGeneral = Usuario::create([
            'nombre'   => 'Admin General',
            'email'    => 'admin.general@footwearpoint.test',
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $this->adminGeneral->assignRole('admin_general');
        $registrar->forgetCachedPermissions();
    }

    private function ramirez(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function categoria(string $nombre): CategoriaDirectorio
    {
        return CategoriaDirectorio::where('nombre', $nombre)->firstOrFail();
    }

    /** Otra distribuidora con las categorías indicadas. */
    private function otra(string $nombre, array $categorias, string $estado = 'activa', bool $visible = true): Distribuidora
    {
        $distribuidora = Distribuidora::create([
            'nombre_comercial'    => $nombre,
            'razon_social'        => "{$nombre} S.A. de C.V.",
            'slug'                => str($nombre)->slug()->toString(),
            'estado'              => $estado,
            'marketplace_visible' => $visible,
            'fecha_solicitud'     => now(),
            'fecha_aprobacion'    => now(),
        ]);

        $distribuidora->categoriasDirectorio()->sync(
            CategoriaDirectorio::whereIn('nombre', $categorias)->pluck('id')->all()
        );

        return $distribuidora;
    }

    // ---------------------------------------------------------------
    // Página pública /marketplace
    // ---------------------------------------------------------------

    public function test_muestra_chips_en_orden_alfabetico_solo_de_categorias_con_distribuidoras_visibles(): void
    {
        $this->get(route('marketplace'))
            ->assertOk()
            ->assertSeeInOrder(['Todas', 'Caballero', 'Casual', 'Dama'])
            ->assertDontSee('Infantil')
            ->assertDontSee('Deportivo');
    }

    public function test_filtra_por_categoria(): void
    {
        $this->otra('Zapatería Norte', ['Infantil', 'Dama']);
        $infantil = $this->categoria('Infantil');

        Livewire::test('marketplace.index')
            ->assertSee('Calzados Ramírez')
            ->assertSee('Zapatería Norte')
            ->call('filtrar', $infantil->id)
            ->assertSet('categoria', (string) $infantil->id)
            ->assertSee('Zapatería Norte')
            ->assertDontSee('Calzados Ramírez')
            ->call('filtrar')
            ->assertSet('categoria', '')
            ->assertSee('Calzados Ramírez');

        Livewire::withQueryParams(['categoria' => (string) $this->categoria('Caballero')->id])
            ->test('marketplace.index')
            ->assertSee('Calzados Ramírez')
            ->assertDontSee('Zapatería Norte');

        // Una dirección mal escrita muestra todas, sin error.
        Livewire::withQueryParams(['categoria' => 'abc'])
            ->test('marketplace.index')
            ->assertSee('Calzados Ramírez')
            ->assertSee('Zapatería Norte');
    }

    public function test_una_categoria_inactiva_no_se_muestra_ni_filtra(): void
    {
        $dama = $this->categoria('Dama');
        $dama->update(['activa' => false]);

        Livewire::test('marketplace.index')
            ->assertSeeInOrder(['Caballero', 'Casual'])
            ->assertDontSee('Dama')
            ->call('filtrar', $dama->id)
            ->assertDontSee('Calzados Ramírez')
            ->assertSee('No hay distribuidoras en esta categoría por ahora.');
    }

    public function test_ocultas_suspendidas_y_pendientes_no_aparecen_aunque_tengan_categoria(): void
    {
        $this->otra('Zapatería Oculta', ['Infantil'], 'activa', false);
        $this->otra('Zapatería Suspendida', ['Deportivo'], 'suspendida', true);
        $this->otra('Zapatería Pendiente', ['Escolar'], 'pendiente', false);

        Livewire::test('marketplace.index')
            ->assertDontSee('Zapatería Oculta')
            ->assertDontSee('Zapatería Suspendida')
            ->assertDontSee('Zapatería Pendiente')
            ->assertDontSee('Infantil')
            ->assertDontSee('Deportivo')
            ->assertDontSee('Escolar');
    }

    public function test_cada_tarjeta_muestra_sus_categorias(): void
    {
        $this->get(route('marketplace'))
            ->assertOk()
            ->assertSeeInOrder(['Calzados Ramírez', 'Caballero', 'Casual', 'Dama', 'Distribuidora multimarca']);
    }

    // ---------------------------------------------------------------
    // API pública
    // ---------------------------------------------------------------

    public function test_api_marketplace_trae_categorias_y_filtra(): void
    {
        $this->otra('Zapatería Norte', ['Infantil']);
        $this->categoria('Casual')->update(['activa' => false]);

        $this->getJson('/api/marketplace')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nombre_comercial', 'Calzados Ramírez')
            ->assertJsonPath('data.0.categorias', [
                ['id' => $this->categoria('Caballero')->id, 'nombre' => 'Caballero'],
                ['id' => $this->categoria('Dama')->id, 'nombre' => 'Dama'],
            ]);

        $this->getJson('/api/marketplace?categoria=' . $this->categoria('Infantil')->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre_comercial', 'Zapatería Norte');

        $this->getJson('/api/marketplace?categoria=' . $this->categoria('Casual')->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/marketplace?categoria=abc')
            ->assertStatus(422)
            ->assertJsonPath('errors.categoria.0', 'La categoría no es válida.');
    }

    public function test_api_categorias_del_directorio(): void
    {
        $this->otra('Zapatería Norte', ['Infantil', 'Dama']);
        $this->otra('Zapatería Oculta', ['Escolar'], 'activa', false);

        $this->getJson('/api/marketplace/categorias')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $this->categoria('Caballero')->id, 'nombre' => 'Caballero', 'total_distribuidoras' => 1],
                ['id' => $this->categoria('Casual')->id, 'nombre' => 'Casual', 'total_distribuidoras' => 1],
                ['id' => $this->categoria('Dama')->id, 'nombre' => 'Dama', 'total_distribuidoras' => 2],
                ['id' => $this->categoria('Infantil')->id, 'nombre' => 'Infantil', 'total_distribuidoras' => 1],
            ]]);
    }

    // ---------------------------------------------------------------
    // Mostrar u ocultar una distribuidora (E2-05, primer criterio)
    // ---------------------------------------------------------------

    public function test_el_admin_general_oculta_y_muestra_desde_el_panel(): void
    {
        $this->actingAs($this->adminGeneral);
        $id = $this->ramirez()->id;

        Livewire::test('admin.distribuidoras-index')
            ->call('toggleMarketplace', $id)
            ->assertSet('mensaje', 'Marketplace: «Calzados Ramírez» ahora está oculta.');

        $this->assertFalse($this->ramirez()->marketplace_visible);
        $this->getJson('/api/marketplace')->assertJsonCount(0, 'data');
        $this->getJson('/api/marketplace/categorias')->assertJsonCount(0, 'data');

        Livewire::test('admin.distribuidoras-index')
            ->call('toggleMarketplace', $id)
            ->assertSet('mensaje', 'Marketplace: «Calzados Ramírez» ahora está visible.');

        $this->assertTrue($this->ramirez()->marketplace_visible);
    }

    public function test_solo_una_activa_puede_hacerse_visible(): void
    {
        $this->actingAs($this->adminGeneral);
        $suspendida = $this->otra('Zapatería Suspendida', [], 'suspendida', false);

        Livewire::test('admin.distribuidoras-index')
            ->call('toggleMarketplace', $suspendida->id)
            ->assertSet('mensaje', CambiarVisibilidadMarketplaceAction::MENSAJE_SOLO_ACTIVAS);

        $this->assertFalse($suspendida->fresh()->marketplace_visible);

        // Ocultar sí se puede aunque no esté activa.
        $suspendida->update(['marketplace_visible' => true]);
        Livewire::test('admin.distribuidoras-index')->call('toggleMarketplace', $suspendida->id);
        $this->assertFalse($suspendida->fresh()->marketplace_visible);
    }

    public function test_api_marketplace_config_con_la_misma_regla(): void
    {
        Sanctum::actingAs($this->adminGeneral);
        $suspendida = $this->otra('Zapatería Suspendida', [], 'suspendida', false);

        $this->patchJson('/api/admin/marketplace/config', ['distribuidora_id' => $this->ramirez()->id, 'marketplace_visible' => false])
            ->assertOk()
            ->assertJsonPath('data.marketplace_visible', false)
            ->assertJsonPath('message', 'Configuración de marketplace actualizada.');

        $this->patchJson('/api/admin/marketplace/config', ['distribuidora_id' => $this->ramirez()->id, 'marketplace_visible' => true])
            ->assertOk()
            ->assertJsonPath('data.marketplace_visible', true);

        $this->patchJson('/api/admin/marketplace/config', ['distribuidora_id' => $suspendida->id, 'marketplace_visible' => true])
            ->assertStatus(422)
            ->assertExactJson(['message' => CambiarVisibilidadMarketplaceAction::MENSAJE_SOLO_ACTIVAS]);

        $this->assertFalse($suspendida->fresh()->marketplace_visible);
    }

    public function test_aprobar_no_la_hace_visible_sola(): void
    {
        Mail::fake();

        $pendiente = app(CrearDistribuidoraAction::class)->ejecutar(
            [
                'nombre_comercial'    => 'Zapatería Sur',
                'slug'                => 'zapateria-sur',
                'marketplace_visible' => true,
            ],
            ['nombre' => 'Rosa Díaz', 'email' => 'rosa@zapateriasur.test', 'password' => 'clave-segura-1'],
            false,
        );

        $this->assertFalse($pendiente->fresh()->marketplace_visible);

        app(AprobarDistribuidoraAction::class)->ejecutar($pendiente);

        $this->assertSame('activa', $pendiente->fresh()->estado);
        $this->assertFalse($pendiente->fresh()->marketplace_visible);
    }
}
