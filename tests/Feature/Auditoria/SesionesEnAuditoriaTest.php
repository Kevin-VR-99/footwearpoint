<?php

namespace Tests\Feature\Auditoria;

use App\Models\Auditoria;
use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Services\Auditoria\RegistrarSesionAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-333 (K35) — Inicios y cierres de sesión en la auditoría.
 *
 * Panel: con IP (el aviso 1.3 la declara para la bitácora del panel). App: sin
 * IP. Nunca el navegador. Cada distribuidora ve solo lo suyo; el admin general
 * ve las sesiones de todas.
 */
class SesionesEnAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CLAVE = 'clave-de-prueba-9';

    private const CLIENTE = 'jose.hernandez@cliente.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    protected function tearDown(): void
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        parent::tearDown();
    }

    /** La cuenta del seeder con una contraseña conocida para entrar de verdad. */
    private function cuenta(string $correo): Usuario
    {
        $usuario = Usuario::where('email', $correo)->firstOrFail();
        $usuario->forceFill(['password' => Hash::make(self::CLAVE)])->save();

        return $usuario;
    }

    private function sesiones(?Usuario $usuario = null)
    {
        return Auditoria::withoutGlobalScopes()
            ->where('accion', 'like', 'sesion.%')
            ->when($usuario, fn ($q) => $q->where('usuario_id', $usuario->id))
            ->orderBy('id')
            ->get();
    }

    private function entrarAlPanel(Usuario $usuario): void
    {
        Livewire::test('auth.login')
            ->set('email', $usuario->email)
            ->set('password', self::CLAVE)
            ->call('login')
            ->assertHasNoErrors();
    }

    private function distribuidora(string $slug): int
    {
        return (int) Distribuidora::where('slug', $slug)->value('id');
    }

    // ------------------------------------------------------------------
    // Panel
    // ------------------------------------------------------------------

    public function test_inicio_de_sesion_en_el_panel_con_usuario_fecha_e_ip(): void
    {
        $empleado = $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO);

        $this->entrarAlPanel($empleado);

        $registro = $this->sesiones($empleado)->sole();
        $this->assertSame(RegistrarSesionAction::INICIO_PANEL, $registro->accion);
        $this->assertSame(Tenant::paraUsuario($empleado->id), $registro->distribuidora_id);
        $this->assertSame('127.0.0.1', $registro->ip_origen);
        $this->assertNotNull($registro->created_at);
        // Solo el canal: nada del navegador.
        $this->assertSame(['canal' => 'panel'], $registro->datos_nuevos);
    }

    public function test_cerrar_sesion_en_el_panel_queda_registrado(): void
    {
        $empleado = $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO);
        $this->actingAs($empleado);

        $this->post(route('logout'))->assertRedirect();

        $registro = $this->sesiones($empleado)->sole();
        $this->assertSame(RegistrarSesionAction::CIERRE_PANEL, $registro->accion);
        $this->assertSame('127.0.0.1', $registro->ip_origen);
    }

    public function test_cuando_entra_solo_con_recordarme_queda_como_recordado(): void
    {
        $empleado = $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO);
        $empleado->forceFill(['remember_token' => 'token-recordado'])->save();
        $guard = Auth::guard('web');

        $this->withCookie(
            $guard->getRecallerName(),
            $empleado->id.'|token-recordado|'.$empleado->getAuthPassword()
        )->get(route('dashboard'))->assertOk();

        $registro = $this->sesiones($empleado)->sole();
        $this->assertSame(RegistrarSesionAction::INICIO_RECORDADO, $registro->accion);
        $this->assertSame('Inicio de sesión (recordado)', \App\Support\Auditoria\EtiquetasAuditoria::etiqueta($registro->accion));
    }

    // ------------------------------------------------------------------
    // App
    // ------------------------------------------------------------------

    public function test_inicio_y_cierre_de_sesion_en_la_app_sin_ip(): void
    {
        $cliente = $this->cuenta(self::CLIENTE);

        $token = $this->postJson('/api/auth/login', ['email' => $cliente->email, 'password' => self::CLAVE])
            ->assertOk()
            ->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/auth/logout')->assertOk();

        $registros = $this->sesiones($cliente);
        $this->assertSame([RegistrarSesionAction::INICIO_APP, RegistrarSesionAction::CIERRE_APP], $registros->pluck('accion')->all());

        foreach ($registros as $registro) {
            $this->assertNull($registro->ip_origen, 'En la app no se guarda la IP.');
            $this->assertSame(['canal' => 'app'], $registro->datos_nuevos);
            $this->assertSame(Tenant::paraUsuario($cliente->id), $registro->distribuidora_id);
        }
    }

    public function test_un_inicio_fallido_en_la_app_no_se_registra(): void
    {
        $cliente = $this->cuenta(self::CLIENTE);

        $this->postJson('/api/auth/login', ['email' => $cliente->email, 'password' => 'otra-clave-1'])
            ->assertStatus(422);

        $this->assertCount(0, $this->sesiones());
    }

    /** Un token que vence (o se borra) solo no es un cierre de sesión. */
    public function test_un_token_que_se_borra_solo_no_cuenta_como_cierre(): void
    {
        $cliente = $this->cuenta(self::CLIENTE);
        $cliente->createToken('prueba');

        $cliente->tokens()->delete();

        $this->assertCount(0, $this->sesiones());
    }

    // ------------------------------------------------------------------
    // Quién ve qué
    // ------------------------------------------------------------------

    private function sesionesDeTodos(): array
    {
        $admin = $this->cuenta(DemoDistribuidoraSeeder::ADMIN);
        $empleado = $this->cuenta(DemoDistribuidoraSeeder::EMPLEADO);
        $cliente = $this->cuenta(self::CLIENTE);
        $otraAdmin = $this->cuenta(DemoDistribuidoraSeeder::SEGUNDA_ADMIN);
        $adminGeneral = $this->cuenta(AdminGeneralSeeder::EMAIL);

        $registrar = app(RegistrarSesionAction::class);
        $registrar->ejecutar($empleado, RegistrarSesionAction::INICIO_PANEL);
        $registrar->ejecutar($cliente, RegistrarSesionAction::INICIO_APP);
        $registrar->ejecutar($otraAdmin, RegistrarSesionAction::INICIO_PANEL);
        $registrar->ejecutar($adminGeneral, RegistrarSesionAction::INICIO_PANEL);

        return compact('admin', 'empleado', 'cliente', 'otraAdmin', 'adminGeneral');
    }

    public function test_cada_distribuidora_ve_solo_las_sesiones_de_su_personal_y_sus_clientes(): void
    {
        ['admin' => $admin, 'empleado' => $empleado, 'cliente' => $cliente, 'otraAdmin' => $otraAdmin, 'adminGeneral' => $adminGeneral]
            = $this->sesionesDeTodos();

        $this->actingAs($admin);
        Tenant::olvidarCache();
        // El rol vive en el equipo de su distribuidora (Spatie con equipos).
        setPermissionsTeamId(Tenant::id());

        $usuarios = Livewire::test('auditoria.index')->instance()->registros
            ->filter(fn ($r) => str_starts_with($r->accion, 'sesion.'))->pluck('usuario_id')->all();

        $this->assertContains($empleado->id, $usuarios);
        $this->assertContains($cliente->id, $usuarios);
        $this->assertNotContains($otraAdmin->id, $usuarios, 'No ve sesiones de otra distribuidora.');
        $this->assertNotContains($adminGeneral->id, $usuarios, 'No ve sesiones del admin general.');

        $this->get(route('auditoria.index'))->assertOk()
            ->assertSee('Inicio de sesión (panel)')
            ->assertSee('Inicio de sesión (app)')
            ->assertDontSee($otraAdmin->email)
            ->assertDontSee($adminGeneral->email);
    }

    public function test_el_admin_general_ve_las_sesiones_de_todos_con_su_distribuidora(): void
    {
        ['empleado' => $empleado, 'cliente' => $cliente, 'otraAdmin' => $otraAdmin, 'adminGeneral' => $adminGeneral]
            = $this->sesionesDeTodos();

        $this->actingAs($adminGeneral);
        Tenant::olvidarCache();

        $usuarios = Livewire::test('admin.auditoria')->set('filtro_area', 'Sesiones')->instance()
            ->registros->pluck('usuario_id')->all();

        foreach ([$empleado, $cliente, $otraAdmin, $adminGeneral] as $usuario) {
            $this->assertContains($usuario->id, $usuarios, $usuario->email);
        }

        $this->get(route('admin.auditoria'))->assertOk()
            ->assertSee('Calzados Ramírez')
            ->assertSee($otraAdmin->email);
    }

    /** El admin general no empieza a ver el resto de la auditoría de cada distribuidora. */
    public function test_el_admin_general_solo_suma_las_sesiones_no_lo_demas_de_cada_distribuidora(): void
    {
        $this->sesionesDeTodos();
        $deUnaDistribuidora = Auditoria::withoutGlobalScopes()->create([
            'usuario_id' => null,
            'distribuidora_id' => $this->distribuidora(DemoDistribuidoraSeeder::SEGUNDA_SLUG),
            'accion' => 'stock.ajuste',
            'entidad_tipo' => 'variante',
        ]);

        $this->actingAs(Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail());
        Tenant::olvidarCache();

        $ids = Livewire::test('admin.auditoria')->instance()->registros->pluck('id')->all();

        $this->assertNotContains($deUnaDistribuidora->id, $ids);
    }
}
