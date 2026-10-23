<?php

namespace Tests\Feature\Admin;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * INC-001 (QA Aure): la descripción del plan de más de 2000 caracteres se
 * rechazaba sin mostrar el mensaje en el formulario.
 */
class PlanesDescripcionErrorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $admin = Usuario::firstOrCreate(
            ['email' => \Database\Seeders\AdminGeneralSeeder::EMAIL],
            ['nombre' => 'Admin General', 'password' => Hash::make('password'), 'estado' => 'activo']
        );
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $admin->assignRole('admin_general');
        $registrar->forgetCachedPermissions();
        $this->actingAs($admin);
    }

    public function test_muestra_el_error_de_descripcion_demasiado_larga(): void
    {
        Livewire::test('admin.planes-index')
            ->call('nuevo')
            ->set('nombre', 'Plan QA')
            ->set('descripcion', str_repeat('a', 2001))
            ->set('precio_base_mensual', '500')
            ->set('lineas_incluidas', '2')
            ->set('precio_linea_extra', '150')
            ->call('guardar')
            ->assertHasErrors(['descripcion' => 'max'])
            ->assertSee('La descripción no puede tener más de 2000 caracteres.');
    }

    public function test_descripcion_de_2000_caracteres_si_se_acepta(): void
    {
        Livewire::test('admin.planes-index')
            ->call('nuevo')
            ->set('nombre', 'Plan QA 2000')
            ->set('descripcion', str_repeat('a', 2000))
            ->set('precio_base_mensual', '500')
            ->set('lineas_incluidas', '2')
            ->set('precio_linea_extra', '150')
            ->call('guardar')
            ->assertHasNoErrors();
    }

    public function test_los_demas_campos_muestran_su_error(): void
    {
        $c = Livewire::test('admin.planes-index')
            ->call('nuevo')
            ->set('nombre', '')
            ->set('precio_base_mensual', '')
            ->set('lineas_incluidas', '')
            ->set('precio_linea_extra', '')
            ->call('guardar')
            ->assertHasErrors(['nombre', 'precio_base_mensual', 'lineas_incluidas']);

        foreach (['nombre', 'precio_base_mensual', 'lineas_incluidas'] as $campo) {
            $this->assertNotEmpty($c->errors()->first($campo));
            $c->assertSee($c->errors()->first($campo));
        }
    }
}
