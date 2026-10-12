<?php

namespace Tests\Feature\Tienda;

use App\Services\Tienda\DominioTienda;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

/**
 * TG-232 — Hosts de confianza con FOOTWEARPOINT_DOMINIO: el dominio de
 * tiendas, el de APP_URL y la dirección pública de Railway (APK, webhooks).
 */
class HostsDeConfianzaTest extends TestCase
{
    private const RAILWAY = 'footwearpoint-production.up.railway.app';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.dominio_base'    => 'footwearpoint.app',
            'app.url'             => 'https://footwearpoint.app',
            'app.railway_dominio' => self::RAILWAY,
            'app.hosts_extra'     => [],
        ]);
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    private function aceptado(string $host): bool
    {
        return collect(DominioTienda::hostsDeConfianza())
            ->contains(fn (string $patron) => preg_match('{'.$patron.'}i', $host) === 1);
    }

    public function test_incluye_railway_cuando_esta_definido(): void
    {
        $this->assertContains('^'.preg_quote(self::RAILWAY).'$', DominioTienda::hostsDeConfianza());
        $this->assertTrue($this->aceptado(self::RAILWAY));
        // El punto va escapado: no acepta variantes.
        $this->assertFalse($this->aceptado('footwearpoint-productionXup.railway.app'));
        $this->assertFalse($this->aceptado('evil.'.self::RAILWAY.'.com'));
    }

    public function test_sin_railway_no_se_agrega(): void
    {
        foreach ([null, ''] as $vacio) {
            config(['app.railway_dominio' => $vacio]);

            $this->assertFalse($this->aceptado(self::RAILWAY));
            $this->assertCount(3, DominioTienda::hostsDeConfianza());
        }
    }

    public function test_siguen_el_dominio_base_sus_subdominios_y_app_url(): void
    {
        config(['app.url' => 'https://panel.ejemplo.test']);

        foreach (['footwearpoint.app', 'calzados-ramirez.footwearpoint.app', 'panel.ejemplo.test', 'healthcheck.railway.app', self::RAILWAY] as $host) {
            $this->assertTrue($this->aceptado($host), $host);
        }

        $this->assertFalse($this->aceptado('evil.com'));
        $this->assertFalse($this->aceptado('footwearpoint.app.evil.com'));
    }

    public function test_hosts_extra_se_aceptan(): void
    {
        // En Railway, RAILWAY_PUBLIC_DOMAIN ya es el dominio propio.
        config(['app.railway_dominio' => 'footwearpoint.app', 'app.hosts_extra' => [self::RAILWAY]]);

        $this->assertTrue($this->aceptado(self::RAILWAY));
        $this->assertFalse($this->aceptado('otro.up.railway.app'));
    }

    public function test_sin_dominio_base_no_se_restringe(): void
    {
        config(['app.dominio_base' => null]);

        $this->assertSame([], DominioTienda::hostsDeConfianza());
    }

    public function test_en_produccion_acepta_railway_y_rechaza_un_host_ajeno(): void
    {
        // Laravel no aplica TrustHosts en pruebas; aquí se fuerza como en producción.
        $middleware = new class($this->app) extends TrustHosts {
            protected function shouldSpecifyTrustedHosts()
            {
                return true;
            }
        };

        $railway = Request::create('https://'.self::RAILWAY.'/api/marketplace');
        $middleware->handle($railway, fn () => response('ok'));
        $this->assertSame(self::RAILWAY, $railway->getHost());

        $tienda = Request::create('https://calzados-ramirez.footwearpoint.app/');
        $this->assertSame('calzados-ramirez.footwearpoint.app', $tienda->getHost());

        $ajeno = Request::create('https://evil.com/');
        $this->expectException(SuspiciousOperationException::class);
        $ajeno->getHost();
    }
}
