<?php

namespace Tests\Feature\Seguridad;

use App\Models\Usuario;
use App\Services\Auth\ConsultarPasswordFiltrada;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La consulta a Have I Been Pwned avisa, pero no impide guardar.
 */
class PasswordFiltracionAvisoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_el_aviso_responde_si_la_contrasena_aparecio(): void
    {
        $password = 'Tenis.Rojo42';
        $hash = strtoupper(sha1($password));
        $prefijo = substr($hash, 0, 5);
        $sufijo = substr($hash, 5);

        Http::fake([
            ConsultarPasswordFiltrada::URL.$prefijo => Http::response($sufijo.':12'."\n".'AAAAA:1', 200),
        ]);

        $this->postJson(route('password.aviso-filtracion'), ['password' => $password])
            ->assertOk()
            ->assertJson(['aparecio' => true]);
    }

    public function test_si_hibp_falla_no_avisa_y_no_revienta(): void
    {
        Http::fake([
            ConsultarPasswordFiltrada::URL.'*' => Http::response('error', 503),
        ]);

        $this->postJson(route('password.aviso-filtracion'), ['password' => 'Tenis.Rojo42'])
            ->assertOk()
            ->assertJson(['aparecio' => false]);
    }

    public function test_una_contrasena_filtrada_pero_valida_si_se_guarda_en_el_alta(): void
    {
        $password = 'Tenis.Rojo42';
        $hash = strtoupper(sha1($password));
        Http::fake([
            ConsultarPasswordFiltrada::URL.substr($hash, 0, 5) => Http::response(substr($hash, 5).':3', 200),
        ]);

        $admin = Usuario::firstOrCreate(
            ['email' => AdminGeneralSeeder::EMAIL],
            ['nombre' => 'Admin General', 'password' => Hash::make('password'), 'estado' => 'activo']
        );
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $admin->assignRole('admin_general');
        $registrar->forgetCachedPermissions();
        $this->actingAs($admin);
        Tenant::olvidarCache();

        Livewire::test('admin.distribuidoras-index')
            ->call('abrirFormularioCrear')
            ->set('nuevo_nombre_comercial', 'Zapatería Norte')
            ->set('admin_nombre', 'Laura Méndez')
            ->set('admin_email', 'laura@zapaterianorte.test')
            ->set('admin_password', $password)
            ->set('admin_password_confirmation', $password)
            ->call('crearDistribuidora')
            ->assertHasNoErrors();

        $usuario = Usuario::where('email', 'laura@zapaterianorte.test')->firstOrFail();
        $this->assertTrue(Hash::check($password, $usuario->password));
    }

    public function test_sigue_rechazando_12345678(): void
    {
        Http::fake();

        $admin = Usuario::firstOrCreate(
            ['email' => AdminGeneralSeeder::EMAIL],
            ['nombre' => 'Admin General', 'password' => Hash::make('password'), 'estado' => 'activo']
        );
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $admin->assignRole('admin_general');
        $registrar->forgetCachedPermissions();
        $this->actingAs($admin);

        Livewire::test('admin.distribuidoras-index')
            ->call('abrirFormularioCrear')
            ->set('nuevo_nombre_comercial', 'Zapatería Norte')
            ->set('admin_nombre', 'Laura Méndez')
            ->set('admin_email', 'laura@zapaterianorte.test')
            ->set('admin_password', '12345678')
            ->set('admin_password_confirmation', '12345678')
            ->call('crearDistribuidora')
            ->assertHasErrors('admin_password');
    }
}
