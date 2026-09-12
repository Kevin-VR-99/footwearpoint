<?php

namespace Tests\Feature\Seguridad;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Marca;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * E17-05 (TG-134) — Acceso multi-rol para revendedor y cliente directo.
 *
 * Antes de este sprint, App\Support\Tenant::id() solo sabía resolver la
 * distribuidora de un empleado o administrador (vía distribuidora_staff).
 * Un revendedor o un cliente directo autenticado devolvía null, y en
 * App\Models\Scopes\TenantScope un tenant null significa "no filtres nada",
 * es decir: ver los datos de TODAS las distribuidoras.
 *
 * Estas pruebas cubren el primer criterio de aceptación de la historia: que
 * el sistema resuelva correctamente la distribuidora de cada tipo de usuario
 * y que un revendedor no vea datos de otra distribuidora.
 *
 * Complementa a AislamientoMultiTenantTest, que cubre lo mismo pero para
 * empleados y a nivel de endpoint HTTP.
 */
class ResolucionTenantMultiRolTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        // El caché de Tenant es estático y sobrevive entre pruebas del mismo
        // proceso. Se limpia para que cada prueba empiece de cero.
        Tenant::olvidarCache();
    }

    private function distribuidoraA(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function crearDistribuidoraB(): Distribuidora
    {
        return Distribuidora::create([
            'nombre_comercial' => 'Zapatería Rival (prueba)',
            'razon_social'     => 'Zapatería Rival S.A. de C.V.',
            'rfc'              => 'ZRI010101' . strtoupper(substr(uniqid(), -3)),
            'slug'             => 'zapateria-rival-' . uniqid(),
            'estado'           => 'activa',
            'fecha_solicitud'  => now(),
            'fecha_aprobacion' => now(),
        ]);
    }

    /**
     * Crea un revendedor con cuenta de acceso propia, afiliado a la
     * distribuidora indicada. Se arma a mano porque el endpoint que hará
     * esto en producción es el de E3-07 (TG-133), que todavía no existe.
     */
    private function crearRevendedorConCuenta(
        int $distribuidoraId,
        string $email,
        string $estadoAfiliacion = 'activo'
    ): Usuario {
        $usuario = Usuario::create([
            'nombre'   => 'Revendedor ' . $email,
            'email'    => $email,
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        $revendedor = Revendedor::create([
            'usuario_id' => $usuario->id,
            'nombre'     => 'Revendedor ' . $email,
            'email'      => $email,
            'estado'     => 'activo',
        ]);

        RevendedorDistribuidora::create([
            'distribuidora_id' => $distribuidoraId,
            'revendedor_id'    => $revendedor->id,
            'estado'           => $estadoAfiliacion,
            'fecha_alta'       => now(),
        ]);

        return $usuario;
    }

    private function crearClienteDirectoConCuenta(int $distribuidoraId, string $email): Usuario
    {
        $usuario = Usuario::create([
            'nombre'   => 'Cliente ' . $email,
            'email'    => $email,
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        ClienteDirecto::create([
            'distribuidora_id' => $distribuidoraId,
            'usuario_id'       => $usuario->id,
            'nombre'           => 'Cliente ' . $email,
            'email'            => $email,
            'estado'           => 'activo',
        ]);

        return $usuario;
    }

    // ------------------------------------------------------------------
    // Resolución de la distribuidora por tipo de usuario
    // ------------------------------------------------------------------

    public function test_un_revendedor_resuelve_la_distribuidora_de_su_afiliacion(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $usuario = $this->crearRevendedorConCuenta($distribuidoraA->id, 'rev.uno@revendedor.test');

        $this->actingAs($usuario);
        Tenant::olvidarCache();

        $this->assertSame($distribuidoraA->id, Tenant::id());
    }

    public function test_un_cliente_directo_resuelve_su_distribuidora(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $usuario = $this->crearClienteDirectoConCuenta($distribuidoraA->id, 'cli.uno@cliente.test');

        $this->actingAs($usuario);
        Tenant::olvidarCache();

        $this->assertSame($distribuidoraA->id, Tenant::id());
    }

    /**
     * Prueba de regresión: el camino que ya funcionaba antes de este cambio
     * (empleado vía distribuidora_staff) tiene que seguir funcionando igual.
     */
    public function test_un_empleado_sigue_resolviendo_su_distribuidora(): void
    {
        $usuario = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();

        $this->actingAs($usuario);
        Tenant::olvidarCache();

        $this->assertSame($this->distribuidoraA()->id, Tenant::id());
    }

    public function test_un_usuario_sin_ningun_vinculo_no_resuelve_distribuidora(): void
    {
        $usuario = Usuario::create([
            'nombre'   => 'Persona suelta',
            'email'    => 'suelto@ninguna.test',
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        $this->actingAs($usuario);
        Tenant::olvidarCache();

        $this->assertNull(Tenant::id());
    }

    // ------------------------------------------------------------------
    // Aislamiento real de datos
    // ------------------------------------------------------------------

    public function test_un_revendedor_solo_ve_las_marcas_de_su_distribuidora(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $distribuidoraB = $this->crearDistribuidoraB();

        $marcaDeA = Tenant::forzar($distribuidoraA->id, fn () => Marca::create([
            'nombre' => 'Marca Propia',
            'activa' => true,
        ]));

        $marcaDeB = Tenant::forzar($distribuidoraB->id, fn () => Marca::create([
            'nombre' => 'Marca Rival',
            'activa' => true,
        ]));

        $usuario = $this->crearRevendedorConCuenta($distribuidoraA->id, 'rev.aislado@revendedor.test');

        $this->actingAs($usuario);
        Tenant::olvidarCache();

        $idsVisibles = Marca::pluck('id');

        // No basta con que no vea la ajena: si el filtro estuviera bloqueando
        // todo por error, la prueba pasaría sin probar nada. Por eso también
        // se confirma que SÍ ve la suya.
        $this->assertContains($marcaDeA->id, $idsVisibles);
        $this->assertNotContains($marcaDeB->id, $idsVisibles);
    }

    public function test_un_cliente_directo_solo_ve_las_marcas_de_su_distribuidora(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $distribuidoraB = $this->crearDistribuidoraB();

        $marcaDeA = Tenant::forzar($distribuidoraA->id, fn () => Marca::create([
            'nombre' => 'Marca Propia',
            'activa' => true,
        ]));

        $marcaDeB = Tenant::forzar($distribuidoraB->id, fn () => Marca::create([
            'nombre' => 'Marca Rival',
            'activa' => true,
        ]));

        $usuario = $this->crearClienteDirectoConCuenta($distribuidoraA->id, 'cli.aislado@cliente.test');

        $this->actingAs($usuario);
        Tenant::olvidarCache();

        $idsVisibles = Marca::pluck('id');

        $this->assertContains($marcaDeA->id, $idsVisibles);
        $this->assertNotContains($marcaDeB->id, $idsVisibles);
    }

    /**
     * Un revendedor afiliado a DOS distribuidoras resuelve siempre la misma,
     * la de menor distribuidora_id, y nunca las dos a la vez.
     *
     * Que este caso no llegue a existir se evita al activar la cuenta
     * (E3-07 / TG-133); esto solo confirma que, si existiera, el
     * comportamiento es predecible y sigue aislado.
     */
    public function test_un_revendedor_afiliado_a_dos_distribuidoras_resuelve_siempre_la_misma(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $distribuidoraB = $this->crearDistribuidoraB();

        $usuario = $this->crearRevendedorConCuenta($distribuidoraA->id, 'rev.doble@revendedor.test');

        RevendedorDistribuidora::create([
            'distribuidora_id' => $distribuidoraB->id,
            'revendedor_id'    => $usuario->revendedor->id,
            'estado'           => 'activo',
            'fecha_alta'       => now(),
        ]);

        $esperada = min($distribuidoraA->id, $distribuidoraB->id);

        $this->actingAs($usuario);
        Tenant::olvidarCache();

        $this->assertSame($esperada, Tenant::id());

        // Y se mantiene estable si se vuelve a preguntar.
        Tenant::olvidarCache();
        $this->assertSame($esperada, Tenant::id());
    }

    // ------------------------------------------------------------------
    // Estados que NO deben resolver distribuidora
    // ------------------------------------------------------------------

    public function test_un_revendedor_suspendido_no_resuelve_distribuidora(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $usuario = $this->crearRevendedorConCuenta(
            $distribuidoraA->id,
            'rev.suspendido@revendedor.test',
            'suspendido'
        );

        $this->actingAs($usuario);
        Tenant::olvidarCache();

        $this->assertNull(Tenant::id());
    }

    public function test_un_cliente_directo_inactivo_no_resuelve_distribuidora(): void
    {
        $distribuidoraA = $this->distribuidoraA();
        $usuario = $this->crearClienteDirectoConCuenta($distribuidoraA->id, 'cli.inactivo@cliente.test');

        ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', $usuario->id)
            ->update(['estado' => 'inactivo']);

        $this->actingAs($usuario);
        Tenant::olvidarCache();

        $this->assertNull(Tenant::id());
    }

    // ------------------------------------------------------------------
    // Roles
    // ------------------------------------------------------------------

    public function test_la_distribuidora_demo_tiene_los_roles_de_la_app_movil(): void
    {
        $distribuidoraA = $this->distribuidoraA();

        foreach (['revendedor', 'cliente_directo'] as $rol) {
            $this->assertTrue(
                Role::where('team_id', $distribuidoraA->id)
                    ->where('name', $rol)
                    ->where('guard_name', 'web')
                    ->exists(),
                "Falta el rol '{$rol}' en la distribuidora demo."
            );
        }
    }
}
