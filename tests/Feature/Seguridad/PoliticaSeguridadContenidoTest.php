<?php

namespace Tests\Feature\Seguridad;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Seguridad oct-2026: CSP obligatoria por defecto (CSP_MODO=bloquear) con nonce; reportar = Report-Only. */
class PoliticaSeguridadContenidoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_por_defecto_la_csp_bloquea(): void
    {
        $this->assertSame('enforce', \App\Http\Middleware\PoliticaSeguridadContenido::modo());

        $respuesta = $this->get('/login')->assertOk();
        $csp = (string) $respuesta->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $csp);
        $this->assertStringContainsString('report-uri /api/csp-report', $csp);
        $respuesta->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_csp_modo_acepta_bloquear_y_reportar_en_espanol(): void
    {
        config(['app.csp_modo' => 'bloquear']);
        $this->assertSame('enforce', \App\Http\Middleware\PoliticaSeguridadContenido::modo());
        config(['app.csp_modo' => 'reportar']);
        $this->assertSame('report-only', \App\Http\Middleware\PoliticaSeguridadContenido::modo());
    }

    public function test_las_paginas_mandan_la_csp_report_only_con_un_nonce_que_usan_los_scripts(): void
    {
        config(['app.csp_modo' => 'reportar']);
        $respuesta = $this->get('/login')->assertOk();

        $this->assertFalse($respuesta->headers->has('Content-Security-Policy') && str_contains((string) $respuesta->headers->get('Content-Security-Policy'), 'script-src'));
        $csp = (string) $respuesta->headers->get('Content-Security-Policy-Report-Only');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $csp);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $m);

        foreach (["default-src 'self'", "object-src 'none'", "frame-ancestors 'self'", "base-uri 'self'", 'https://*.mercadopago.com', 'report-uri /api/csp-report'] as $parte) {
            $this->assertStringContainsString($parte, $csp);
        }

        // Todos los <script> de la página llevan el nonce de esta petición.
        preg_match_all('/<script\b[^>]*>/i', $respuesta->getContent(), $scripts);
        $this->assertNotEmpty($scripts[0]);
        foreach ($scripts[0] as $script) {
            $this->assertStringContainsString('nonce="'.$m[1].'"', $script);
        }
    }

    public function test_cada_peticion_tiene_un_nonce_distinto(): void
    {
        config(['app.csp_modo' => 'reportar']);
        $uno = $this->get('/login')->headers->get('Content-Security-Policy-Report-Only');
        $dos = $this->get('/login')->headers->get('Content-Security-Policy-Report-Only');

        $this->assertNotSame($uno, $dos);
    }

    public function test_las_respuestas_json_no_llevan_csp(): void
    {
        $this->getJson('/api/ping')->assertOk()->assertHeaderMissing('Content-Security-Policy-Report-Only')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_en_modo_enforce_la_politica_es_obligatoria(): void
    {
        config(['app.csp_modo' => 'enforce']);

        $respuesta = $this->get('/login')->assertOk();
        $this->assertStringContainsString("'nonce-", (string) $respuesta->headers->get('Content-Security-Policy'));
        $respuesta->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_el_reporte_del_navegador_queda_en_el_log(): void
    {
        Log::spy();

        $this->call('POST', '/api/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode([
            'csp-report' => [
                'document-uri'       => 'https://footwearpoint.test/login',
                'violated-directive' => 'script-src',
                'blocked-uri'        => 'https://malo.test/x.js',
            ],
        ]))->assertNoContent();

        Log::shouldHaveReceived('warning')->withArgs(fn ($mensaje, $contexto) => str_starts_with($mensaje, 'CSP:')
            && $contexto['bloqueado'] === 'https://malo.test/x.js' && $contexto['directiva'] === 'script-src');
    }

    public function test_con_https_upgrade_insecure_requests_va_en_su_propia_cabecera_en_report_only(): void
    {
        config(['app.url' => 'https://footwearpoint.test', 'app.csp_modo' => 'reportar']);

        $respuesta = $this->get('/login')->assertOk();

        $this->assertSame('upgrade-insecure-requests', $respuesta->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('upgrade-insecure-requests', (string) $respuesta->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_con_https_y_enforce_upgrade_insecure_requests_va_dentro_de_la_politica(): void
    {
        config(['app.url' => 'https://footwearpoint.test', 'app.csp_modo' => 'enforce']);

        $csp = (string) $this->get('/login')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'nonce-", $csp);
        $this->assertStringContainsString('upgrade-insecure-requests', $csp);
    }

    public function test_sin_https_no_se_pide_upgrade_insecure_requests(): void
    {
        config(['app.url' => 'http://localhost', 'app.csp_modo' => 'reportar']);

        $respuesta = $this->get('/login')->assertOk();

        $respuesta->assertHeaderMissing('Content-Security-Policy');
        $this->assertStringNotContainsString('upgrade-insecure-requests', (string) $respuesta->headers->get('Content-Security-Policy-Report-Only'));
    }
}
