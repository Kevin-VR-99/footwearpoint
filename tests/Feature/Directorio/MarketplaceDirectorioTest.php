<?php

namespace Tests\Feature\Directorio;

use App\Models\CategoriaDirectorio;
use App\Models\Distribuidora;
use App\Models\Usuario;
use Database\Seeders\AdminGeneralSeeder;
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

        // Desde TG-186 el admin general ya viene del seeder: aqui solo se usa.
        $this->adminGeneral = Usuario::firstOrCreate(
            ['email' => AdminGeneralSeeder::EMAIL],
            [
                'nombre'   => 'Admin General',
                'password' => Hash::make('password'),
                'estado'   => 'activo',
            ]
        );

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

    public function test_el_marketplace_tiene_un_solo_buscador_por_nombre_y_lugar_sin_mostrar_categorias(): void
    {
        $norte = $this->otra('Zapatería Norte', ['Infantil']);
        $norte->update(['direccion_publica' => 'Centro, San Cristóbal de las Casas']);

        $pagina = $this->get(route('marketplace'))
            ->assertOk()
            ->assertSee('Buscar distribuidora')
            ->assertSee('Nombre, ciudad, colonia o dirección')
            ->assertDontSee('Buscar por nombre')
            ->assertDontSee('Buscar por lugar')
            ->assertSee('Volver al inicio')
            ->assertSee('sticky top-[4.125rem]', false)
            ->assertSee('Buscando…')
            ->assertDontSee('Filtrar por categoría')
            ->assertDontSee('Caballero')
            ->assertDontSee('Casual');

        Livewire::test('marketplace.index')
            ->set('q', 'Norte')
            ->assertSee('Zapatería Norte')
            ->assertDontSee('Calzados Ramírez')
            ->set('q', 'Cristóbal')
            ->assertSee('Zapatería Norte')
            ->assertDontSee('Calzados Ramírez')
            // Nombre y lugar en el mismo campo: cada palabra debe coincidir en alguno de los dos.
            ->set('q', 'Norte Centro')
            ->assertSee('Zapatería Norte')
            ->set('q', 'Norte Tuxtla')
            ->assertDontSee('Zapatería Norte')
            ->set('q', 'no-existe-xyz')
            ->assertSee('Ninguna distribuidora coincide con esa búsqueda.');

        $pagina->assertDontSee('Infantil');
    }

    public function test_un_enlace_viejo_con_lugar_llena_el_buscador_unificado(): void
    {
        $norte = $this->otra('Zapatería Norte', ['Infantil']);
        $norte->update(['direccion_publica' => 'Col. Centro, Palenque']);

        Livewire::withQueryParams(['lugar' => 'Palenque'])
            ->test('marketplace.index')
            ->assertSet('q', 'Palenque')
            ->assertSee('Zapatería Norte')
            ->assertDontSee('Calzados Ramírez');
    }

    public function test_el_buscador_unificado_usa_la_sucursal_principal_si_no_hay_direccion_publica(): void
    {
        $norte = $this->otra('Zapatería Norte', ['Infantil']);
        $norte->update(['direccion_publica' => null]);
        \App\Models\Sucursal::withoutGlobalScopes()->updateOrCreate(
            ['distribuidora_id' => $norte->id, 'es_principal' => true],
            ['nombre' => 'Matriz Ocosingo', 'direccion' => 'Barrio de Guadalupe, Ocosingo', 'activa' => true],
        );

        $nombres = app(\App\Services\Directorio\DirectorioPublico::class)
            ->distribuidoras(null, null, null, 'Ocosingo')
            ->pluck('nombre_comercial');

        $this->assertSame(['Zapatería Norte'], $nombres->all());
    }

    public function test_la_pagina_no_filtra_por_categoria_aunque_llegue_en_la_direccion(): void
    {
        $this->otra('Zapatería Norte', ['Infantil', 'Dama']);

        Livewire::withQueryParams(['categoria' => (string) $this->categoria('Infantil')->id])
            ->test('marketplace.index')
            ->assertSee('Calzados Ramírez')
            ->assertSee('Zapatería Norte')
            ->assertDontSee('Infantil');
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

    public function test_cada_tarjeta_muestra_el_logo_y_no_las_categorias(): void
    {
        $this->get(route('marketplace'))
            ->assertOk()
            ->assertSee('Calzados Ramírez')
            ->assertSee('Distribuidora multimarca')
            ->assertSee('h-28 w-28', false)
            ->assertDontSee('Caballero')
            ->assertDontSee('Casual')
            ->assertDontSee('Dama');
    }

    // ---------------------------------------------------------------
    // API pública
    // ---------------------------------------------------------------

    public function test_api_marketplace_ya_no_filtra_por_categoria(): void
    {
        $this->otra('Zapatería Norte', ['Infantil']);

        $this->getJson('/api/marketplace')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nombre_comercial', 'Calzados Ramírez')
            ->assertJsonPath('data.0.categorias', []);

        $this->getJson('/api/marketplace?categoria='.$this->categoria('Infantil')->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_api_categorias_del_directorio_responde_vacio(): void
    {
        $this->otra('Zapatería Norte', ['Infantil', 'Dama']);

        $this->getJson('/api/marketplace/categorias')
            ->assertOk()
            ->assertExactJson(['data' => []]);
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
