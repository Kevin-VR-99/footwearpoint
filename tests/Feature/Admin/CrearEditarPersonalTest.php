<?php

namespace Tests\Feature\Admin;

use App\Models\Auditoria;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use App\Notifications\RestablecerPasswordNotification;
use App\Services\Auth\EnviarEnlaceRestablecerPanelAction;
use App\Services\Auth\RestablecerPasswordAction;
use App\Services\Personal\CrearPersonalAction;
use App\Support\DocumentosLegales;
use App\Support\PersonalPlataforma;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-336 (K38) — El admin general crea y edita cuentas de personal.
 */
class CrearEditarPersonalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CLIENTE = 'jose.hernandez@cliente.test';

    private const MAYORISTA = 'maria.lopez@revendedor.test';

    private const CORREO = 'nueva.persona@tg336.test';

    private const CLAVE_NUEVA = 'Zafiro-Norte-58';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
        Storage::fake('s3');
        $this->limpiarCaches();
    }

    protected function tearDown(): void
    {
        $this->limpiarCaches();
        parent::tearDown();
    }

    private function limpiarCaches(): void
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function cuenta(string $correo): Usuario
    {
        return Usuario::where('email', $correo)->firstOrFail();
    }

    private function comoAdminGeneral(): Usuario
    {
        $admin = $this->cuenta(AdminGeneralSeeder::EMAIL);
        $this->actingAs($admin);
        $this->limpiarCaches();

        return $admin;
    }

    private function ramirez(): int
    {
        return (int) Distribuidora::where('slug', DemoDistribuidoraSeeder::SLUG)->value('id');
    }

    private function alta(array $datos = [])
    {
        $prueba = Livewire::test('admin.usuarios-index')->call('abrirAlta');

        foreach ($datos + [
            'nombre' => 'Nueva Persona',
            'email' => self::CORREO,
            'telefono' => '9610003360',
            'rol' => 'empleado',
            'distribuidora_id' => (string) $this->ramirez(),
            'direccion' => 'Av. Central 336',
        ] as $campo => $valor) {
            $prueba->set($campo, $valor);
        }

        return $prueba->call('guardarAlta');
    }

    private function temporalDe($prueba): string
    {
        $this->assertSame(1, preg_match('/data-password-temporal[^>]*>([^<]+)</', $prueba->html(), $coincide));

        return html_entity_decode($coincide[1]);
    }

    private function nueva(): Usuario
    {
        return $this->cuenta(self::CORREO);
    }

    private function rolesDe(Usuario $usuario): array
    {
        return PersonalPlataforma::rolesDe([(int) $usuario->id])[$usuario->id] ?? [];
    }

    /** Sale de la sesión del admin general, como quien abre otro navegador. */
    private function salir(): void
    {
        Auth::logout();
        $this->app['auth']->forgetGuards();
        $this->limpiarCaches();
    }

    private function entrar(string $correo, string $clave): void
    {
        $this->salir();

        Livewire::test('auth.login')
            ->set('email', $correo)
            ->set('password', $clave)
            ->call('login')
            ->assertHasNoErrors();

        $this->limpiarCaches();
    }

    private function aceptarLegales(Usuario $usuario): void
    {
        foreach (DocumentosLegales::tipos() as $tipo) {
            DocumentosLegales::registrar($usuario, $tipo, '127.0.0.1');
        }
    }

    // ------------------------------------------------------------------
    // Alta por rol
    // ------------------------------------------------------------------

    public function test_alta_de_empleado_con_contrasena_temporal_que_se_ve_una_vez(): void
    {
        $this->comoAdminGeneral();

        $prueba = $this->alta()->assertHasNoErrors();
        $temporal = $this->temporalDe($prueba);
        $usuario = $this->nueva();

        $this->assertTrue(Hash::check($temporal, $usuario->password));
        $this->assertTrue($usuario->debe_cambiar_password);
        $this->assertSame('Av. Central 336', $usuario->direccion);
        $this->assertSame(['empleado'], array_column($this->rolesDe($usuario), 'rol'));
        $this->assertDatabaseHas('distribuidora_staff', [
            'distribuidora_id' => $this->ramirez(),
            'usuario_id' => $usuario->id,
            'tipo' => 'empleado',
            'estado' => 'activo',
        ]);

        $prueba->call('cerrarPasswordTemporal')->assertDontSee($temporal);
        $this->assertStringNotContainsString($temporal, json_encode($prueba->snapshot));
    }

    public function test_alta_de_admin_de_distribuidora(): void
    {
        $this->comoAdminGeneral();

        $this->alta(['rol' => 'admin_distribuidora'])->assertHasNoErrors();
        $usuario = $this->nueva();

        $this->assertSame(['admin_distribuidora'], array_column($this->rolesDe($usuario), 'rol'));
        $this->assertSame('administrador', DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $usuario->id)->value('tipo'));
    }

    public function test_alta_de_admin_general_pide_confirmacion_y_no_guarda_direccion(): void
    {
        $this->comoAdminGeneral();

        Livewire::test('admin.usuarios-index')
            ->call('abrirAlta')
            ->set('rol', 'admin_general')
            ->assertSee('Vas a crear otro admin general.')
            ->assertDontSee('Dirección');

        $this->alta(['rol' => 'admin_general', 'distribuidora_id' => ''])
            ->assertHasErrors(['confirmar_admin_general']);
        $this->assertDatabaseMissing('usuarios', ['email' => self::CORREO]);

        $resultado = app(CrearPersonalAction::class)->ejecutar([
            'nombre' => 'Nueva Persona',
            'email' => self::CORREO,
            'direccion' => 'No debe guardarse',
            'distribuidora_id' => $this->ramirez(),
            'rol' => 'admin_general',
            'acceso' => 'temporal',
            'confirmar_admin_general' => true,
        ]);

        $usuario = $resultado['usuario']->fresh();
        $this->assertNull($usuario->direccion);
        $this->assertSame([['rol' => 'admin_general', 'distribuidora' => null]], array_map(
            fn ($rol) => ['rol' => $rol['rol'], 'distribuidora' => $rol['distribuidora']],
            $this->rolesDe($usuario)
        ));
        $this->assertFalse(DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $usuario->id)->exists());
        $this->assertTrue(PersonalPlataforma::esAdminGeneral($usuario));
    }

    public function test_la_distribuidora_es_obligatoria_y_debe_estar_activa(): void
    {
        $this->comoAdminGeneral();

        $this->alta(['distribuidora_id' => ''])->assertHasErrors(['distribuidora_id']);

        Distribuidora::whereKey($this->ramirez())->update(['estado' => 'suspendida']);
        $this->alta()->assertHasErrors(['distribuidora_id']);

        $this->assertDatabaseMissing('usuarios', ['email' => self::CORREO]);
    }

    public function test_nunca_crea_clientes(): void
    {
        $this->comoAdminGeneral();

        foreach (['revendedor', 'cliente_directo'] as $rol) {
            $this->alta(['rol' => $rol])->assertHasErrors(['rol']);
        }

        $this->assertDatabaseMissing('usuarios', ['email' => self::CORREO]);
    }

    public function test_no_deja_cambiado_el_equipo_de_permisos(): void
    {
        $this->comoAdminGeneral();
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(987);

        $this->alta()->assertHasNoErrors();
        $this->assertSame(987, (int) $registrar->getPermissionsTeamId());

        app(CrearPersonalAction::class)->ejecutar([
            'nombre' => 'Otra Persona',
            'email' => 'otra@tg336.test',
            'rol' => 'admin_general',
            'acceso' => 'enlace',
            'confirmar_admin_general' => true,
        ]);
        $this->assertSame(987, (int) $registrar->getPermissionsTeamId());
    }

    // ------------------------------------------------------------------
    // Primer acceso
    // ------------------------------------------------------------------

    public function test_primer_acceso_obliga_a_cambiar_la_contrasena_temporal(): void
    {
        $this->comoAdminGeneral();
        $temporal = $this->temporalDe($this->alta());
        $usuario = $this->nueva();
        $this->aceptarLegales($usuario);

        $this->entrar(self::CORREO, $temporal);

        $this->get(route('dashboard'))->assertRedirect(route('password.cambiar'));
        $this->get(route('password.cambiar'))->assertOk()->assertSee('Cambia tu contraseña');

        Livewire::test('auth.cambiar-password')
            ->set('password_actual', 'no-es-esta-1')
            ->set('password', self::CLAVE_NUEVA)
            ->set('password_confirmation', self::CLAVE_NUEVA)
            ->call('guardar')
            ->assertHasErrors(['password_actual']);

        Livewire::test('auth.cambiar-password')
            ->set('password_actual', $temporal)
            ->set('password', $temporal)
            ->set('password_confirmation', $temporal)
            ->call('guardar')
            ->assertHasErrors(['password']);

        Livewire::test('auth.cambiar-password')
            ->set('password_actual', $temporal)
            ->set('password', self::CLAVE_NUEVA)
            ->set('password_confirmation', self::CLAVE_NUEVA)
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $usuario->refresh();
        $this->assertFalse($usuario->debe_cambiar_password);
        $this->assertTrue(Hash::check(self::CLAVE_NUEVA, $usuario->password));

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_primero_acepta_los_legales_y_despues_cambia_la_contrasena(): void
    {
        $this->comoAdminGeneral();
        $temporal = $this->temporalDe($this->alta());

        $this->entrar(self::CORREO, $temporal);

        $this->get(route('dashboard'))->assertRedirect(route('legales.aceptar'));
        $this->get(route('legales.aceptar'))->assertOk();

        $this->aceptarLegales($this->nueva());

        $this->get(route('dashboard'))->assertRedirect(route('password.cambiar'));
    }

    public function test_un_admin_general_nuevo_tambien_la_cambia(): void
    {
        $this->comoAdminGeneral();
        $temporal = app(CrearPersonalAction::class)->ejecutar([
            'nombre' => 'Nueva Persona',
            'email' => self::CORREO,
            'rol' => 'admin_general',
            'acceso' => 'temporal',
            'confirmar_admin_general' => true,
        ])['password_temporal'];

        $this->entrar(self::CORREO, $temporal);

        $this->get(route('admin.usuarios'))->assertRedirect(route('password.cambiar'));

        Livewire::test('auth.cambiar-password')
            ->set('password_actual', $temporal)
            ->set('password', self::CLAVE_NUEVA)
            ->set('password_confirmation', self::CLAVE_NUEVA)
            ->call('guardar')
            ->assertRedirect(route('admin.dashboard'));

        $this->get(route('admin.usuarios'))->assertOk();
    }

    public function test_un_cliente_con_temporal_no_llega_a_la_pantalla_del_panel(): void
    {
        $mayorista = $this->cuenta(self::MAYORISTA);
        $mayorista->forceFill(['debe_cambiar_password' => true])->save();
        $this->actingAs($mayorista);
        $this->limpiarCaches();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // Enlace
    // ------------------------------------------------------------------

    public function test_alta_con_enlace_para_definir_la_contrasena(): void
    {
        $this->comoAdminGeneral();

        $prueba = $this->alta(['acceso' => 'enlace'])->assertHasNoErrors()->assertDontSee('Contraseña temporal');
        $usuario = $this->nueva();
        $token = null;

        $this->assertFalse($usuario->debe_cambiar_password);
        Notification::assertSentTo($usuario, RestablecerPasswordNotification::class, function ($aviso) use (&$token) {
            $token = $aviso->token;

            return true;
        });
        $prueba->assertDispatched('guardado');

        $this->assertTrue(app(RestablecerPasswordAction::class)->ejecutar(
            self::CORREO, self::CLAVE_NUEVA, self::CLAVE_NUEVA, $token
        ));
        $this->assertTrue(Hash::check(self::CLAVE_NUEVA, $usuario->fresh()->password));
    }

    public function test_si_falla_el_enlace_la_cuenta_queda_y_se_avisa(): void
    {
        $this->comoAdminGeneral();
        $this->mock(EnviarEnlaceRestablecerPanelAction::class)
            ->shouldReceive('ejecutar')
            ->andThrow(ValidationException::withMessages(['enlace' => ['El correo no salió.']]));

        $this->alta(['acceso' => 'enlace'])
            ->assertHasNoErrors()
            ->assertSee('se creó, pero no se envió el enlace')
            ->assertSee('El correo no salió.');

        $this->assertDatabaseHas('usuarios', ['email' => self::CORREO]);
    }

    // ------------------------------------------------------------------
    // Correo y foto
    // ------------------------------------------------------------------

    public function test_un_correo_ocupado_da_error_y_no_liga_la_cuenta(): void
    {
        $this->comoAdminGeneral();
        $cliente = $this->cuenta(self::CLIENTE);
        $usuarios = Usuario::count();
        $staff = DistribuidoraStaff::withoutGlobalScopes()->count();

        foreach ([self::CLIENTE, strtoupper(self::CLIENTE), DemoDistribuidoraSeeder::EMPLEADO] as $correo) {
            $this->alta(['email' => $correo])->assertHasErrors(['email']);
        }

        $this->assertSame($usuarios, Usuario::count());
        $this->assertSame($staff, DistribuidoraStaff::withoutGlobalScopes()->count());
        $this->assertSame([], $this->rolesDe($cliente));
    }

    public function test_el_correo_debe_tener_dominio_real(): void
    {
        $this->comoAdminGeneral();

        $this->alta(['email' => 'persona@noexiste.invalid'])->assertHasErrors(['email']);
    }

    public function test_alta_con_foto(): void
    {
        $this->comoAdminGeneral();

        $this->alta(['foto' => UploadedFile::fake()->image('foto.png', 200, 200)])->assertHasNoErrors();

        $url = $this->nueva()->foto_url;
        $this->assertNotNull($url);
        Storage::disk('s3')->assertExists(substr($url, strpos($url, 'perfiles/personal/')));
    }

    public function test_una_foto_invalida_no_crea_la_cuenta(): void
    {
        $this->comoAdminGeneral();

        $this->alta(['foto' => UploadedFile::fake()->create('nota.pdf', 20, 'application/pdf')])
            ->assertHasErrors(['foto']);

        $this->assertDatabaseMissing('usuarios', ['email' => self::CORREO]);
        $this->assertSame([], Storage::disk('s3')->allFiles('perfiles'));
    }

    // ------------------------------------------------------------------
    // Edición
    // ------------------------------------------------------------------

    public function test_edita_nombre_telefono_direccion_y_foto(): void
    {
        $this->comoAdminGeneral();
        $this->alta(['foto' => UploadedFile::fake()->image('vieja.png')])->assertHasNoErrors();
        $usuario = $this->nueva();
        $fotoVieja = substr($usuario->foto_url, strpos($usuario->foto_url, 'perfiles/'));
        $correo = $usuario->email;
        $hash = $usuario->password;

        Livewire::test('admin.usuarios-ficha', ['usuario' => $usuario->id])
            ->call('abrirEdicion')
            ->assertSet('nombre', 'Nueva Persona')
            ->set('nombre', 'Persona Editada')
            ->set('telefono', '')
            ->set('direccion', 'Calle Nueva 9')
            ->set('foto', UploadedFile::fake()->image('nueva.png'))
            ->call('guardarEdicion')
            ->assertHasNoErrors()
            ->assertSet('editando', false)
            ->assertSee('Persona Editada')
            ->assertSee('Calle Nueva 9');

        $usuario->refresh();
        $this->assertSame('Persona Editada', $usuario->nombre);
        $this->assertNull($usuario->telefono);
        $this->assertSame('Calle Nueva 9', $usuario->direccion);
        $this->assertSame($correo, $usuario->email);
        $this->assertSame($hash, $usuario->password);
        Storage::disk('s3')->assertMissing($fotoVieja);
        Storage::disk('s3')->assertExists(substr($usuario->foto_url, strpos($usuario->foto_url, 'perfiles/')));
    }

    public function test_el_enlace_editar_abre_el_formulario(): void
    {
        $this->comoAdminGeneral();
        $empleado = $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO);

        $this->get(route('admin.usuarios.ficha', ['usuario' => $empleado->id, 'editar' => 1]))
            ->assertOk()
            ->assertSee('Editar usuario');
    }

    public function test_al_admin_general_no_se_le_guarda_direccion_al_editar(): void
    {
        $admin = $this->comoAdminGeneral();

        Livewire::test('admin.usuarios-ficha', ['usuario' => $admin->id])
            ->call('abrirEdicion')
            ->assertDontSee('Dirección')
            ->set('direccion', 'No debe guardarse')
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $this->assertNull($admin->fresh()->direccion);
    }

    // ------------------------------------------------------------------
    // Auditoría, columna y acceso
    // ------------------------------------------------------------------

    public function test_la_auditoria_queda_sin_distribuidora_y_sin_datos_sensibles(): void
    {
        $admin = $this->comoAdminGeneral();
        $temporal = $this->temporalDe($this->alta());
        $usuario = $this->nueva();

        $alta = Auditoria::withoutGlobalScopes()->where('accion', 'personal.creado')->where('entidad_id', $usuario->id)->sole();
        $this->assertSame($admin->id, $alta->usuario_id);
        $this->assertNull($alta->distribuidora_id);
        $this->assertSame('usuario', $alta->entidad_tipo);
        $this->assertNull($alta->datos_previos);
        $this->assertEquals([
            'nombre' => 'Nueva Persona',
            'email' => self::CORREO,
            'telefono' => '9610003360',
            'direccion' => 'Av. Central 336',
            'rol' => 'empleado',
            'distribuidora_id' => $this->ramirez(),
            'acceso' => 'temporal',
        ], $alta->datos_nuevos);

        Livewire::test('admin.usuarios-ficha', ['usuario' => $usuario->id])
            ->call('abrirEdicion')
            ->set('nombre', 'Persona Editada')
            ->call('guardarEdicion');

        $edicion = Auditoria::withoutGlobalScopes()->where('accion', 'personal.actualizado')->sole();
        $this->assertNull($edicion->distribuidora_id);
        $this->assertEquals(['nombre' => 'Nueva Persona'], $edicion->datos_previos);
        $this->assertEquals(['nombre' => 'Persona Editada'], $edicion->datos_nuevos);

        $registros = Auditoria::withoutGlobalScopes()->where('entidad_id', $usuario->id)->where('entidad_tipo', 'usuario')->get();
        $this->assertTrue($registros->contains('accion', 'password.temporal_generada'));

        $texto = json_encode($registros->map->only(['datos_previos', 'datos_nuevos']));
        $this->assertStringNotContainsString($temporal, $texto);
        $this->assertStringNotContainsString($usuario->fresh()->password, $texto);
        $this->assertStringNotContainsString('password"', $texto);
    }

    public function test_guardar_sin_cambios_no_deja_registro(): void
    {
        $this->comoAdminGeneral();
        $empleado = $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO);

        Livewire::test('admin.usuarios-ficha', ['usuario' => $empleado->id])
            ->call('abrirEdicion')
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $this->assertFalse(Auditoria::withoutGlobalScopes()->where('accion', 'personal.actualizado')->exists());
    }

    public function test_la_direccion_es_opcional_y_no_sale_al_serializar_la_cuenta(): void
    {
        $this->assertTrue(Schema::hasColumn('usuarios', 'direccion'));

        $this->comoAdminGeneral();
        $this->alta(['direccion' => ''])->assertHasNoErrors();
        $this->assertNull($this->nueva()->direccion);

        $empleado = $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO);
        $empleado->forceFill(['direccion' => 'Privada 1'])->save();
        $this->assertArrayNotHasKey('direccion', $empleado->fresh()->toArray());
    }

    public function test_ningun_otro_rol_crea_ni_edita(): void
    {
        $empleado = $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO);

        foreach ([DemoDistribuidoraSeeder::ADMIN, DemoDistribuidoraSeeder::EMPLEADO, self::CLIENTE, self::MAYORISTA] as $correo) {
            $this->actingAs($this->cuenta($correo));
            $this->limpiarCaches();

            $this->get(route('admin.usuarios'))->assertForbidden();
            $this->get(route('admin.usuarios.ficha', ['usuario' => $empleado->id, 'editar' => 1]))->assertForbidden();
            Livewire::test('admin.usuarios-index')->assertForbidden();
            Livewire::test('admin.usuarios-ficha', ['usuario' => $empleado->id])->assertForbidden();
        }

        $this->assertDatabaseMissing('usuarios', ['email' => self::CORREO]);
    }

    public function test_un_cliente_no_se_edita_desde_aqui(): void
    {
        $this->comoAdminGeneral();

        $this->get(route('admin.usuarios.ficha', ['usuario' => $this->cuenta(self::CLIENTE)->id, 'editar' => 1]))
            ->assertNotFound();
    }
}
