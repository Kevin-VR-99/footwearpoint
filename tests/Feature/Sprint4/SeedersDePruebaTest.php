<?php

namespace Tests\Feature\Sprint4;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDistribuidoraSeeder;
use Database\Seeders\Support\PasswordDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * TG-186 (Ola 2, K10) — Lo que deja listo `migrate:fresh --seed`.
 *
 * Después de sembrar, la demo tiene que poder arrancar sola: el admin general,
 * las dos distribuidoras de prueba con su personal, y las dos cuentas de la
 * app. Todas con la misma contraseña de prueba.
 */
class SeedersDePruebaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    public function test_quedan_las_cuentas_de_prueba_con_su_rol(): void
    {
        foreach (DatabaseSeeder::CUENTAS as $correo => $rol) {
            $usuario = Usuario::where('email', $correo)->first();

            $this->assertNotNull($usuario, "Falta la cuenta $correo");

            // Se consulta directo: los roles viven por distribuidora (equipo),
            // y aquí solo interesa que la cuenta tenga el suyo.
            $tieneElRol = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $usuario->id)
                ->where('roles.name', $rol)
                ->exists();

            $this->assertTrue($tieneElRol, "La cuenta $correo no tiene el rol $rol");
        }
    }

    public function test_no_hay_cuentas_de_prueba_repetidas(): void
    {
        $correos = array_keys(DatabaseSeeder::CUENTAS);

        $this->assertSame(count($correos), count(array_unique($correos)));
        $this->assertSame(count($correos), Usuario::whereIn('email', $correos)->count());
    }

    /** Las de la app entran por la API, que es como las usa el celular. */
    public function test_el_revendedor_y_el_cliente_entran_a_la_app(): void
    {
        foreach (['maria.lopez@revendedor.test', 'jose.hernandez@cliente.test'] as $correo) {
            $this->app['auth']->forgetGuards();
            Tenant::olvidarCache();

            $this->postJson('/api/auth/login', [
                'email' => $correo,
                'password' => PasswordDePrueba::POR_OMISION,
            ])
                ->assertOk()
                ->assertJsonPath('data.usuario.email', $correo);
        }
    }

    /** Las del personal entran por el panel web. */
    public function test_el_personal_entra_al_panel(): void
    {
        $delPanel = [
            'admin.general@footwearpoint.test',
            DemoDistribuidoraSeeder::ADMIN,
            DemoDistribuidoraSeeder::EMPLEADO,
            DemoDistribuidoraSeeder::SEGUNDA_ADMIN,
        ];

        foreach ($delPanel as $correo) {
            $this->app['auth']->forgetGuards();
            Tenant::olvidarCache();

            Livewire::test('auth.login')
                ->set('email', $correo)
                ->set('password', PasswordDePrueba::POR_OMISION)
                ->call('login')
                ->assertHasNoErrors();

            $this->assertAuthenticated();
            auth()->logout();
        }
    }

    /** El personal conserva su nombre en la cuenta; los contactos, en su registro. */
    public function test_el_personal_tiene_nombre_y_los_contactos_no(): void
    {
        $this->assertNotNull(Usuario::where('email', DemoDistribuidoraSeeder::ADMIN)->value('nombre'));
        $this->assertNull(Usuario::where('email', 'maria.lopez@revendedor.test')->value('nombre'));
        $this->assertSame(
            'María López',
            Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail()->nombreVisible()
        );
    }

    /** En un servidor de verdad no se crean cuentas con una contraseña conocida. */
    public function test_en_produccion_sin_contrasena_configurada_el_seeder_se_detiene(): void
    {
        $this->app['env'] = 'production';

        try {
            PasswordDePrueba::obtener();
            $this->fail('Dejó sembrar cuentas de prueba en producción.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SEED_PASSWORD', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_con_la_variable_configurada_se_usa_esa_contrasena(): void
    {
        putenv('SEED_PASSWORD=otra-clave-de-prueba');

        try {
            $this->assertSame('otra-clave-de-prueba', PasswordDePrueba::obtener());
            $this->assertStringContainsString('SEED_PASSWORD', PasswordDePrueba::comoExplicarla());
        } finally {
            putenv('SEED_PASSWORD');
        }
    }
}
