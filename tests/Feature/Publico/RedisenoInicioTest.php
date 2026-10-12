<?php

namespace Tests\Feature\Publico;

use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TG-234: la página de inicio rediseñada carga y muestra las redes configuradas. */
class RedisenoInicioTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    public function test_la_landing_carga_con_logo_contenido_y_anio(): void
    {
        config(['footwearpoint.redes' => ['instagram' => null, 'facebook' => null, 'youtube' => null]]);

        $this->get('/')
            ->assertOk()
            ->assertSee('brand/logo-mark-white-40.png', false)
            ->assertSee('Una distribuidora de calzado, ordenada de principio a fin')
            ->assertSee('Solicitar mi alta')
            ->assertSee('Ver marketplace')
            ->assertSee('Cómo funciona')
            ->assertSee('Contáctanos')
            ->assertSee('© '.now()->year.' FootwearPoint')
            ->assertDontSee('data-red=', false);
    }

    public function test_los_iconos_de_redes_aparecen_solo_con_url(): void
    {
        config(['footwearpoint.redes' => [
            'instagram' => 'https://instagram.com/footwearpoint',
            'facebook' => 'https://facebook.com/footwearpoint',
            'youtube' => null,
        ]]);

        $this->get('/')
            ->assertOk()
            ->assertSee('href="https://instagram.com/footwearpoint"', false)
            ->assertSee('data-red="instagram"', false)
            ->assertSee('data-red="facebook"', false)
            ->assertSee('aria-label="FootwearPoint en Instagram"', false)
            ->assertSee('rel="noopener"', false)
            ->assertDontSee('data-red="youtube"', false);
    }
}
