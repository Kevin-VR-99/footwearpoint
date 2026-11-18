<?php

namespace Tests\Feature\Admin;

use App\Mail\AvisoCambioCorreoMail;
use App\Mail\CambioCuentaPersonalMail;
use App\Mail\ConfirmarCambioContactoMail;
use App\Models\Auditoria;
use App\Models\CajaSesion;
use App\Models\CambioContactoPendiente;
use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Revendedor;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Notifications\RestablecerPasswordNotification;
use App\Services\Auth\LimiteIntentosLogin;
use App\Services\Distribuidora\ProvisionarRolesDistribuidoraAction;
use App\Services\Personal\CambiarRolPersonalAction;
use App\Support\PersonalPlataforma;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-337 (K39) — Correo, rol y contraseña del personal, con la contraseña del
 * admin general.
 */
class CorreoRolPasswordPersonalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CLAVE_ADMIN = 'Clave-Del-Admin-58';

    private const CLIENTE = 'jose.hernandez@cliente.test';

    private const MAYORISTA = 'maria.lopez@revendedor.test';

    private const CORREO_NUEVO = 'correo.nuevo@tg337.test';

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        $this->limpiarCaches();

        $this->admin = $this->cuenta(AdminGeneralSeeder::EMAIL);
        $this->admin->forceFill(['password' => Hash::make(self::CLAVE_ADMIN)])->save();
        app(LimiteIntentosLogin::class)->limpiar('confirmar-personal:'.$this->admin->id, null);
        $this->actingAs($this->admin);
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

    private function empleado(): Usuario
    {
        return $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO);
    }

    private function adminDistribuidora(): Usuario
    {
        return $this->cuenta(DemoDistribuidoraSeeder::ADMIN);
    }

    private function ramirez(): int
    {
        return (int) Distribuidora::where('slug', DemoDistribuidoraSeeder::SLUG)->value('id');
    }

    private function boutique(): int
    {
        return (int) Distribuidora::where('slug', DemoDistribuidoraSeeder::SEGUNDA_SLUG)->value('id');
    }

    private function rolesDe(Usuario $usuario): array
    {
        return array_map(
            fn (array $r) => $r['rol'].'|'.$r['equipo'],
            PersonalPlataforma::rolesDe([(int) $usuario->id])[$usuario->id] ?? []
        );
    }

    private function fila(Usuario $usuario, int $distribuidoraId): ?DistribuidoraStaff
    {
        return DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $usuario->id)
            ->where('distribuidora_id', $distribuidoraId)
            ->first();
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
            'email' => 'otra.admin@tg337.test',
            'password' => Hash::make('Clave-Otra-58'),
            'estado' => 'activo',
        ]);
        $this->darRol($otro, 'admin_general', 0);

        return $otro;
    }

    private function otroAdminEn(int $distribuidoraId): Usuario
    {
        $otro = Usuario::create([
            'nombre' => 'Otro Admin Distribuidora',
            'email' => 'otro.admin.'.$distribuidoraId.'@tg337.test',
            'password' => Hash::make('Clave-Otro-58'),
            'estado' => 'activo',
        ]);
        DistribuidoraStaff::withoutGlobalScopes()->create([
            'distribuidora_id' => $distribuidoraId,
            'usuario_id' => $otro->id,
            'tipo' => 'administrador',
            'estado' => 'activo',
            'fecha_alta' => now(),
        ]);
        $this->darRol($otro, 'admin_distribuidora', $distribuidoraId);

        return $otro;
    }

    private function ficha(Usuario $persona)
    {
        return Livewire::test('admin.usuarios-ficha', ['usuario' => $persona->id]);
    }

    private function cambioDeRol(Usuario $persona, string $asignacion, string $nuevo, array $extra = [], string $clave = self::CLAVE_ADMIN)
    {
        $prueba = $this->ficha($persona)->call('abrirCambio', 'rol')
            ->set('asignacion', $asignacion)
            ->set('rol_nuevo', $nuevo);

        foreach ($extra as $campo => $valor) {
            $prueba->set($campo, $valor);
        }

        return $prueba->set('password_confirmacion', $clave)->call('guardarCambio');
    }

    private function asignacionEmpleado(): string
    {
        return 'empleado|'.$this->ramirez();
    }

    private function asignacionAdmin(): string
    {
        return 'admin_distribuidora|'.$this->ramirez();
    }

    // ------------------------------------------------------------------
    // Confirmación con la contraseña del admin
    // ------------------------------------------------------------------

    public function test_cada_cambio_exige_la_contrasena_correcta(): void
    {
        $empleado = $this->empleado();
        $correo = $empleado->email;
        $hash = $empleado->password;

        $this->ficha($empleado)->call('abrirCambio', 'correo')
            ->set('correo_nuevo', self::CORREO_NUEVO)
            ->set('password_confirmacion', 'incorrecta-1')
            ->call('guardarCambio')
            ->assertHasErrors(['password_confirmacion'])
            ->assertSet('password_confirmacion', '');

        $this->cambioDeRol($empleado, $this->asignacionEmpleado(), 'admin_distribuidora', [], 'incorrecta-1')
            ->assertHasErrors(['password_confirmacion']);

        $this->ficha($empleado)->call('abrirCambio', 'password')
            ->set('password_confirmacion', 'incorrecta-1')
            ->call('guardarCambio')
            ->assertHasErrors(['password_confirmacion']);

        $empleado->refresh();
        $this->assertSame($correo, $empleado->email);
        $this->assertSame($hash, $empleado->password);
        $this->assertSame([$this->asignacionEmpleado()], $this->rolesDe($empleado));
        $this->assertFalse(CambioContactoPendiente::query()->exists());
        Mail::assertNothingSent();
    }

    public function test_tras_varios_intentos_fallidos_se_bloquea_aunque_luego_sea_correcta(): void
    {
        $empleado = $this->empleado();

        for ($i = 0; $i < LimiteIntentosLogin::INTENTOS_MAXIMOS; $i++) {
            $this->cambioDeRol($empleado, $this->asignacionEmpleado(), 'admin_distribuidora', [], 'incorrecta-1')
                ->assertHasErrors(['password_confirmacion']);
        }

        $this->cambioDeRol($empleado, $this->asignacionEmpleado(), 'admin_distribuidora')
            ->assertHasErrors(['password_confirmacion'])
            ->assertSee('Demasiados intentos fallidos');

        $this->assertSame([$this->asignacionEmpleado()], $this->rolesDe($empleado));
    }

    // ------------------------------------------------------------------
    // Correo
    // ------------------------------------------------------------------

    public function test_cambio_de_correo_con_confirmacion_y_aviso(): void
    {
        $empleado = $this->empleado();
        $anterior = $empleado->email;

        $this->ficha($empleado)->call('abrirCambio', 'correo')
            ->set('correo_nuevo', self::CORREO_NUEVO)
            ->set('password_confirmacion', self::CLAVE_ADMIN)
            ->call('guardarCambio')
            ->assertHasNoErrors()
            ->assertSee('Por confirmar: '.self::CORREO_NUEVO);

        $this->assertSame($anterior, $empleado->fresh()->email);

        $url = null;
        Mail::assertSent(ConfirmarCambioContactoMail::class, function ($mail) use (&$url) {
            $url = $mail->url;

            return $mail->hasTo(self::CORREO_NUEVO) && $mail->porAdministrador;
        });
        Mail::assertSent(AvisoCambioCorreoMail::class, fn ($mail) => $mail->hasTo($anterior) && $mail->porAdministrador);

        $this->get($url)->assertOk()->assertSee('Desde ahora entras al panel con él.');

        $this->assertSame(self::CORREO_NUEVO, $empleado->fresh()->email);
        $this->assertNotNull($empleado->fresh()->email_verified_at);

        foreach (['personal.correo_solicitado', 'personal.correo_cambiado'] as $accion) {
            $registro = Auditoria::withoutGlobalScopes()->where('accion', $accion)->where('entidad_id', $empleado->id)->sole();
            $this->assertNull($registro->distribuidora_id, $accion);
            $this->assertEquals(['email' => $anterior], $registro->datos_previos);
            $this->assertEquals(['email' => self::CORREO_NUEVO], $registro->datos_nuevos);
        }
    }

    public function test_la_confirmacion_no_se_guarda_con_la_distribuidora_de_quien_abre_el_enlace(): void
    {
        $empleado = $this->empleado();

        $this->ficha($empleado)->call('abrirCambio', 'correo')
            ->set('correo_nuevo', self::CORREO_NUEVO)
            ->set('password_confirmacion', self::CLAVE_ADMIN)
            ->call('guardarCambio');

        $cambio = CambioContactoPendiente::query()->sole();
        $this->actingAs($empleado);
        $this->limpiarCaches();

        $this->get(URL::temporarySignedRoute('perfil.confirmar-cambio', now()->addHour(), ['cambio' => $cambio->id]))->assertOk();

        $this->assertNull(Auditoria::withoutGlobalScopes()->where('accion', 'personal.correo_cambiado')->sole()->distribuidora_id);
    }

    public function test_un_correo_ocupado_no_se_acepta(): void
    {
        $empleado = $this->empleado();

        foreach ([self::CLIENTE, strtoupper(DemoDistribuidoraSeeder::ADMIN), $empleado->email] as $correo) {
            $this->ficha($empleado)->call('abrirCambio', 'correo')
                ->set('correo_nuevo', $correo)
                ->set('password_confirmacion', self::CLAVE_ADMIN)
                ->call('guardarCambio')
                ->assertHasErrors(['email']);
        }

        $this->assertFalse(CambioContactoPendiente::query()->exists());
        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Rol dentro de la distribuidora
    // ------------------------------------------------------------------

    public function test_empleado_a_admin_y_de_regreso_conserva_permisos_finos(): void
    {
        $empleado = $this->empleado();
        $finos = ['pagos.registrar' => false, 'costos.ver' => true];
        $this->fila($empleado, $this->ramirez())->forceFill(['permisos_finos' => $finos])->save();

        $this->cambioDeRol($empleado, $this->asignacionEmpleado(), 'admin_distribuidora')->assertHasNoErrors();

        $this->assertSame([$this->asignacionAdmin()], $this->rolesDe($empleado));
        $this->assertSame('administrador', $this->fila($empleado, $this->ramirez())->tipo);
        Mail::assertSent(CambioCuentaPersonalMail::class, fn ($mail) => $mail->hasTo($empleado->email)
            && str_contains($mail->mensaje, 'Empleado en Calzados Ramírez')
            && str_contains($mail->mensaje, 'Admin de distribuidora en Calzados Ramírez'));

        $this->cambioDeRol($empleado, $this->asignacionAdmin(), 'empleado')->assertHasNoErrors();

        $fila = $this->fila($empleado, $this->ramirez());
        $this->assertSame('empleado', $fila->tipo);
        $this->assertEquals($finos, $fila->permisos_finos);
    }

    public function test_no_deja_a_una_distribuidora_sin_su_unico_admin_activo(): void
    {
        $admin = $this->adminDistribuidora();

        $this->cambioDeRol($admin, $this->asignacionAdmin(), 'empleado')
            ->assertHasErrors(['rol_nuevo'])
            ->assertSee('Primero nombra a otro admin.');
        $this->assertSame([$this->asignacionAdmin()], $this->rolesDe($admin));

        $this->otroAdminEn($this->ramirez());

        $this->cambioDeRol($admin, $this->asignacionAdmin(), 'empleado')->assertHasNoErrors();
        $this->assertSame([$this->asignacionEmpleado()], $this->rolesDe($admin));
    }

    public function test_con_varios_roles_solo_cambia_la_asignacion_elegida_y_no_los_de_cliente(): void
    {
        $empleado = $this->empleado();
        $this->darRol($empleado, 'empleado', $this->boutique());
        $this->darRol($empleado, 'cliente_directo', $this->boutique());

        $this->cambioDeRol($empleado, $this->asignacionEmpleado(), 'admin_distribuidora')->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$this->asignacionAdmin(), 'empleado|'.$this->boutique()],
            $this->rolesDe($empleado)
        );
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $empleado->id)
            ->where('model_has_roles.team_id', $this->boutique())
            ->where('roles.name', 'cliente_directo')
            ->exists());
        $this->assertNull($this->fila($empleado, $this->boutique()));
    }

    public function test_no_deja_cambiado_el_equipo_de_permisos(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(987);

        $this->cambioDeRol($this->empleado(), $this->asignacionEmpleado(), 'admin_distribuidora')->assertHasNoErrors();

        $this->assertSame(987, (int) $registrar->getPermissionsTeamId());
    }

    // ------------------------------------------------------------------
    // Admin general a personal
    // ------------------------------------------------------------------

    public function test_no_puedes_quitarte_tu_propio_rol_de_admin_general(): void
    {
        $this->otroAdminGeneral();

        $this->cambioDeRol($this->admin, 'admin_general|0', 'empleado', [
            'distribuidora_nueva' => (string) $this->ramirez(),
            'confirmar_consecuencias' => true,
        ])->assertHasErrors(['rol_nuevo'])->assertSee('No puedes quitarte tu propio rol');

        $this->assertSame(['admin_general|0'], $this->rolesDe($this->admin));
    }

    public function test_no_se_le_quita_el_rol_al_ultimo_admin_general_activo(): void
    {
        $otro = $this->otroAdminGeneral();
        $this->admin->forceFill(['estado' => 'bloqueado'])->save();

        // Quien hace el cambio sigue siendo admin general, pero no cuenta como activo.
        $this->cambioDeRol($otro, 'admin_general|0', 'empleado', [
            'distribuidora_nueva' => (string) $this->ramirez(),
            'confirmar_consecuencias' => true,
        ])->assertHasErrors(['rol_nuevo'])->assertSee('último admin general activo');

        $this->assertSame(['admin_general|0'], $this->rolesDe($otro));
    }

    public function test_admin_general_pasa_a_personal_con_advertencia_y_distribuidora_activa(): void
    {
        $otro = $this->otroAdminGeneral();

        $this->ficha($otro)->call('abrirCambio', 'rol')
            ->assertSet('asignacion', 'admin_general|0')
            ->assertSee('Dejará de ser admin general.');

        $base = ['distribuidora_nueva' => (string) $this->ramirez()];

        $this->cambioDeRol($otro, 'admin_general|0', 'empleado', $base)->assertHasErrors(['confirmar_consecuencias']);

        Distribuidora::whereKey($this->boutique())->update(['estado' => 'suspendida']);
        $this->cambioDeRol($otro, 'admin_general|0', 'empleado', [
            'distribuidora_nueva' => (string) $this->boutique(),
            'confirmar_consecuencias' => true,
        ])->assertHasErrors(['distribuidora_nueva']);

        $this->cambioDeRol($otro, 'admin_general|0', 'empleado', $base + ['confirmar_consecuencias' => true])->assertHasNoErrors();

        $this->assertSame([$this->asignacionEmpleado()], $this->rolesDe($otro));
        $this->assertSame('activo', $this->fila($otro, $this->ramirez())->estado);
        $this->assertFalse(PersonalPlataforma::esAdminGeneral($otro));
    }

    public function test_reactiva_la_fila_inactiva_en_vez_de_crear_otra(): void
    {
        $otro = $this->otroAdminGeneral();
        $vieja = DistribuidoraStaff::withoutGlobalScopes()->create([
            'distribuidora_id' => $this->ramirez(),
            'usuario_id' => $otro->id,
            'tipo' => 'administrador',
            'estado' => 'inactivo',
            'fecha_alta' => now()->subYear(),
        ]);

        $this->cambioDeRol($otro, 'admin_general|0', 'empleado', [
            'distribuidora_nueva' => (string) $this->ramirez(),
            'confirmar_consecuencias' => true,
        ])->assertHasNoErrors();

        $fila = DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $otro->id)->sole();
        $this->assertSame($vieja->id, $fila->id);
        $this->assertSame('activo', $fila->estado);
        $this->assertSame('empleado', $fila->tipo);
    }

    // ------------------------------------------------------------------
    // Personal a admin general
    // ------------------------------------------------------------------

    private function aAdminGeneral(Usuario $persona, string $asignacion)
    {
        return $this->cambioDeRol($persona, $asignacion, 'admin_general', ['confirmar_consecuencias' => true]);
    }

    public function test_personal_pasa_a_admin_general_y_su_fila_queda_inactiva(): void
    {
        $empleado = $this->empleado();

        $this->cambioDeRol($empleado, $this->asignacionEmpleado(), 'admin_general')
            ->assertHasErrors(['confirmar_consecuencias']);

        $this->aAdminGeneral($empleado, $this->asignacionEmpleado())->assertHasNoErrors();

        $this->assertSame(['admin_general|0'], $this->rolesDe($empleado));
        $this->assertSame('inactivo', $this->fila($empleado, $this->ramirez())->estado);

        $this->actingAs($empleado->fresh());
        $this->limpiarCaches();
        $this->get(route('admin.usuarios'))->assertOk();
    }

    public function test_al_pasar_a_admin_general_se_borra_la_direccion_sin_dejarla_en_la_auditoria(): void
    {
        $direccion = 'Calle Privada 337';
        $empleado = $this->empleado();
        $empleado->forceFill(['direccion' => $direccion])->save();

        $this->ficha($empleado)->call('abrirCambio', 'rol')
            ->set('rol_nuevo', 'admin_general')
            ->assertSee('su dirección se borrará');

        $this->aAdminGeneral($empleado, $this->asignacionEmpleado())->assertHasNoErrors();

        $this->assertNull($empleado->fresh()->direccion);

        $registro = Auditoria::withoutGlobalScopes()->where('accion', 'personal.rol_cambiado')->sole();
        $this->assertTrue($registro->datos_nuevos['direccion_borrada']);
        $this->assertStringNotContainsString($direccion, json_encode(
            Auditoria::withoutGlobalScopes()->where('entidad_id', $empleado->id)->get()->map->only(['datos_previos', 'datos_nuevos']),
            JSON_UNESCAPED_UNICODE
        ));
    }

    public function test_si_el_cambio_a_admin_general_se_bloquea_la_direccion_se_queda(): void
    {
        $admin = $this->adminDistribuidora();
        $admin->forceFill(['direccion' => 'Calle Privada 337'])->save();

        $this->aAdminGeneral($admin, $this->asignacionAdmin())->assertHasErrors(['rol_nuevo']);

        $this->assertSame('Calle Privada 337', $admin->fresh()->direccion);
    }

    public function test_sin_direccion_la_auditoria_no_anota_que_se_borro(): void
    {
        $this->aAdminGeneral($this->empleado(), $this->asignacionEmpleado())->assertHasNoErrors();

        $this->assertArrayNotHasKey(
            'direccion_borrada',
            Auditoria::withoutGlobalScopes()->where('accion', 'personal.rol_cambiado')->sole()->datos_nuevos
        );
    }

    public function test_no_pasa_a_admin_general_con_otra_asignacion(): void
    {
        $empleado = $this->empleado();
        $this->darRol($empleado, 'empleado', $this->boutique());

        $this->aAdminGeneral($empleado, $this->asignacionEmpleado())
            ->assertHasErrors(['rol_nuevo'])
            ->assertSee('una sola asignación');
    }

    public function test_no_pasa_a_admin_general_con_una_caja_abierta(): void
    {
        $empleado = $this->empleado();
        $fila = $this->fila($empleado, $this->ramirez());

        CajaSesion::withoutGlobalScopes()->create([
            'distribuidora_id' => $this->ramirez(),
            'sucursal_id' => Sucursal::withoutGlobalScopes()->where('distribuidora_id', $this->ramirez())->value('id'),
            'abierta_por_staff_id' => $fila->id,
            'estado' => 'abierta',
            'fondo_inicial' => 0,
            'abierta_at' => now(),
        ]);

        $this->aAdminGeneral($empleado, $this->asignacionEmpleado())
            ->assertHasErrors(['rol_nuevo'])
            ->assertSee('caja abierta');
    }

    public function test_no_pasa_a_admin_general_si_es_cliente_aunque_su_registro_este_inactivo(): void
    {
        $empleado = $this->empleado();

        ClienteDirecto::withoutGlobalScopes()->create([
            'distribuidora_id' => $this->boutique(),
            'usuario_id' => $empleado->id,
            'nombre' => 'Registro viejo',
            'telefono' => '9610003370',
            'estado' => 'inactivo',
        ]);

        $this->aAdminGeneral($empleado, $this->asignacionEmpleado())
            ->assertHasErrors(['rol_nuevo'])
            ->assertSee('también es cliente');

        $this->assertSame([$this->asignacionEmpleado()], $this->rolesDe($empleado));
    }

    public function test_no_pasa_a_admin_general_si_es_cliente_mayorista(): void
    {
        $empleado = $this->empleado();
        Revendedor::withoutGlobalScopes()->where('usuario_id', $this->cuenta(self::MAYORISTA)->id)
            ->update(['usuario_id' => $empleado->id, 'estado' => 'inactivo']);

        $this->aAdminGeneral($empleado, $this->asignacionEmpleado())
            ->assertHasErrors(['rol_nuevo'])
            ->assertSee('también es cliente');
    }

    public function test_el_unico_admin_de_una_distribuidora_no_pasa_a_admin_general(): void
    {
        $this->aAdminGeneral($this->adminDistribuidora(), $this->asignacionAdmin())
            ->assertHasErrors(['rol_nuevo'])
            ->assertSee('Primero nombra a otro admin.');
    }

    // ------------------------------------------------------------------
    // Contraseña
    // ------------------------------------------------------------------

    public function test_contrasena_temporal_se_ve_una_vez_y_obliga_a_cambiarla(): void
    {
        $empleado = $this->empleado();

        $prueba = $this->ficha($empleado)->call('abrirCambio', 'password')
            ->set('modo_password', 'temporal')
            ->set('password_confirmacion', self::CLAVE_ADMIN)
            ->call('guardarCambio')
            ->assertHasNoErrors();

        $this->assertSame(1, preg_match('/data-password-temporal[^>]*>([^<]+)</', $prueba->html(), $coincide));
        $temporal = html_entity_decode($coincide[1]);

        $empleado->refresh();
        $this->assertTrue(Hash::check($temporal, $empleado->password));
        $this->assertTrue($empleado->debe_cambiar_password);
        $this->assertStringNotContainsString($temporal, json_encode($prueba->snapshot));

        $prueba->call('cerrarPasswordTemporal')->assertDontSee($temporal);

        Mail::assertSent(CambioCuentaPersonalMail::class, fn ($mail) => $mail->hasTo($empleado->email)
            && ! str_contains($mail->render(), $temporal));
    }

    public function test_enlace_de_restablecimiento_sin_tocar_la_contrasena_actual(): void
    {
        $empleado = $this->empleado();
        $hash = $empleado->password;

        $this->ficha($empleado)->call('abrirCambio', 'password')
            ->set('modo_password', 'enlace')
            ->set('password_confirmacion', self::CLAVE_ADMIN)
            ->call('guardarCambio')
            ->assertHasNoErrors()
            ->assertDontSee('Cópiala ahora');

        Notification::assertSentTo($empleado, RestablecerPasswordNotification::class);
        $this->assertSame($hash, $empleado->fresh()->password);
        $this->assertFalse($empleado->fresh()->debe_cambiar_password);
    }

    // ------------------------------------------------------------------
    // Auditoría y acceso
    // ------------------------------------------------------------------

    public function test_la_auditoria_queda_sin_distribuidora_ni_datos_sensibles(): void
    {
        $empleado = $this->empleado();

        $this->cambioDeRol($empleado, $this->asignacionEmpleado(), 'admin_distribuidora')->assertHasNoErrors();

        $registro = Auditoria::withoutGlobalScopes()->where('accion', 'personal.rol_cambiado')->sole();
        $this->assertSame($this->admin->id, $registro->usuario_id);
        $this->assertNull($registro->distribuidora_id);
        $this->assertEquals(['rol' => 'empleado', 'distribuidora_id' => $this->ramirez(), 'distribuidora' => 'Calzados Ramírez'], $registro->datos_previos);
        $this->assertEquals(['rol' => 'admin_distribuidora', 'distribuidora_id' => $this->ramirez(), 'distribuidora' => 'Calzados Ramírez'], $registro->datos_nuevos);

        $prueba = $this->ficha($empleado)->call('abrirCambio', 'password')
            ->set('modo_password', 'temporal')
            ->set('password_confirmacion', self::CLAVE_ADMIN)
            ->call('guardarCambio');
        preg_match('/data-password-temporal[^>]*>([^<]+)</', $prueba->html(), $coincide);

        $temporal = Auditoria::withoutGlobalScopes()->where('accion', 'password.temporal_generada')->sole();
        $this->assertNull($temporal->distribuidora_id);

        $texto = json_encode(Auditoria::withoutGlobalScopes()->where('entidad_id', $empleado->id)->get()
            ->map->only(['datos_previos', 'datos_nuevos']));

        $this->assertStringNotContainsString($coincide[1], $texto);
        $this->assertStringNotContainsString(self::CLAVE_ADMIN, $texto);
        $this->assertStringNotContainsString((string) $empleado->fresh()->password, $texto);
    }

    public function test_ningun_otro_rol_entra_a_los_cambios(): void
    {
        $empleado = $this->empleado();

        foreach ([DemoDistribuidoraSeeder::ADMIN, DemoDistribuidoraSeeder::EMPLEADO, self::CLIENTE, self::MAYORISTA] as $correo) {
            $this->actingAs($this->cuenta($correo));
            $this->limpiarCaches();

            Livewire::test('admin.usuarios-ficha', ['usuario' => $empleado->id])->assertForbidden();
        }
    }

    public function test_un_cliente_no_se_puede_modificar(): void
    {
        $cliente = $this->cuenta(self::CLIENTE);

        $this->get(route('admin.usuarios.ficha', $cliente->id))->assertNotFound();

        try {
            app(CambiarRolPersonalAction::class)->ejecutar($this->admin, $cliente, [
                'asignacion' => 'cliente_directo|'.$this->ramirez(),
                'rol_nuevo' => 'empleado',
                'password_confirmacion' => self::CLAVE_ADMIN,
            ]);
            $this->fail('Debió rechazarse.');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $this->assertSame([], $this->rolesDe($cliente));
        }
    }

    public function test_el_rol_invalido_se_rechaza(): void
    {
        $this->expectException(ValidationException::class);

        app(CambiarRolPersonalAction::class)->ejecutar($this->admin, $this->empleado(), [
            'asignacion' => $this->asignacionEmpleado(),
            'rol_nuevo' => 'cliente_directo',
            'password_confirmacion' => self::CLAVE_ADMIN,
        ]);
    }
}
