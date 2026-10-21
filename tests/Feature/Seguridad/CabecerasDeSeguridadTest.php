<?php

namespace Tests\Feature\Seguridad;

use App\Http\Middleware\AgregarHsts;
use App\Http\Middleware\CabecerasDeSeguridad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cabeceras de seguridad en la web y en la API. */
class CabecerasDeSeguridadTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_web_y_la_api_traen_las_cabeceras_de_seguridad(): void
    {
        foreach (['https://localhost/login', 'https://localhost/api/marketplace'] as $url) {
            $respuesta = $this->get($url)->assertOk();

            foreach (CabecerasDeSeguridad::CABECERAS as $nombre => $valor) {
                $respuesta->assertHeader($nombre, $valor);
            }

            $respuesta->assertHeaderMissing('X-Powered-By');
        }
    }

    public function test_hsts_cubre_los_subdominios(): void
    {
        $this->assertStringContainsString('includeSubDomains', AgregarHsts::VALOR);

        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
