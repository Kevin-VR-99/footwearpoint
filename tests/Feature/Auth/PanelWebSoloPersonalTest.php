<?php

namespace Tests\Feature\Auth;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Auth\AccesoPanelWebService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-184 (A1) — El panel web es solo para el personal.
 *
 * En el recorrido del equipo (29-sep) se entro al panel con la cuenta de un
 * revendedor: el login web no miraba el rol y las rutas del panel solo pedian
 * sesion. Desde ahi se veian los numeros de la distribuidora y sus pedidos.
 *
 * Estas pruebas cubren las dos puertas: el login y las rutas.
 */
class PanelWebSoloPersonalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function distribuidora(): Distribuidora
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

    /** Un revendedor con cuenta de app, afiliado a la distribuidora de prueba. */
    private function crearRevendedor(): Usuario
    {
        $distribuidora = $this->distribuidora();

        $usuario = Usuario::create([
            'nombre'   => 'María López',
            'email'    => 'maria.panel@revendedor.test',
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        $revendedor = Revendedor::create([
            'usuario_id' => $usuario->id,
            'nombre'     => 'María López',
            'email'      => 'maria.panel@revendedor.test',
            'estado'     => 'activo',
        ]);

        RevendedorDistribuidora::create([
            'distribuidora_id' => $distribuidora->id,
            'revendedor_id'    => $revendedor->id,
            'estado'           => 'activo',
            'fecha_alta'       => now(),
        ]);

        $this->asignarRol($usuario, 'revendedor', $distribuidora->id);

        return $usuario;
    }

    private function crearClienteDirecto(): Usuario
    {
        $distribuidora = $this->distribuidora();

        $usuario = Usuario::create([
            'nombre'   => 'José Hernández',
            'email'    => 'jose.panel@cliente.test',
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        ClienteDirecto::create([
            'distribuidora_id' => $distribuidora->id,
            'usuario_id'       => $usuario->id,
            'nombre'           => 'José Hernández',
            'email'            => 'jose.panel@cliente.test',
            'estado'           => 'activo',
        ]);

        $this->asignarRol($usuario, 'cliente_directo', $distribuidora->id);

        return $usuario;
    }

    public function test_un_revendedor_no_puede_entrar_al_panel_desde_el_login_web(): void
    {
        $this->crearRevendedor();

        Livewire::test('auth.login')
            ->set('email', 'maria.panel@revendedor.test')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email')
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_el_mensaje_le_dice_que_entre_por_la_app(): void
    {
        $this->crearRevendedor();

        $componente = Livewire::test('auth.login')
            ->set('email', 'maria.panel@revendedor.test')
            ->set('password', 'password')
            ->call('login');

        $errores = $componente->errors()->get('email');

        $this->assertContains(AccesoPanelWebService::MENSAJE_SOLO_PERSONAL, $errores);
        $this->assertStringContainsString('app FootwearPoint', AccesoPanelWebService::MENSAJE_SOLO_PERSONAL);
    }

    public function test_un_cliente_directo_tampoco_puede_entrar_al_panel(): void
    {
        $this->crearClienteDirecto();

        Livewire::test('auth.login')
            ->set('email', 'jose.panel@cliente.test')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_el_personal_si_entra_al_panel(): void
    {
        // Cuentas del seeder demo: admin y empleado de Calzados Ramírez.
        foreach (['admin@calzadosramirez.test', 'empleado@calzadosramirez.test'] as $correo) {
            Livewire::test('auth.login')
                ->set('email', $correo)
                ->set('password', 'password')
                ->call('login')
                ->assertHasNoErrors()
                ->assertRedirect(route('dashboard'));

            $this->assertAuthenticated();
            $this->post(route('logout'));
        }
    }

    public function test_un_revendedor_con_sesion_no_abre_las_pantallas_del_panel(): void
    {
        $usuario = $this->crearRevendedor();

        foreach (['dashboard', 'stock.index', 'punto-venta.index', 'pedidos.index', 'vales.index', 'reportes.index'] as $ruta) {
            $this->actingAs($usuario)
                ->get(route($ruta))
                ->assertRedirect(route('login'));
        }

        $this->assertGuest();
    }

    public function test_quien_pierde_el_acceso_todavia_puede_cerrar_sesion(): void
    {
        $usuario = $this->crearRevendedor();

        // /logout queda fuera del candado a proposito: si no, quedaria
        // atrapado sin poder salir.
        $this->actingAs($usuario)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_un_empleado_desactivado_no_entra_al_panel(): void
    {
        $usuario = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();

        \App\Models\DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $usuario->id)
            ->update(['estado' => 'inactivo']);

        Livewire::test('auth.login')
            ->set('email', 'empleado@calzadosramirez.test')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    // ---------------------------------------------------------------
    // TG-243: al empleado desactivado se le dice que su cuenta esta
    // desactivada, no que entre por la app como si fuera cliente.
    // ---------------------------------------------------------------

    private function desactivarEmpleadoDemo(): Usuario
    {
        $usuario = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();

        Tenant::forzar($this->distribuidora()->id, fn () => \App\Models\DistribuidoraStaff::query()
            ->where('usuario_id', $usuario->id)
            ->update(['estado' => 'inactivo']));

        return $usuario;
    }

    public function test_el_empleado_desactivado_ve_que_su_cuenta_esta_desactivada_al_iniciar_sesion(): void
    {
        $this->desactivarEmpleadoDemo();

        $errores = Livewire::test('auth.login')
            ->set('email', 'empleado@calzadosramirez.test')
            ->set('password', 'password')
            ->call('login')
            ->errors()
            ->get('email');

        $this->assertContains(AccesoPanelWebService::MENSAJE_CUENTA_DESACTIVADA, $errores);
        $this->assertNotContains(AccesoPanelWebService::MENSAJE_SOLO_PERSONAL, $errores);
        $this->assertStringContainsString('desactivada', AccesoPanelWebService::MENSAJE_CUENTA_DESACTIVADA);
        $this->assertStringContainsString('administrador de tu distribuidora', AccesoPanelWebService::MENSAJE_CUENTA_DESACTIVADA);
        $this->assertGuest();
    }

    public function test_al_empleado_desactivado_con_sesion_abierta_se_le_saca_con_el_mensaje_de_cuenta_desactivada(): void
    {
        $usuario = $this->desactivarEmpleadoDemo();

        $this->actingAs($usuario)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('aviso_acceso', AccesoPanelWebService::MENSAJE_CUENTA_DESACTIVADA);

        $this->assertGuest();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(AccesoPanelWebService::MENSAJE_CUENTA_DESACTIVADA)
            ->assertDontSee(AccesoPanelWebService::MENSAJE_SOLO_PERSONAL);
    }

    public function test_el_revendedor_expulsado_sigue_viendo_que_entre_por_la_app(): void
    {
        $usuario = $this->crearRevendedor();

        $this->actingAs($usuario)
            ->get(route('dashboard'))
            ->assertSessionHas('aviso_acceso', AccesoPanelWebService::MENSAJE_SOLO_PERSONAL);
    }
}
