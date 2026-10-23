<?php

namespace Tests\Feature\Seguridad;

use App\Mail\SolicitudAltaDistribuidoraMail;
use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Services\Distribuidora\NotificarSolicitudAltaAction;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Database\Seeders\DemoDistribuidoraSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-280 — Había dos roles admin_general (team null y team 0) y el aviso de
 * solicitud de alta no encontraba a nadie.
 */
class RolAdminGeneralUnicoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MIGRACION = 'database/migrations/2026_10_12_100100_fusiona_rol_admin_general_duplicado.php';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
    }

    private function rolesAdminGeneral()
    {
        return DB::table('roles')->where('name', 'admin_general')->get();
    }

    public function test_tras_sembrar_solo_hay_un_admin_general_en_el_equipo_0(): void
    {
        $roles = $this->rolesAdminGeneral();

        $this->assertCount(1, $roles);
        $this->assertSame(0, (int) $roles->first()->team_id);
        $this->assertNotNull($roles->first()->team_id);
    }

    public function test_volver_a_sembrar_no_duplica_ni_rompe(): void
    {
        $this->seed(RolesPermissionsSeeder::class);
        $this->seed(AdminGeneralSeeder::class);

        $this->assertCount(1, $this->rolesAdminGeneral());
        $this->assertSame(1, Usuario::where('email', AdminGeneralSeeder::EMAIL)->count());
    }

    public function test_el_admin_general_tiene_el_rol_y_el_correo_verificado(): void
    {
        $admin = Usuario::where('email', 'mvpla.footwearpoint@gmail.com')->firstOrFail();

        app(PermissionRegistrar::class)->setPermissionsTeamId(0);

        $this->assertTrue($admin->hasRole('admin_general'));
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_si_la_cuenta_ya_existe_no_cambia_su_contrasena(): void
    {
        $admin = Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();
        $admin->forceFill(['password' => 'hash-de-produccion', 'email_verified_at' => null])->save();

        $this->seed(AdminGeneralSeeder::class);

        $admin->refresh();
        $this->assertSame('hash-de-produccion', $admin->getRawOriginal('password'));
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_el_aviso_de_solicitud_de_alta_llega_al_buzon_de_footwearpoint(): void
    {
        Mail::fake();

        $distribuidora = Distribuidora::query()->firstOrFail();
        $solicitante = Usuario::where('email', DemoDistribuidoraSeeder::ADMIN)->firstOrFail();

        $enviado = app(NotificarSolicitudAltaAction::class)->ejecutar($distribuidora, $solicitante);

        $this->assertTrue($enviado);
        $this->assertSame('footwearpoint.mx@gmail.com', config('footwearpoint.solicitudes_alta.correo'));
        Mail::assertSent(SolicitudAltaDistribuidoraMail::class, function ($correo) {
            return $correo->hasTo('footwearpoint.mx@gmail.com')
                && ! $correo->hasTo(AdminGeneralSeeder::EMAIL);
        });
    }

    public function test_sin_buzon_de_solicitudes_el_aviso_cae_en_el_admin_general(): void
    {
        Mail::fake();
        config(['footwearpoint.solicitudes_alta.correo' => '']);

        $distribuidora = Distribuidora::query()->firstOrFail();
        $solicitante = Usuario::where('email', DemoDistribuidoraSeeder::ADMIN)->firstOrFail();

        $enviado = app(NotificarSolicitudAltaAction::class)->ejecutar($distribuidora, $solicitante);

        $this->assertTrue($enviado);
        Mail::assertSent(SolicitudAltaDistribuidoraMail::class, fn ($correo) => $correo->hasTo(AdminGeneralSeeder::EMAIL));
    }

    public function test_la_migracion_fusiona_el_duplicado_en_el_de_equipo_0(): void
    {
        $bueno = $this->rolesAdminGeneral()->first();
        $admin = Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();
        $otro = Usuario::where('email', DemoDistribuidoraSeeder::EMPLEADO)->firstOrFail();
        $permiso = Permission::firstOrCreate(['name' => 'ciclo.cerrar', 'guard_name' => 'web']);

        // El duplicado como quedó en producción: team null.
        $viejo = DB::table('roles')->insertGetId([
            'name' => 'admin_general',
            'guard_name' => 'web',
            'team_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            ['role_id' => $viejo, 'model_type' => $admin->getMorphClass(), 'model_id' => $admin->id, 'team_id' => 0],
            ['role_id' => $viejo, 'model_type' => $otro->getMorphClass(), 'model_id' => $otro->id, 'team_id' => 0],
        ]);
        DB::table('role_has_permissions')->insert(['role_id' => $viejo, 'permission_id' => $permiso->id]);

        $migracion = require base_path(self::MIGRACION);
        $migracion->up();
        $migracion->up(); // idempotente

        $roles = $this->rolesAdminGeneral();
        $this->assertCount(1, $roles);
        $this->assertSame((int) $bueno->id, (int) $roles->first()->id);

        // El admin no queda con la fila repetida; el otro usuario sí pasa.
        $this->assertSame(1, DB::table('model_has_roles')->where('role_id', $bueno->id)->where('model_id', $admin->id)->count());
        $this->assertSame(1, DB::table('model_has_roles')->where('role_id', $bueno->id)->where('model_id', $otro->id)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('role_id', $viejo)->count());
        $this->assertTrue(DB::table('role_has_permissions')->where('role_id', $bueno->id)->where('permission_id', $permiso->id)->exists());
    }
}
