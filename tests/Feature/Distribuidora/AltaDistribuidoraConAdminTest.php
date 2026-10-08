<?php

namespace Tests\Feature\Distribuidora;

use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use App\Services\Distribuidora\ProvisionarRolesDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-194 (G2) — Alta de distribuidora con su administrador.
 *
 * Antes el alta desde el panel guardaba distribuidora_staff.tipo = 'admin'
 * (el enum es administrador|empleado) y le asignaba un rol que nadie había
 * creado para esa distribuidora, así que el alta con administrador nunca
 * llegaba a guardarse. Tampoco la aprobación creaba los roles.
 */
class AltaDistribuidoraConAdminTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Usuario $adminGeneral;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        // Desde TG-186 el admin general ya viene del seeder: aqui solo se usa.
        $this->adminGeneral = Usuario::firstOrCreate(
            ['email' => 'admin.general@footwearpoint.test'],
            [
                'nombre'   => 'Admin General',
                'password' => Hash::make('password'),
                'estado'   => 'activo',
            ]
        );

        // El rol admin_general vive fuera de toda distribuidora (equipo 0).
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $this->adminGeneral->assignRole('admin_general');
        $registrar->forgetCachedPermissions();

        $this->actingAs($this->adminGeneral);
    }

    /** El formulario de alta lleno con datos válidos; $cambios pisa lo que haga falta. */
    private function crear(array $cambios = []): Testable
    {
        $componente = Livewire::test('admin.distribuidoras-index')
            ->call('abrirFormularioCrear')
            // Al escribir el nombre se sugieren slug y subdominio.
            ->set('nuevo_nombre_comercial', $cambios['nuevo_nombre_comercial'] ?? 'Zapatería Norte');

        unset($cambios['nuevo_nombre_comercial']);

        $datos = array_merge([
            'nuevo_razon_social' => 'Zapatería Norte S.A. de C.V.',
            'nuevo_rfc'          => '',
            'nuevo_activar_ya'   => true,
            'admin_nombre'       => 'Laura Méndez',
            'admin_email'        => 'laura@zapaterianorte.test',
            'admin_password'     => 'clave-segura-1',
        ], $cambios);

        foreach ($datos as $campo => $valor) {
            $componente->set($campo, $valor);
        }

        return $componente->call('crearDistribuidora');
    }

    private function distribuidora(string $slug = 'zapateria-norte'): Distribuidora
    {
        return Distribuidora::where('slug', $slug)->firstOrFail();
    }

    private function rolesDe(Distribuidora $distribuidora): array
    {
        return Role::where('team_id', $distribuidora->id)->orderBy('name')->pluck('name')->all();
    }

    /** Revisa, dentro del equipo de la distribuidora, que sea su admin con los 5 permisos. */
    private function assertEsAdministradorConPermisos(Usuario $usuario, Distribuidora $distribuidora): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        $registrar->setPermissionsTeamId($distribuidora->id);
        $usuario->unsetRelation('roles')->unsetRelation('permissions');

        $this->assertTrue($usuario->hasRole('admin_distribuidora'), 'No tiene el rol admin_distribuidora');

        foreach (RolesPermissionsSeeder::PERMISOS_POR_MODULO as $permiso) {
            $this->assertTrue($usuario->hasPermissionTo($permiso), "Le falta el permiso $permiso");
        }

        $registrar->setPermissionsTeamId(0);
        $usuario->unsetRelation('roles')->unsetRelation('permissions');
    }

    private function cerrarSesion(): void
    {
        Auth::logout();
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    public function test_crea_la_distribuidora_con_su_admin_y_el_admin_inicia_sesion(): void
    {
        $this->crear(['nuevo_rfc' => 'ZNO010101AB1'])
            ->assertHasNoErrors()
            ->assertSet('mensaje', 'Distribuidora creada correctamente.')
            ->assertSet('mostrandoFormularioCrear', false);

        $distribuidora = $this->distribuidora();
        $this->assertSame('activa', $distribuidora->estado);
        $this->assertSame('ZNO010101AB1', $distribuidora->rfc);

        $admin = Usuario::where('email', 'laura@zapaterianorte.test')->firstOrFail();

        // El bug: se guardaba 'admin', que no existe en el enum.
        $this->assertDatabaseHas('distribuidora_staff', [
            'distribuidora_id' => $distribuidora->id,
            'usuario_id'       => $admin->id,
            'tipo'             => 'administrador',
            'estado'           => 'activo',
        ]);

        $this->assertSame(
            ['admin_distribuidora', 'cliente_directo', 'empleado', 'revendedor'],
            $this->rolesDe($distribuidora)
        );
        $this->assertEsAdministradorConPermisos($admin, $distribuidora);

        // Entra por el login del panel con la cuenta recién creada.
        $this->cerrarSesion();

        Livewire::test('auth.login')
            ->set('email', 'laura@zapaterianorte.test')
            ->set('password', 'clave-segura-1')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
        Tenant::olvidarCache();
        $this->assertSame($distribuidora->id, Tenant::id());

        // Una pantalla que exige role:admin_distribuidora.
        $this->get(route('auditoria.index'))->assertOk();
    }

    public function test_el_administrador_es_obligatorio(): void
    {
        $componente = $this->crear([
            'admin_nombre'   => '',
            'admin_email'    => '',
            'admin_password' => '',
        ])->assertHasErrors([
            'admin_nombre'   => 'required',
            'admin_email'    => 'required',
            'admin_password' => 'required',
        ]);

        $this->assertSame('El correo del administrador es obligatorio.', $componente->errors()->first('admin_email'));
        $this->assertFalse(Distribuidora::where('slug', 'zapateria-norte')->exists());
    }

    public function test_la_contrasena_del_administrador_pide_minimo_8_caracteres(): void
    {
        $this->crear(['admin_password' => 'corta'])
            ->assertHasErrors(['admin_password' => 'min']);

        $this->assertFalse(Distribuidora::where('slug', 'zapateria-norte')->exists());
    }

    public function test_el_correo_del_administrador_no_puede_tener_cuenta(): void
    {
        $componente = $this->crear(['admin_email' => 'admin@calzadosramirez.test'])
            ->assertHasErrors(['admin_email' => 'unique']);

        $this->assertSame('Ese correo ya tiene una cuenta.', $componente->errors()->first('admin_email'));
        $this->assertFalse(Distribuidora::where('slug', 'zapateria-norte')->exists());
    }

    public function test_el_rfc_es_opcional(): void
    {
        $this->crear(['nuevo_rfc' => '   '])->assertHasNoErrors();

        $this->assertNull($this->distribuidora()->rfc);
    }

    #[DataProvider('rfcsInvalidos')]
    public function test_un_rfc_con_formato_invalido_se_rechaza(string $rfc): void
    {
        $componente = $this->crear(['nuevo_rfc' => $rfc])
            ->assertHasErrors(['nuevo_rfc' => 'regex']);

        $this->assertStringStartsWith('El RFC no tiene un formato válido', $componente->errors()->first('nuevo_rfc'));
        $this->assertFalse(Distribuidora::where('slug', 'zapateria-norte')->exists());
    }

    public static function rfcsInvalidos(): array
    {
        return [
            'muy corto'            => ['ABC123'],
            '14 caracteres'        => ['LOPJ800101AB12'],
            'numero en las letras' => ['Z1O010101AB1'],
            'fecha con letras'     => ['ZNO01A101AB1'],
        ];
    }

    public function test_rfc_de_persona_moral_de_12_caracteres(): void
    {
        $this->crear(['nuevo_rfc' => 'ZNO010101AB1'])->assertHasNoErrors();

        $this->assertSame('ZNO010101AB1', $this->distribuidora()->rfc);
    }

    public function test_rfc_de_persona_fisica_de_13_caracteres_se_guarda_en_mayusculas(): void
    {
        $this->crear(['nuevo_rfc' => '  lopj800101ab2 '])->assertHasNoErrors();

        $this->assertSame('LOPJ800101AB2', $this->distribuidora()->rfc);
    }

    public function test_un_rfc_repetido_se_rechaza_aunque_venga_en_minusculas(): void
    {
        // CRA010101AAA es el RFC de la distribuidora demo.
        $componente = $this->crear(['nuevo_rfc' => ' cra010101aaa '])
            ->assertHasErrors(['nuevo_rfc' => 'unique']);

        $this->assertSame('Ese RFC ya está registrado en otra distribuidora.', $componente->errors()->first('nuevo_rfc'));
        $this->assertFalse(Distribuidora::where('slug', 'zapateria-norte')->exists());
    }

    public function test_un_subdominio_repetido_se_rechaza(): void
    {
        $this->crear(['nuevo_subdominio' => 'norte'])->assertHasNoErrors();

        $this->crear([
            'nuevo_nombre_comercial' => 'Zapatería Norte Dos',
            'nuevo_subdominio'       => 'norte',
            'admin_email'            => 'otro@zapaterianorte.test',
        ])->assertHasErrors(['nuevo_subdominio' => 'unique']);

        $this->assertFalse(Distribuidora::where('slug', 'zapateria-norte-dos')->exists());
    }

    public function test_una_pendiente_ya_tiene_roles_y_al_aprobarla_desde_el_panel_queda_activa(): void
    {
        $this->crear(['nuevo_activar_ya' => false])->assertHasNoErrors();

        $distribuidora = $this->distribuidora();
        $admin = Usuario::where('email', 'laura@zapaterianorte.test')->firstOrFail();

        $this->assertSame('pendiente', $distribuidora->estado);
        $this->assertFalse($distribuidora->marketplace_visible);
        $this->assertSame(0, DB::table('suscripciones')->where('distribuidora_id', $distribuidora->id)->count());

        // Los roles se crean desde el alta, aunque quede pendiente.
        $this->assertCount(4, $this->rolesDe($distribuidora));
        $this->assertEsAdministradorConPermisos($admin, $distribuidora);

        Livewire::test('admin.distribuidoras-index')
            ->call('aprobar', $distribuidora->id)
            ->assertSet('mensaje', "Distribuidora «Zapatería Norte» aprobada.");

        $distribuidora->refresh();
        $this->assertSame('activa', $distribuidora->estado);
        $this->assertNotNull($distribuidora->fecha_aprobacion);
        $this->assertSame(1, DB::table('suscripciones')->where('distribuidora_id', $distribuidora->id)->where('estado', 'activa')->count());
        $this->assertSame(1, DB::table('sucursales')->where('distribuidora_id', $distribuidora->id)->count());

        // Aprobar no duplica roles.
        $this->assertSame(
            ['admin_distribuidora', 'cliente_directo', 'empleado', 'revendedor'],
            $this->rolesDe($distribuidora)
        );
        $this->assertEsAdministradorConPermisos($admin, $distribuidora);

        // Una que ya está activa no se vuelve a aprobar.
        Livewire::test('admin.distribuidoras-index')
            ->call('aprobar', $distribuidora->id)
            ->assertSet('mensaje', 'Solo se pueden aprobar distribuidoras pendientes.');
    }

    /**
     * Una solicitud de antes de TG-194: pendiente, con su administrador en
     * distribuidora_staff pero sin roles creados.
     */
    public function test_aprobar_por_api_crea_los_roles_y_le_da_el_rol_al_administrador(): void
    {
        $distribuidora = Distribuidora::create([
            'nombre_comercial' => 'Calzado Antiguo',
            'slug'             => 'calzado-antiguo',
            'estado'           => 'pendiente',
            'fecha_solicitud'  => now(),
        ]);

        $admin = Usuario::create([
            'nombre'   => 'Pedro Ruiz',
            'email'    => 'pedro@calzadoantiguo.test',
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);

        Tenant::forzar($distribuidora->id, fn () => DistribuidoraStaff::create([
            'distribuidora_id' => $distribuidora->id,
            'usuario_id'       => $admin->id,
            'tipo'             => 'administrador',
            'estado'           => 'activo',
            'fecha_alta'       => now(),
        ]));

        $this->assertSame([], $this->rolesDe($distribuidora));

        Sanctum::actingAs($this->adminGeneral);

        $this->postJson("/api/admin/distribuidoras/{$distribuidora->id}/aprobar")
            ->assertOk()
            ->assertJsonPath('message', 'Distribuidora aprobada correctamente.')
            ->assertJsonPath('data.id', $distribuidora->id)
            ->assertJsonPath('data.estado', 'activa');

        $this->assertSame(
            ['admin_distribuidora', 'cliente_directo', 'empleado', 'revendedor'],
            $this->rolesDe($distribuidora)
        );
        $this->assertEsAdministradorConPermisos($admin, $distribuidora);

        $this->postJson("/api/admin/distribuidoras/{$distribuidora->id}/aprobar")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Solo se pueden aprobar distribuidoras en estado pendiente.');
    }

    /** Antes fallaba: el rol empleado no existía en una distribuidora nueva. */
    public function test_el_admin_nuevo_puede_registrar_a_su_primer_empleado(): void
    {
        $this->crear()->assertHasNoErrors();

        $distribuidora = $this->distribuidora();

        $this->cerrarSesion();
        $this->actingAs(Usuario::where('email', 'laura@zapaterianorte.test')->firstOrFail());
        app(PermissionRegistrar::class)->setPermissionsTeamId($distribuidora->id);

        Livewire::test('distribuidora.usuarios')
            ->call('abrirFormularioInvitarEmpleado')
            ->set('empleado_nombre', 'Mario Pérez')
            ->set('empleado_email', 'mario@zapaterianorte.test')
            ->set('empleado_password', 'clave-segura-2')
            ->set('empleado_password_confirmation', 'clave-segura-2')
            ->call('guardarEmpleado')
            ->assertHasNoErrors();

        $empleado = Usuario::where('email', 'mario@zapaterianorte.test')->firstOrFail();

        $this->assertDatabaseHas('distribuidora_staff', [
            'distribuidora_id' => $distribuidora->id,
            'usuario_id'       => $empleado->id,
            'tipo'             => 'empleado',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($distribuidora->id);
        $empleado->unsetRelation('roles');
        $this->assertTrue($empleado->hasRole('empleado'));
    }

    public function test_provisionar_roles_es_idempotente_y_no_toca_otras_distribuidoras(): void
    {
        $this->crear()->assertHasNoErrors();

        $distribuidora = $this->distribuidora();
        $demo = $this->distribuidora('calzados-ramirez');
        $rolesDemoAntes = $this->rolesDe($demo);

        $provisionar = app(ProvisionarRolesDistribuidoraAction::class);
        $provisionar->ejecutar($distribuidora);
        $provisionar->ejecutar($distribuidora);

        $this->assertSame(4, Role::where('team_id', $distribuidora->id)->count());

        $rolAdmin = Role::where('team_id', $distribuidora->id)->where('name', 'admin_distribuidora')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            RolesPermissionsSeeder::PERMISOS_POR_MODULO,
            $rolAdmin->permissions()->pluck('name')->all()
        );

        // R4 todavía no le da permisos al empleado.
        $rolEmpleado = Role::where('team_id', $distribuidora->id)->where('name', 'empleado')->firstOrFail();
        $this->assertSame(0, $rolEmpleado->permissions()->count());

        // Asignar al administrador otra vez no duplica la asignación.
        $admin = Usuario::where('email', 'laura@zapaterianorte.test')->firstOrFail();
        $provisionar->asignarAdministrador($admin, $distribuidora);
        $this->assertSame(1, DB::table('model_has_roles')
            ->where('model_id', $admin->id)
            ->where('role_id', $rolAdmin->id)
            ->where('team_id', $distribuidora->id)
            ->count());

        // La distribuidora demo se queda como estaba.
        $this->assertSame($rolesDemoAntes, $this->rolesDe($demo));

        // Y el equipo de quien llama (admin general, equipo 0) se restaura.
        $this->assertSame(0, app(PermissionRegistrar::class)->getPermissionsTeamId());
    }
}
