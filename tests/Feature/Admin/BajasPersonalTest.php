<?php

namespace Tests\Feature\Admin;

use App\Mail\CambioCuentaPersonalMail;
use App\Mail\CambioEstadoDistribuidoraMail;
use App\Models\Auditoria;
use App\Models\CajaSesion;
use App\Models\CambioContactoPendiente;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\Auth\AccesoPanelWebService;
use App\Services\Auth\LimiteIntentosLogin;
use App\Services\Distribuidora\ActualizarAdminDistribuidoraAction;
use App\Services\Distribuidora\NotificarCambioEstadoDistribuidoraAction;
use App\Services\Personal\CrearPersonalAction;
use App\Support\PersonalPlataforma;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-338 (K40) — Desactivar, reactivar y borrar cuentas del personal.
 */
class BajasPersonalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CLAVE_ADMIN = 'Clave-Del-Admin-58';

    private const CLIENTE = 'jose.hernandez@cliente.test';

    private const MAYORISTA = 'maria.lopez@revendedor.test';

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        Storage::fake('s3');
        $this->limpiarCaches();

        $this->admin = $this->cuenta(AdminGeneralSeeder::EMAIL);
        $this->admin->forceFill(['password' => Hash::make(self::CLAVE_ADMIN)])->save();
        app(LimiteIntentosLogin::class)->limpiar('confirmar-personal:'.$this->admin->id, null);
        $this->comoAdmin();
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

    private function comoAdmin(): void
    {
        $this->actingAs($this->admin->fresh());
        $this->limpiarCaches();
    }

    private function cuenta(string $correo): Usuario
    {
        return Usuario::where('email', $correo)->firstOrFail();
    }

    private function empleado(): Usuario
    {
        return $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO);
    }

    private function ramirez(): int
    {
        return (int) Distribuidora::where('slug', DemoDistribuidoraSeeder::SLUG)->value('id');
    }

    private function boutique(): int
    {
        return (int) Distribuidora::where('slug', DemoDistribuidoraSeeder::SEGUNDA_SLUG)->value('id');
    }

    private function accion(Usuario $persona, string $tipo, string $clave = self::CLAVE_ADMIN)
    {
        return Livewire::test('admin.usuarios-ficha', ['usuario' => $persona->id])
            ->call('abrirCambio', $tipo)
            ->set('password_confirmacion', $clave)
            ->call('guardarCambio');
    }

    private function darRol(Usuario $usuario, string $rol, int $equipo): void
    {
        $registrar = app(PermissionRegistrar::class);
        $anterior = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($equipo);
        $usuario->unsetRelation('roles');
        $usuario->assignRole(Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web', 'team_id' => $equipo]));
        $usuario->unsetRelation('roles');
        $registrar->setPermissionsTeamId($anterior);
    }

    private function otroAdminGeneral(): Usuario
    {
        $otro = Usuario::create([
            'nombre' => 'Otra Admin',
            'email' => 'otra.admin@tg338.test',
            'password' => Hash::make('Clave-Otra-58'),
            'estado' => 'activo',
        ]);
        $this->darRol($otro, 'admin_general', 0);

        return $otro;
    }

    /** Una cuenta dada de alta desde K38 que nunca entró. */
    private function cuentaNueva(array $datos = []): Usuario
    {
        return app(CrearPersonalAction::class)->ejecutar($datos + [
            'nombre' => 'Nunca Entró',
            'email' => 'nunca.entro@tg338.test',
            'rol' => 'empleado',
            'distribuidora_id' => $this->ramirez(),
            'acceso' => 'enlace',
        ], $datos['foto'] ?? null)['usuario']->fresh();
    }

    // ------------------------------------------------------------------
    // Desactivar
    // ------------------------------------------------------------------

    public function test_desactivar_saca_la_sesion_abierta_del_panel_y_revoca_la_app(): void
    {
        $empleado = $this->empleado();
        $empleado->createToken('celular');
        $recordar = $empleado->remember_token;

        $this->actingAs($empleado);
        $this->limpiarCaches();
        $this->get(route('dashboard'))->assertOk();

        $this->comoAdmin();
        $this->accion($empleado, 'desactivar')->assertHasNoErrors();

        $empleado->refresh();
        $this->assertSame('inactivo', $empleado->estado);
        $this->assertNotSame($recordar, $empleado->remember_token, 'La cookie de recordarme ya no sirve.');
        $this->assertSame(0, $empleado->tokens()->count());
        $this->assertSame('activo', DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $empleado->id)->value('estado'),
            'La fila de personal no se toca.');

        $this->actingAs($empleado);
        $this->limpiarCaches();
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('aviso_acceso', AccesoPanelWebService::MENSAJE_CUENTA_INACTIVA);

        Mail::assertSent(CambioCuentaPersonalMail::class, fn ($mail) => $mail->hasTo($empleado->email)
            && str_contains($mail->mensaje, 'desactivó'));
    }

    public function test_un_admin_general_desactivado_pierde_admin_aunque_tenga_la_sesion_abierta(): void
    {
        $otro = $this->otroAdminGeneral();

        $this->actingAs($otro);
        $this->limpiarCaches();
        $this->get(route('admin.usuarios'))->assertOk();

        $this->comoAdmin();
        $this->accion($otro, 'desactivar')->assertHasNoErrors();

        $this->actingAs($otro->fresh());
        $this->limpiarCaches();
        $this->get(route('admin.usuarios'))->assertRedirect(route('login'));
    }

    public function test_reactivar_le_devuelve_el_acceso(): void
    {
        $empleado = $this->empleado();
        $empleado->forceFill(['estado' => 'inactivo'])->save();

        Livewire::test('admin.usuarios-ficha', ['usuario' => $empleado->id])->assertSee('Reactivar')->assertDontSee('data-cambiar="desactivar"', false);

        $this->accion($empleado, 'reactivar')->assertHasNoErrors();
        $this->assertSame('activo', $empleado->fresh()->estado);

        $this->actingAs($empleado->fresh());
        $this->limpiarCaches();
        $this->get(route('dashboard'))->assertOk();

        Mail::assertSent(CambioCuentaPersonalMail::class, fn ($mail) => str_contains($mail->mensaje, 'reactivó'));
    }

    public function test_la_distribuidora_ve_la_cuenta_desactivada_en_su_lista(): void
    {
        $empleado = $this->empleado();
        $empleado->forceFill(['estado' => 'inactivo'])->save();

        $this->actingAs($this->cuenta(DemoDistribuidoraSeeder::ADMIN));
        $this->limpiarCaches();
        setPermissionsTeamId($this->ramirez());

        $this->get(route('distribuidora.empleados'))->assertOk()->assertSee('Cuenta desactivada');
    }

    // ------------------------------------------------------------------
    // Salvaguardas
    // ------------------------------------------------------------------

    public function test_la_contrasena_incorrecta_no_cambia_nada(): void
    {
        $empleado = $this->empleado();

        foreach (['desactivar', 'borrar'] as $tipo) {
            $this->accion($empleado, $tipo, 'incorrecta-1')->assertHasErrors(['password_confirmacion']);
        }

        $this->assertSame('activo', $empleado->fresh()->estado);
        Mail::assertNothingSent();
    }

    public function test_no_puedes_desactivarte_ni_borrarte(): void
    {
        $this->otroAdminGeneral();

        $this->accion($this->admin, 'desactivar')->assertHasErrors(['baja'])->assertSee('No puedes desactivar tu propia cuenta.');
        $this->accion($this->admin, 'borrar')->assertHasErrors(['baja'])->assertSee('No puedes borrar tu propia cuenta.');

        $this->assertSame('activo', $this->admin->fresh()->estado);
    }

    public function test_no_se_desactiva_ni_borra_al_ultimo_admin_general_activo(): void
    {
        $otro = $this->otroAdminGeneral();
        // Quien hace el cambio sigue siendo admin general, pero no cuenta como activo.
        $this->admin->forceFill(['estado' => 'bloqueado'])->save();

        foreach (['desactivar', 'borrar'] as $tipo) {
            $this->accion($otro, $tipo)->assertHasErrors(['baja'])->assertSee('último admin general activo');
        }

        $this->assertSame('activo', $otro->fresh()->estado);
    }

    public function test_no_se_desactiva_ni_borra_al_unico_admin_activo_de_una_distribuidora(): void
    {
        $admin = $this->cuenta(DemoDistribuidoraSeeder::ADMIN);

        foreach (['desactivar', 'borrar'] as $tipo) {
            $this->accion($admin, $tipo)->assertHasErrors(['baja'])->assertSee('Primero nombra a otro admin.');
        }

        $this->assertSame('activo', $admin->fresh()->estado);
    }

    public function test_no_se_desactiva_con_una_caja_abierta(): void
    {
        $empleado = $this->empleado();

        CajaSesion::withoutGlobalScopes()->create([
            'distribuidora_id' => $this->ramirez(),
            'sucursal_id' => Sucursal::withoutGlobalScopes()->where('distribuidora_id', $this->ramirez())->value('id'),
            'abierta_por_staff_id' => DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $empleado->id)->value('id'),
            'estado' => 'abierta',
            'fondo_inicial' => 0,
            'abierta_at' => now(),
        ]);

        $this->accion($empleado, 'desactivar')->assertHasErrors(['baja'])->assertSee('caja abierta');
        $this->assertSame('activo', $empleado->fresh()->estado);
    }

    public function test_con_acceso_de_cliente_activo_no_se_desactiva_ni_borra(): void
    {
        $empleado = $this->empleado();
        $registro = ClienteDirecto::withoutGlobalScopes()->create([
            'distribuidora_id' => $this->boutique(),
            'usuario_id' => $empleado->id,
            'nombre' => 'Registro como cliente',
            'telefono' => '9610003380',
            'estado' => 'activo',
        ]);

        foreach (['desactivar', 'borrar'] as $tipo) {
            $this->accion($empleado, $tipo)->assertHasErrors(['baja'])->assertSee('le cortaría la app');
        }

        $registro->forceFill(['estado' => 'inactivo'])->save();
        $this->accion($empleado, 'desactivar')->assertHasNoErrors();
        $this->assertSame('inactivo', $empleado->fresh()->estado);
    }

    public function test_con_varias_asignaciones_avisa_de_todas_y_revisa_cada_una(): void
    {
        $empleado = $this->empleado();
        DistribuidoraStaff::withoutGlobalScopes()->create([
            'distribuidora_id' => $this->boutique(),
            'usuario_id' => $empleado->id,
            'tipo' => 'administrador',
            'estado' => 'activo',
            'fecha_alta' => now(),
        ]);
        $this->darRol($empleado, 'admin_distribuidora', $this->boutique());

        Livewire::test('admin.usuarios-ficha', ['usuario' => $empleado->id])
            ->call('abrirCambio', 'desactivar')
            ->assertSee('Empleado en Calzados Ramírez')
            ->assertSee('Admin de distribuidora en Boutique del Calzado');

        // La otra admin de la boutique ya no cuenta: su cuenta está desactivada.
        $this->cuenta(DemoDistribuidoraSeeder::SEGUNDA_ADMIN)->forceFill(['estado' => 'inactivo'])->save();

        $this->accion($empleado, 'desactivar')->assertHasErrors(['baja'])->assertSee('único admin activo de Boutique del Calzado');
    }

    // ------------------------------------------------------------------
    // Borrar
    // ------------------------------------------------------------------

    public function test_una_cuenta_nueva_de_k38_que_nunca_entro_se_borra_completa(): void
    {
        $nueva = $this->cuentaNueva(['foto' => UploadedFile::fake()->image('foto.png')]);
        $ruta = substr($nueva->foto_url, strpos($nueva->foto_url, 'perfiles/'));
        Storage::disk('s3')->assertExists($ruta);

        $nueva->createToken('celular');
        CambioContactoPendiente::create([
            'usuario_id' => $nueva->id, 'tipo' => 'email', 'valor_anterior' => $nueva->email,
            'valor_nuevo' => 'otra@tg338.test', 'expira_en' => now()->addHour(),
        ]);
        $this->assertTrue(DB::table('password_reset_tokens')->where('email', $nueva->email)->exists());
        $alta = Auditoria::withoutGlobalScopes()->where('accion', 'personal.creado')->where('entidad_id', $nueva->id)->sole();

        $this->accion($nueva, 'borrar')->assertHasNoErrors()->assertRedirect(route('admin.usuarios'));

        $this->assertNull(Usuario::find($nueva->id));
        $this->assertFalse(DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $nueva->id)->exists());
        $this->assertFalse(DB::table('model_has_roles')->where('model_id', $nueva->id)->exists());
        $this->assertFalse(DB::table('personal_access_tokens')->where('tokenable_id', $nueva->id)->exists());
        $this->assertFalse(CambioContactoPendiente::query()->where('usuario_id', $nueva->id)->exists());
        $this->assertFalse(DB::table('password_reset_tokens')->where('email', $nueva->email)->exists());
        Storage::disk('s3')->assertMissing($ruta);

        $this->assertNotNull($alta->fresh(), 'Los registros que la mencionan se quedan.');
        Mail::assertSent(CambioCuentaPersonalMail::class, fn ($mail) => $mail->hasTo($nueva->email)
            && str_contains($mail->mensaje, 'borró'));

        $this->get(route('admin.usuarios'))->assertOk();
    }

    public function test_con_historial_no_se_borra_y_sugiere_desactivar(): void
    {
        $empleado = $this->empleado();

        $this->accion($empleado, 'borrar')
            ->assertHasErrors(['baja'])
            ->assertSee('aceptaciones de documentos legales')
            ->assertSee('Desactívala');

        $nueva = $this->cuentaNueva();
        Auditoria::withoutGlobalScopes()->create([
            'usuario_id' => $nueva->id,
            'accion' => 'sesion.inicio_panel',
            'entidad_tipo' => 'usuario',
            'entidad_id' => $nueva->id,
        ]);

        $this->accion($nueva, 'borrar')->assertHasErrors(['baja'])->assertSee('movimientos en la auditoría');

        $this->assertNotNull(Usuario::find($empleado->id));
        $this->assertNotNull(Usuario::find($nueva->id));
    }

    public function test_si_capturo_pedidos_no_se_borra(): void
    {
        $nueva = $this->cuentaNueva();
        $staff = DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $nueva->id)->value('id');
        DB::table('pedidos')->insert([
            'distribuidora_id' => $this->ramirez(),
            'sucursal_id' => Sucursal::withoutGlobalScopes()->where('distribuidora_id', $this->ramirez())->value('id'),
            'folio' => 'TG338-1',
            'tipo' => 'cliente_directo',
            'subtotal' => 0,
            'total' => 0,
            'capturado_por_staff_id' => $staff,
        ]);

        $this->accion($nueva, 'borrar')->assertHasErrors(['baja'])->assertSee('pedidos capturados');
        $this->assertNotNull(Usuario::find($nueva->id));
    }

    // ------------------------------------------------------------------
    // Administradores activos (ajuste 1)
    // ------------------------------------------------------------------

    public function test_un_admin_con_la_cuenta_desactivada_no_recibe_avisos_ni_sale_como_administrador(): void
    {
        $distribuidora = Distribuidora::findOrFail($this->ramirez());
        $admin = $this->cuenta(DemoDistribuidoraSeeder::ADMIN);

        $this->assertSame($admin->id, app(ActualizarAdminDistribuidoraAction::class)->administradorDe($distribuidora)?->id);

        $admin->forceFill(['estado' => 'inactivo'])->save();

        $this->assertNull(app(ActualizarAdminDistribuidoraAction::class)->administradorDe($distribuidora));
        $this->assertNotContains($admin->id, DistribuidoraStaff::withoutGlobalScopes()->administradoresActivos()->pluck('usuario_id')->all());

        app(NotificarCambioEstadoDistribuidoraAction::class)->ejecutar($distribuidora, NotificarCambioEstadoDistribuidoraAction::SUSPENDIDA);
        Mail::assertNotSent(CambioEstadoDistribuidoraMail::class, fn ($mail) => $mail->hasTo($admin->email));
    }

    // ------------------------------------------------------------------
    // Auditoría y acceso
    // ------------------------------------------------------------------

    public function test_la_auditoria_queda_sin_distribuidora_ni_datos_sensibles(): void
    {
        $empleado = $this->empleado();

        $this->accion($empleado, 'desactivar')->assertHasNoErrors();
        $this->accion($empleado->fresh(), 'reactivar')->assertHasNoErrors();
        $nueva = $this->cuentaNueva();
        $hash = $nueva->password;
        $this->accion($nueva, 'borrar')->assertHasNoErrors();

        $registros = Auditoria::withoutGlobalScopes()->whereIn('accion', ['personal.desactivado', 'personal.reactivado', 'personal.borrado'])->get();
        $this->assertCount(3, $registros);

        foreach ($registros as $registro) {
            $this->assertNull($registro->distribuidora_id, $registro->accion);
            $this->assertSame($this->admin->id, $registro->usuario_id);
        }

        $this->assertEquals(['estado' => 'activo'], $registros->firstWhere('accion', 'personal.desactivado')->datos_previos);
        $this->assertEquals(['estado' => 'inactivo'], $registros->firstWhere('accion', 'personal.desactivado')->datos_nuevos);

        $texto = json_encode($registros->map->only(['datos_previos', 'datos_nuevos']));
        $this->assertStringNotContainsString(self::CLAVE_ADMIN, $texto);
        $this->assertStringNotContainsString($hash, $texto);
        $this->assertStringNotContainsString('password', $texto);
        $this->assertStringNotContainsString('remember_token', $texto);
    }

    public function test_ningun_otro_rol_entra(): void
    {
        $empleado = $this->empleado();

        foreach ([DemoDistribuidoraSeeder::ADMIN, DemoDistribuidoraSeeder::EMPLEADO, self::CLIENTE, self::MAYORISTA] as $correo) {
            $this->actingAs($this->cuenta($correo));
            $this->limpiarCaches();

            Livewire::test('admin.usuarios-ficha', ['usuario' => $empleado->id])->assertForbidden();
        }

        $this->assertSame('activo', $empleado->fresh()->estado);
    }

    public function test_un_cliente_no_se_desactiva_desde_aqui(): void
    {
        $this->get(route('admin.usuarios.ficha', $this->cuenta(self::CLIENTE)->id))->assertNotFound();
        $this->assertFalse(PersonalPlataforma::esPersonal((int) $this->cuenta(self::CLIENTE)->id));
    }
}
