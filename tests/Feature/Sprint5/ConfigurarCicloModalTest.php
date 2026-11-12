<?php

namespace Tests\Feature\Sprint5;

use App\Models\ConfiguracionCiclo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AyudasFuncionesCompletas;
use Tests\TestCase;

/** S5-51: las reglas del ciclo se abren desde Compras → Ciclo y salen de Configuración. */
class ConfigurarCicloModalTest extends TestCase
{
    use AyudasFuncionesCompletas;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_el_admin_abre_el_modal_y_guarda_con_las_mismas_reglas(): void
    {
        $this->assertSame('America/Mexico_City', config('app.timezone'));
        $this->comoPanel(self::ADMIN);
        $antes = ConfiguracionCiclo::query()->count();

        Livewire::test('ciclo.index')
            ->assertSee('data-configurar-ciclo', false)
            ->call('abrirConfigurarCiclo')
            ->assertSet('configurandoCiclo', true)
            ->assertSee('Configurar ciclo')
            ->assertSee('Configuraciones de ciclo')
            ->call('cerrarConfigurarCiclo')
            ->assertSet('configurandoCiclo', false);

        Livewire::test('distribuidora.ciclos')
            ->call('abrirFormularioCrearCiclo')
            ->set('ciclo_dias_recepcion', [])
            ->call('guardarCiclo')
            ->assertHasErrors('ciclo_dias_recepcion');

        Livewire::test('distribuidora.ciclos')
            ->call('abrirFormularioCrearCiclo')
            ->set('ciclo_dias_estimados_llegada', 7)
            ->set('ciclo_dias_recepcion', [1, 3])
            ->call('guardarCiclo')
            ->assertHasNoErrors();

        $this->assertSame($antes + 1, ConfiguracionCiclo::query()->count());
        $this->assertTrue(ConfiguracionCiclo::query()->where('dias_estimados_llegada', 7)->exists());
    }

    public function test_el_empleado_no_ve_el_boton_ni_guarda_reglas(): void
    {
        $this->comoPanel(self::EMPLEADO);

        Livewire::test('ciclo.index')
            ->assertDontSee('data-configurar-ciclo', false)
            ->call('abrirConfigurarCiclo')
            ->assertForbidden();

        Livewire::test('distribuidora.ciclos')
            ->call('guardarCiclo')
            ->assertForbidden();
    }

    public function test_la_pestana_ciclos_de_configuracion_ya_no_rompe(): void
    {
        $this->comoPanel(self::ADMIN);

        $this->get(route('distribuidora.configuracion'))
            ->assertOk()
            ->assertDontSee('Ciclos de compra')
            ->assertDontSee('data-pestana-config="ciclos"', false)
            ->assertSee('Anticipos y plazos');

        Livewire::withQueryParams(['pestana' => 'ciclos'])
            ->test('distribuidora.configuracion')
            ->assertSet('pestanaActiva', 'general')
            ->assertSee('Anticipo por producto (MXN)');
    }
}
