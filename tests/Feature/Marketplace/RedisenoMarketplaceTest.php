<?php

namespace Tests\Feature\Marketplace;

use App\Models\Notificacion;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TG-234 / TG-196: el rediseño carga y muestra lo pedido. */
class RedisenoMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    public function test_el_marketplace_carga_con_logo_y_anio_en_el_pie(): void
    {
        $this->get(route('marketplace'))
            ->assertOk()
            ->assertSee('brand/logo-mark-white-40.png', false)
            ->assertSee('© '.now()->year.' FootwearPoint')
            ->assertSee('Distribuidoras');
    }

    public function test_el_admin_general_ve_el_menu_de_usuario(): void
    {
        $admin = Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-menu-usuario', false)
            ->assertSee('Cerrar sesión')
            ->assertSee('Marketplace público');
    }

    public function test_el_admin_general_tiene_barra_lateral_plegable(): void
    {
        $admin = Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('sidebarOpen: false', false)
            ->assertSee('@keydown.escape.window="sidebarOpen = false"', false)
            ->assertSee('data-admin-sidebar', false)
            ->assertSee("sidebarOpen ? 'w-64' : 'w-0'", false)
            ->assertSee('transition-[width] duration-200 ease-out', false)
            ->assertSee('aria-label="Abrir o cerrar menú"', false)
            ->assertSee('aria-label="Cerrar menú"', false);
    }

    public function test_la_campana_del_panel_muestra_los_grupos(): void
    {
        $admin = Usuario::where('email', DemoDistribuidoraSeeder::ADMIN)->firstOrFail();
        $this->actingAs($admin);
        Notificacion::withoutGlobalScopes()->create([
            'usuario_id' => $admin->id,
            'distribuidora_id' => \App\Models\Distribuidora::where('slug', DemoDistribuidoraSeeder::SLUG)->value('id'),
            'tipo' => 'vale_por_vencer', 'titulo' => 'Vale por vencer', 'mensaje' => 'Tu vale vence pronto',
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-campana', false)
            ->assertSee('Pedidos y entregas')
            ->assertSee('Pagos y vales')
            ->assertSee('Vale por vencer · Tu vale vence pronto')
            ->assertSee('data-menu-usuario', false);

        $this->get(route('notificaciones.index', ['grupo' => 'pagos']))->assertOk();

        $pagos = \Livewire\Livewire::withQueryParams(['grupo' => 'pagos'])->test('notificaciones.index');
        $this->assertSame(['vale_por_vencer'], $pagos->instance()->notificaciones->pluck('tipo')->all());

        $pedidos = \Livewire\Livewire::withQueryParams(['grupo' => 'pedidos'])->test('notificaciones.index');
        $this->assertNotContains('vale_por_vencer', $pedidos->instance()->notificaciones->pluck('tipo')->all());
    }
}
