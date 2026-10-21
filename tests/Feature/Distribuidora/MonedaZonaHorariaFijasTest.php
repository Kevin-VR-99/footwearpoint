<?php

namespace Tests\Feature\Distribuidora;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Moneda y zona horaria son fijas: MXN / America/Mexico_City. */
class MonedaZonaHorariaFijasTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function comoAdmin(): Usuario
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $usuario = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        $this->actingAs($usuario);
        Tenant::olvidarCache();

        return $usuario;
    }

    public function test_la_pantalla_de_configuracion_ya_no_muestra_moneda_ni_zona_horaria(): void
    {
        $this->comoAdmin();

        Livewire::test('distribuidora.general')
            ->assertDontSeeHtml('general-moneda')
            ->assertDontSeeHtml('general-zona-horaria')
            ->assertSeeHtml('general-dias-vigencia-vale');
    }

    public function test_guardar_ignora_moneda_y_zona_si_llegan(): void
    {
        $this->comoAdmin();
        ConfiguracionDistribuidora::query()->update(['moneda' => 'MXN', 'zona_horaria' => 'America/Mexico_City']);

        $this->patchJson('/api/distribuidora/config', [
            'moneda' => 'USD',
            'zona_horaria' => 'Europe/Madrid',
            'dias_vigencia_vale' => 45,
        ])->assertOk()
            ->assertJsonPath('data.moneda', 'MXN')
            ->assertJsonPath('data.zona_horaria', 'America/Mexico_City')
            ->assertJsonPath('data.dias_vigencia_vale', 45);

        $config = ConfiguracionDistribuidora::query()->firstOrFail();
        $this->assertSame('MXN', $config->getRawOriginal('moneda'));
        $this->assertSame('America/Mexico_City', $config->getRawOriginal('zona_horaria'));
    }

    public function test_aunque_la_base_tenga_otro_valor_siempre_se_lee_mxn_y_cdmx(): void
    {
        $this->comoAdmin();
        ConfiguracionDistribuidora::query()->update(['moneda' => 'usd', 'zona_horaria' => 'UTC']);

        $this->getJson('/api/distribuidora/config')
            ->assertOk()
            ->assertJsonPath('data.moneda', 'MXN')
            ->assertJsonPath('data.zona_horaria', 'America/Mexico_City');

        $config = ConfiguracionDistribuidora::query()->firstOrFail();
        $this->assertSame('MXN', $config->moneda);
        $this->assertSame('America/Mexico_City', $config->zona_horaria);
    }
}
