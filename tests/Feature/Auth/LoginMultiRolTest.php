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
     * Regresión: el login del personal interno no cambia.
     */
    public function test_un_empleado_sigue_iniciando_sesion_igual(): void
    {
        $respuesta = $this->postJson('/api/auth/login', [
            'email'    => 'empleado@calzadosramirez.test',
            'password' => 'password',
        ])->assertOk();

        $this->assertSame($this->distribuidoraA()->id, $respuesta->json('data.distribuidora_id'));
        $this->assertSame('empleado', $respuesta->json('data.rol'));
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
