<?php

namespace Tests\Feature\Distribuidora;

use App\Models\Distribuidora;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ver datos abre el mismo tipo de modal que editar, con el logotipo arriba,
 * y borrar quita la distribuidora sin tocar el catálogo compartido.
 */
class VerDatosDistribuidoraTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Usuario $adminGeneral;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->adminGeneral = Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $this->adminGeneral->assignRole('admin_general');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_ver_datos_abre_el_modal_con_foto_y_borrar_pide_confirmacion(): void
    {
        $ramirez = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
        $boutique = Distribuidora::where('slug', 'boutique-del-calzado')->firstOrFail();
        $marcas = Marca::count();
        $productos = Producto::count();
        $ramirez->update(['logotipo_url' => 'https://ejemplo.test/logo-ramirez.png']);

        $this->actingAs($this->adminGeneral);
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        Livewire::test('admin.distribuidoras-index')
            ->call('verDatos', $boutique->id)
            ->assertSee('Datos de la distribuidora')
            ->assertSee('fp-modal', false)
            ->assertSee('fp-logo-entrada', false)
            ->assertSeeHtml('data-sin-logotipo')
            ->assertSee('Boutique del Calzado')
            ->call('verDatos', $ramirez->id)
            ->assertSee('logo-ramirez.png')
            ->assertSee('Logotipo de Calzados Ramírez')
            ->assertSee('Razón social')
            ->assertSee('CRA010101AAA')
            ->assertSee('Borrar definitivamente')
            ->assertDontSee('No se puede deshacer')
            ->call('eliminarDistribuidora');

        $this->assertDatabaseHas('distribuidoras', ['id' => $ramirez->id]);

        Livewire::test('admin.distribuidoras-index')
            ->call('verDatos', $ramirez->id)
            ->call('pedirBorrado')
            ->assertSee('No se puede deshacer')
            ->assertSee('El catálogo compartido se queda')
            ->call('cancelarBorrado')
            ->assertDontSee('No se puede deshacer');

        $this->assertDatabaseHas('distribuidoras', ['id' => $ramirez->id]);

        Livewire::test('admin.distribuidoras-index')
            ->call('verDatos', $ramirez->id)
            ->call('pedirBorrado')
            ->call('eliminarDistribuidora')
            ->assertSet('distribuidoraDetalleId', null)
            ->assertSet('mensaje', 'Distribuidora «Calzados Ramírez» borrada del sistema.')
            ->assertDontSee('calzados-ramirez');

        $this->assertDatabaseMissing('distribuidoras', ['slug' => 'calzados-ramirez']);
        $this->assertDatabaseHas('distribuidoras', ['slug' => 'boutique-del-calzado']);
        $this->assertSame($marcas, Marca::count());
        $this->assertSame($productos, Producto::count());
        $this->assertDatabaseMissing('usuarios', ['email' => 'admin@calzadosramirez.test']);
        $this->assertDatabaseMissing('usuarios', ['email' => 'empleado@calzadosramirez.test']);
        $this->assertDatabaseHas('usuarios', ['email' => AdminGeneralSeeder::EMAIL]);
        $this->assertDatabaseHas('usuarios', ['email' => 'admin@boutiquedelcalzado.test']);
    }

    public function test_quien_no_es_admin_general_no_abre_ver_datos(): void
    {
        $ajena = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        $this->actingAs($ajena);

        Livewire::test('admin.distribuidoras-index')->assertForbidden();
    }
}
