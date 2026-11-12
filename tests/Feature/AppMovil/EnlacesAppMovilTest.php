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

    /**
     * TG-410: con el disco de verdad (sin Storage::fake), la descarga tiene
     * que ir a la URL pública de R2 y no a la de la API, que es privada.
     */
    public function test_sin_configurar_usa_la_url_publica_de_r2_y_no_la_privada(): void
    {
        // El disco toma la URL pública de AWS_URL, igual que el de las fotos.
        $this->assertArrayHasKey('url', config('filesystems.disks.productos'));
        $this->assertSame(config('filesystems.disks.s3.url'), config('filesystems.disks.productos.url'));

        config([
            'services.app_movil.descarga_url' => null,
            'footwearpoint.apk.disco' => 'productos',
            'filesystems.disks.productos.key' => 'llave-de-prueba',
            'filesystems.disks.productos.secret' => 'secreto-de-prueba',
            'filesystems.disks.productos.region' => 'auto',
            'filesystems.disks.productos.bucket' => 'bucket-de-prueba',
            'filesystems.disks.productos.endpoint' => 'https://cuenta.r2.cloudflarestorage.com',
            'filesystems.disks.productos.url' => 'https://pub-ejemplo.r2.dev',
        ]);
        Storage::forgetDisk('productos');

        $url = AppMovil::urlDescarga();

        $this->assertSame('https://pub-ejemplo.r2.dev/app-movil/footwearpoint.apk', $url);
        $this->assertStringNotContainsString('r2.cloudflarestorage.com', $url);
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
