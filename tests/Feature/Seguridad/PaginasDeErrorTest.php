<?php

namespace Tests\Feature\Seguridad;

use App\Exceptions\RespuestaErrorApi;
use App\Models\Usuario;
use App\Support\MensajeProhibido;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * TG-264 (U4) - Páginas de error 403/404/419/429/500/503 en español, sin
 * detalles técnicos y con "Volver al inicio" según el host. La API sigue en JSON.
 */
class PaginasDeErrorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const PRINCIPAL = 'http://panel.ejemplo.test';

    private const BASE = 'footwearpoint.test';

    private const ADMIN = 'admin@calzadosramirez.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::PRINCIPAL]);
        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        Route::middleware('web')->group(function () {
            Route::get('/_prueba/500', fn () => throw new RuntimeException('SQLSTATE[HY000] detalle secreto'));
            Route::get('/_prueba/403-ingles', fn () => abort(403, 'Invalid signature.'));
            Route::get('/_prueba/{codigo}', fn (int $codigo) => abort($codigo))->whereNumber('codigo');
        });
    }

    private function enlaceInicio(string $html): string
    {
        $this->assertSame(1, preg_match('#<a href="([^"]*)"[^>]*>\s*Volver al inicio#', $html, $m), 'No está el botón "Volver al inicio"');

        return html_entity_decode($m[1]);
    }

    public function test_ruta_inexistente_da_404_en_espanol_con_enlace_al_dominio_principal(): void
    {
        $respuesta = $this->get(self::PRINCIPAL.'/esta-pagina-no-existe')
            ->assertNotFound()
            ->assertSee('Página no encontrada')
            ->assertDontSee('Not Found');

        $this->assertSame(self::PRINCIPAL, $this->enlaceInicio($respuesta->getContent()));
    }

    public function test_en_el_subdominio_de_una_tienda_vuelve_al_inicio_de_esa_tienda(): void
    {
        config(['app.dominio_base' => self::BASE]);

        $respuesta = $this->get('http://calzados-ramirez.'.self::BASE.'/productos/999999')
            ->assertNotFound();

        $this->assertSame('http://calzados-ramirez.'.self::BASE, $this->enlaceInicio($respuesta->getContent()));
    }

    public function test_con_sesion_vuelve_a_su_panel(): void
    {
        $this->actingAs(Usuario::where('email', self::ADMIN)->firstOrFail());

        $respuesta = $this->get(self::PRINCIPAL.'/esta-pagina-no-existe')->assertNotFound();

        $this->assertSame(route('dashboard'), $this->enlaceInicio($respuesta->getContent()));
    }

    public function test_403_por_rol_muestra_el_mensaje_en_espanol(): void
    {
        $this->actingAs(Usuario::where('email', self::ADMIN)->firstOrFail());

        $this->get('/admin')
            ->assertForbidden()
            ->assertSee('Acceso no permitido')
            ->assertSee(RespuestaErrorApi::SIN_PERMISO)
            ->assertDontSee('User does not have the right roles');
    }

    public function test_403_con_mensaje_en_ingles_muestra_el_generico(): void
    {
        $this->get('/_prueba/403-ingles')
            ->assertForbidden()
            ->assertSee('Acceso no permitido')
            ->assertSee(MensajeProhibido::GENERICO)
            ->assertDontSee('Invalid signature');
    }

    public function test_un_500_sin_debug_muestra_pagina_en_espanol_sin_detalles(): void
    {
        config(['app.debug' => false]);
        Exceptions::fake();

        $respuesta = $this->get('/_prueba/500')
            ->assertStatus(500)
            ->assertSee('Algo salió mal')
            ->assertSee('Volver al inicio');

        foreach (['SQLSTATE', 'detalle secreto', 'RuntimeException', '.php'] as $detalle) {
            $this->assertStringNotContainsString($detalle, $respuesta->getContent());
        }

        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_419_429_y_503_en_espanol(): void
    {
        $this->get('/_prueba/419')->assertStatus(419)->assertSee('La página expiró');
        $this->get('/_prueba/429')->assertStatus(429)->assertSee('Demasiados intentos');
        $this->get('/_prueba/503')->assertStatus(503)->assertSee('En mantenimiento');
    }

    public function test_la_api_sigue_respondiendo_json(): void
    {
        $this->get('/api/esta-ruta-no-existe')
            ->assertNotFound()
            ->assertExactJson(['message' => RespuestaErrorApi::NO_ENCONTRADO]);
    }
}
