<?php

namespace Tests\Feature\Admin;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * TG-393 (A33) — El apartado Métricas del admin general.
 *
 * Esta tarjeta es el marco: ruta, menú, filtros y permisos. Los números son
 * A34 y las gráficas A35.
 *
 * El "Listo cuando" de la tarjeta son tres cosas: 403 a quien no es admin, la
 * pantalla carga, y el punto 21 del plan.
 */
class ApartadoMetricasTest extends TestCase
{
    use CreaAdminGeneral;
    use RefreshDatabase;

    protected bool $seed = true;

    private function comoAdminGeneral(): Usuario
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $admin = $this->adminGeneral();
        $this->actingAs($admin);
        Tenant::olvidarCache();

        return $admin;
    }

    public function test_el_admin_general_entra_y_la_pantalla_carga(): void
    {
        $this->comoAdminGeneral();

        $this->get('/admin/metricas')
            ->assertOk()
            ->assertSee('Métricas')
            ->assertSee('Periodo')
            ->assertSee('Distribuidora')
            ->assertSee('Plan');
    }

    public function test_a_una_distribuidora_le_responde_403(): void
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());

        $this->get('/admin/metricas')->assertForbidden();
    }

    public function test_sin_sesion_manda_al_login(): void
    {
        $this->get('/admin/metricas')->assertRedirect('/login');
    }

    /**
     * El permiso se vuelve a comprobar en cada acción.
     *
     * Las peticiones siguientes de Livewire NO pasan por el middleware de la
     * ruta: quien ya tenga la pantalla abierta y deje de ser admin podría
     * seguir pidiendo datos. Por eso el componente lo comprueba él mismo.
     */
    public function test_el_componente_comprueba_el_permiso_el_mismo(): void
    {
        $vista = file_get_contents(base_path('resources/views/components/admin/⚡metricas.blade.php'));

        $this->assertStringContainsString('Tenant::esAdminGeneral()', $vista);
        $this->assertStringContainsString('private function autorizar', $vista);
    }

    public function test_el_menu_del_admin_lleva_a_metricas(): void
    {
        $this->comoAdminGeneral();

        $this->get('/admin')->assertOk()->assertSee('Métricas');
    }

    /** Los filtros viajan en la dirección: una vista se puede compartir. */
    public function test_los_filtros_se_guardan_en_la_direccion(): void
    {
        $this->comoAdminGeneral();

        Livewire::test('admin.metricas')
            ->assertSet('dias', '30')
            ->set('dias', '7')
            ->assertSet('dias', '7')
            ->set('distribuidora', '1')
            ->call('limpiarFiltros')
            ->assertSet('dias', '30')
            ->assertSet('distribuidora', '')
            ->assertSet('plan', '');
    }

    /**
     * Una dirección escrita a mano no puede tronar la pantalla.
     *
     * Los filtros llegan como texto desde la URL; cualquiera puede escribir
     * ?dias=abc o una distribuidora que no existe.
     */
    public function test_una_direccion_inventada_no_rompe_nada(): void
    {
        $this->comoAdminGeneral();

        $this->get('/admin/metricas?dias=abc&distribuidora=hola&plan=999999')->assertOk();

        Livewire::test('admin.metricas', ['dias' => 'abc', 'distribuidora' => 'hola', 'plan' => '999999'])
            ->assertOk()
            ->assertSet('dias', 'abc');
    }

    /**
     * El rango se cuenta en hora de México.
     *
     * Es la misma regla del registro de actividad: con UTC, lo que pasó a las
     * 7 de la noche aparecería al día siguiente.
     */
    public function test_el_rango_va_en_hora_de_mexico(): void
    {
        $this->comoAdminGeneral();

        $rango = Livewire::test('admin.metricas')->instance()->rango;

        $this->assertSame('America/Mexico_City', $rango['desde']->timezone->getName());
        $this->assertSame('America/Mexico_City', $rango['hasta']->timezone->getName());

        // Por fecha y no por diferencia de horas: 'desde' es el inicio del dia
        // y 'hasta' el final, asi que restarlos da 29 dias y pico.
        $this->assertSame(
            $rango['hasta']->copy()->subDays(29)->toDateString(),
            $rango['desde']->toDateString(),
            'El periodo de 30 dias no abarca 30 dias completos.'
        );
        $this->assertSame('00:00:00', $rango['desde']->format('H:i:s'));
        $this->assertSame('23:59:59', $rango['hasta']->format('H:i:s'));
    }
}
