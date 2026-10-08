<?php

namespace Tests\Feature\Auth;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-134 — POST /api/auth/login para los roles nuevos.
 *
 * El login resolvía la distribuidora con una búsqueda propia que solo miraba
 * distribuidora_staff. Un revendedor con cuenta iniciaba sesión "bien" pero
 * recibía distribuidora_id y rol en null, así que la app Flutter se quedaba
 * sin nada con que trabajar aunque por dentro el sistema sí lo reconociera.
 *
 * Ahora usa Tenant::paraUsuario(), la misma lógica que el resto del sistema.
 */
class LoginMultiRolTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function distribuidoraA(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function asignarRol(Usuario $usuario, string $rol, int $distribuidoraId): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($distribuidoraId);
        $usuario->assignRole($rol);
        $registrar->forgetCachedPermissions();
    }

    public function test_un_revendedor_inicia_sesion_y_recibe_su_distribuidora_y_su_rol(): void
    {
        $distribuidoraA = $this->distribuidoraA();

        $usuario = Usuario::create([
            'nombre'   => 'María López',
            'email'    => 'maria.login@revendedor.test',
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        $revendedor = Revendedor::create([
            'usuario_id' => $usuario->id,
            'nombre'     => 'María López',
            'email'      => 'maria.login@revendedor.test',
            'estado'     => 'activo',
        ]);

        RevendedorDistribuidora::create([
            'distribuidora_id' => $distribuidoraA->id,
            'revendedor_id'    => $revendedor->id,
            'estado'           => 'activo',
            'fecha_alta'       => now(),
        ]);

        $this->asignarRol($usuario, 'revendedor', $distribuidoraA->id);

        $respuesta = $this->postJson('/api/auth/login', [
            'email'    => 'maria.login@revendedor.test',
            'password' => 'password',
        ])->assertOk();

        $this->assertSame($distribuidoraA->id, $respuesta->json('data.distribuidora_id'));
        $this->assertSame('revendedor', $respuesta->json('data.rol'));
        $this->assertNotEmpty($respuesta->json('data.token'));
    }

    public function test_un_cliente_directo_inicia_sesion_y_recibe_su_distribuidora_y_su_rol(): void
    {
        $distribuidoraA = $this->distribuidoraA();

        $usuario = Usuario::create([
            'nombre'   => 'Ana García',
            'email'    => 'ana.login@cliente.test',
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        ClienteDirecto::create([
            'distribuidora_id' => $distribuidoraA->id,
            'usuario_id'       => $usuario->id,
            'nombre'           => 'Ana García',
            'email'            => 'ana.login@cliente.test',
            'estado'           => 'activo',
        ]);

        $this->asignarRol($usuario, 'cliente_directo', $distribuidoraA->id);

        $respuesta = $this->postJson('/api/auth/login', [
            'email'    => 'ana.login@cliente.test',
            'password' => 'password',
        ])->assertOk();

        $this->assertSame($distribuidoraA->id, $respuesta->json('data.distribuidora_id'));
        $this->assertSame('cliente_directo', $respuesta->json('data.rol'));
    }

    /**
     * TG-93 (E1-01): la API de login es de la app móvil, que es solo para
     * revendedor y cliente directo. El personal interno entra por el panel
     * web (login con sesión, no pasa por este endpoint).
     *
     * Antes de TG-93 esta prueba decía lo contrario: que el empleado seguía
     * entrando igual por la API.
     */
    private function assertLoginRechazadoParaPersonal(string $email, string $password): void
    {
        $this->postJson('/api/auth/login', [
            'email'    => $email,
            'password' => $password,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Esta aplicación es solo para revendedores y clientes directos.')
            ->assertJsonMissingPath('data.token');

        // Se rechaza antes de crear el token: no le queda ninguno válido.
        $usuario = Usuario::where('email', $email)->firstOrFail();
        $this->assertSame(0, $usuario->tokens()->count());
    }

    public function test_un_empleado_no_puede_iniciar_sesion_en_la_app(): void
    {
        $this->assertLoginRechazadoParaPersonal('empleado@calzadosramirez.test', 'password');
    }

    public function test_un_admin_de_distribuidora_no_puede_iniciar_sesion_en_la_app(): void
    {
        $this->assertLoginRechazadoParaPersonal('admin@calzadosramirez.test', 'password');
    }

    public function test_el_admin_general_no_puede_iniciar_sesion_en_la_app(): void
    {
        // DatabaseSeeder no crea al admin general (eso lo hace
        // UsuariosPruebaSeeder, que no corre en las pruebas): se crea aquí
        // igual que ahí, con su rol global en team 0.
        $usuario = Usuario::create([
            'nombre'   => 'Admin General',
            'email'    => 'admin.general.login@footwearpoint.test',
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        $this->asignarRol($usuario, 'admin_general', 0);

        $this->assertLoginRechazadoParaPersonal('admin.general.login@footwearpoint.test', 'password');
    }

    /**
     * Un revendedor suspendido no resuelve distribuidora. Puede autenticarse
     * (su cuenta sigue siendo válida), pero entra sin distribuidora, y el
     * TenantScope endurecido hace que no vea absolutamente nada.
     */
    public function test_un_revendedor_suspendido_inicia_sesion_sin_distribuidora(): void
    {
        $distribuidoraA = $this->distribuidoraA();

        $usuario = Usuario::create([
            'nombre'   => 'Revendedor Suspendido',
            'email'    => 'suspendido.login@revendedor.test',
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        $revendedor = Revendedor::create([
            'usuario_id' => $usuario->id,
            'nombre'     => 'Revendedor Suspendido',
            'email'      => 'suspendido.login@revendedor.test',
            'estado'     => 'activo',
        ]);

        RevendedorDistribuidora::create([
            'distribuidora_id' => $distribuidoraA->id,
            'revendedor_id'    => $revendedor->id,
            'estado'           => 'suspendido',
            'fecha_alta'       => now(),
        ]);

        $this->asignarRol($usuario, 'revendedor', $distribuidoraA->id);

        $respuesta = $this->postJson('/api/auth/login', [
            'email'    => 'suspendido.login@revendedor.test',
            'password' => 'password',
        ])->assertOk();

        $this->assertNull($respuesta->json('data.distribuidora_id'));
    }
}
