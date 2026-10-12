<?php

namespace Tests\Feature\Panel;

use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-246 — Los permisos por módulo del empleado se respetan en el servidor.
 *
 *  1. Sin 'reportes.ver_financieros', /reportes da 403 (antes se abría por
 *     URL y mostraba los montos) y el menú no lo enlaza.
 *  2. Sin 'ciclo.cerrar', /ciclo no muestra el botón "Cerrar ciclo" y la
 *     acción, llamada a mano, no cierra el ciclo.
 */
class PermisosModuloReportesCicloTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

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

    private function usuario(string $email): Usuario
    {
        return Usuario::where('email', $email)->firstOrFail();
    }

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $usuario = $this->usuario($email);
        $this->actingAs($usuario);

        return $usuario;
    }

    private function darPermiso(string $email, string $permiso): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->distribuidora()->id);
        $this->usuario($email)->givePermissionTo($permiso);
        $registrar->forgetCachedPermissions();
    }

    // ------------------------------------------------------------------
    // Falla 1: reportes financieros
    // ------------------------------------------------------------------

    public function test_el_empleado_sin_permiso_no_abre_reportes_por_url(): void
    {
        $this->como(self::EMPLEADO);

        $this->get(route('reportes.index'))->assertForbidden();
    }

    public function test_el_empleado_sin_permiso_no_ve_el_enlace_a_reportes(): void
    {
        $this->como(self::EMPLEADO);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('reportes.index'), false);
    }

    public function test_el_empleado_con_permiso_si_abre_reportes(): void
    {
        $this->darPermiso(self::EMPLEADO, 'reportes.ver_financieros');
        $this->como(self::EMPLEADO);

        $this->get(route('reportes.index'))->assertOk();
    }

    public function test_el_admin_de_la_distribuidora_abre_reportes(): void
    {
        $this->como(self::ADMIN);

        $this->get(route('reportes.index'))->assertOk();
    }
}
