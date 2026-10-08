<?php

namespace Tests\Feature\Auth;

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
 * TG-140 — GET /api/auth/me.
 *
 * La app móvil solo guarda el token. Al volver a abrirla le pregunta al
 * servidor de quién es ese token, en vez de confiar en datos guardados en el
 * teléfono que pudieron quedar viejos.
 *
 * Todas las pruebas usan tokens reales sacados del login, no Sanctum::actingAs,
 * porque lo que importa aquí es justo cómo se comporta el token.
 */
class SesionActualTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function iniciarSesion(string $email, string $password = 'password'): string
    {
        $token = $this->postJson('/api/auth/login', [
            'email'    => $email,
            'password' => $password,
        ])->assertOk()->json('data.token');

        $this->olvidarSesionEnMemoria();

        return $token;
    }

    /**
     * Dentro de una misma prueba Laravel recuerda al usuario entre peticiones.
     * En la vida real cada petición llega sola con su token, así que se borra
     * esa memoria para que cada llamada se autentique de verdad por el token.
     */
    private function olvidarSesionEnMemoria(): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function crearRevendedor(string $email, string $estadoAfiliacion = 'activo'): Usuario
    {
        $distribuidora = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();

        $usuario = Usuario::create([
            'nombre'   => 'María López',
            'email'    => $email,
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        $revendedor = Revendedor::create([
            'usuario_id' => $usuario->id,
            'nombre'     => 'María López',
            'email'      => $email,
            'estado'     => 'activo',
        ]);

        RevendedorDistribuidora::create([
            'distribuidora_id' => $distribuidora->id,
            'revendedor_id'    => $revendedor->id,
            'estado'           => $estadoAfiliacion,
            'fecha_alta'       => now(),
        ]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($distribuidora->id);
        $usuario->assignRole('revendedor');
        $registrar->forgetCachedPermissions();

        return $usuario;
    }

    public function test_con_un_token_valido_regresa_lo_mismo_que_el_login(): void
    {
        $this->crearRevendedor('maria.me@revendedor.test');

        $login = $this->postJson('/api/auth/login', [
            'email'    => 'maria.me@revendedor.test',
            'password' => 'password',
        ])->assertOk();

        $this->olvidarSesionEnMemoria();

        $me = $this->withToken($login->json('data.token'))
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->assertSame($login->json('data.usuario'), $me->json('data.usuario'));
        $this->assertSame('revendedor', $me->json('data.rol'));
        $this->assertSame($login->json('data.distribuidora_id'), $me->json('data.distribuidora_id'));
    }

    public function test_no_entrega_un_token_nuevo(): void
    {
        $this->crearRevendedor('maria.sintoken@revendedor.test');
        $token = $this->iniciarSesion('maria.sintoken@revendedor.test');

        $me = $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        $this->assertNull($me->json('data.token'));
        $this->assertSame('revendedor', $me->json('data.rol'));
    }

    public function test_sin_token_responde_401(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_despues_de_cerrar_sesion_el_token_ya_no_sirve(): void
    {
        $this->crearRevendedor('maria.logout@revendedor.test');
        $token = $this->iniciarSesion('maria.logout@revendedor.test');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->olvidarSesionEnMemoria();

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    /**
     * Si un admin desactiva la cuenta después de que la persona inició sesión,
     * su token sigue existiendo. Al abrir la app debe regresarla al login,
     * no dejarla pasar con datos viejos.
     */
    public function test_una_cuenta_desactivada_despues_del_login_responde_401_y_pierde_el_token(): void
    {
        $this->crearRevendedor('maria.bloqueada@revendedor.test');
        $token = $this->iniciarSesion('maria.bloqueada@revendedor.test');

        Usuario::where('email', 'maria.bloqueada@revendedor.test')->update(['estado' => 'bloqueado']);

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
        $this->olvidarSesionEnMemoria();

        Usuario::where('email', 'maria.bloqueada@revendedor.test')->update(['estado' => 'activo']);

        // Aunque la reactiven, ese token ya se revocó: tiene que volver a entrar.
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    /**
     * Un revendedor suspendido conserva una cuenta válida, pero sin
     * distribuidora. Recibe los mismos datos que en el login: distribuidora
     * null, y por TenantScope no ve nada.
     */
    public function test_un_revendedor_suspendido_recibe_distribuidora_null(): void
    {
        $this->crearRevendedor('maria.suspendida@revendedor.test', 'suspendido');

        $token = $this->iniciarSesion('maria.suspendida@revendedor.test');

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.distribuidora_id', null);
    }

    /**
     * Mismo rechazo que el login (TG-93). El login ya no le da token al
     * personal interno, así que aquí se simula un token viejo, sacado antes
     * de esa regla: tampoco debe servir para entrar a la app.
     */
    public function test_un_token_viejo_de_empleado_responde_401_y_se_revoca(): void
    {
        $empleado = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
        $token = $empleado->createToken('token_de_antes')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);

        $this->assertSame(0, $empleado->tokens()->count());
    }
}
