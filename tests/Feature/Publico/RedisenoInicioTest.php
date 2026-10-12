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
        config(['footwearpoint.redes' => ['instagram' => null, 'facebook' => null, 'youtube' => null, 'correo' => null]]);

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
            'correo' => null,
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

    public function test_el_correo_aparece_como_icono_redondo_con_mailto(): void
    {
        config(['footwearpoint.redes' => [
            'instagram' => null, 'facebook' => null,
            'youtube' => 'https://youtube.com/@footwearpoint',
            'correo' => 'mvpla.footwearpoint@gmail.com',
        ]]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-red="correo"', $html);
        $this->assertStringContainsString('href="mailto:mvpla.footwearpoint@gmail.com"', $html);
        $this->assertStringContainsString('aria-label="Escríbenos a mvpla.footwearpoint@gmail.com"', $html);

        // Mismo estilo y hover que el icono de YouTube.
        preg_match('/<a [^>]*data-red="youtube"[^>]*class="([^"]+)"/s', $html, $youtube);
        preg_match('/<a [^>]*data-red="correo"[^>]*class="([^"]+)"/s', $html, $correo);
        $this->assertNotEmpty($youtube);
        $this->assertSame($youtube[1], $correo[1]);
    }

    public function test_el_correo_no_se_repite_como_texto_si_ya_es_icono(): void
    {
        config([
            'footwearpoint.contacto.email' => 'mvpla.footwearpoint@gmail.com',
            'footwearpoint.redes.correo' => 'mvpla.footwearpoint@gmail.com',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'href="mailto:mvpla.footwearpoint@gmail.com"'));
        $this->assertStringContainsString('data-red="correo"', $html);
    }
}
