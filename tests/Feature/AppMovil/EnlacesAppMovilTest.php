<?php

namespace Tests\Feature\AppMovil;

use App\Support\AppMovil;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Botones «Abrir la app» / «Descargar la app» de las páginas de confirmación. */
class EnlacesAppMovilTest extends TestCase
{
    public function test_usa_la_url_configurada(): void
    {
        config(['services.app_movil.descarga_url' => 'https://descargas.ejemplo.test/fp']);

        $this->assertSame('https://descargas.ejemplo.test/fp', AppMovil::urlDescarga());
    }

    public function test_sin_configurar_usa_el_apk_de_r2(): void
    {
        config(['services.app_movil.descarga_url' => null]);
        Storage::fake(config('footwearpoint.apk.disco', 's3'));

        $this->assertStringContainsString('app-movil/footwearpoint.apk', AppMovil::urlDescarga());
    }

    public function test_abrir_la_app_cae_a_la_descarga(): void
    {
        config(['services.app_movil.descarga_url' => 'https://descargas.ejemplo.test/fp']);

        $url = AppMovil::urlAbrir('perfil');

        $this->assertStringStartsWith('intent://footwearpoint.app/abrir/perfil#Intent;', $url);
        $this->assertStringContainsString('package=com.footwearpoint.app', $url);
        $this->assertStringContainsString('S.browser_fallback_url='.rawurlencode('https://descargas.ejemplo.test/fp'), $url);
    }

    public function test_los_botones_se_dibujan(): void
    {
        config(['services.app_movil.descarga_url' => 'https://descargas.ejemplo.test/fp']);

        $html = (string) $this->blade('<x-app-movil.botones destino="perfil" />');

        $this->assertStringContainsString('data-abrir-app', $html);
        $this->assertStringContainsString('href="https://descargas.ejemplo.test/fp"', $html);
    }
}
