<?php

namespace Tests\Feature\Pwa;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-282 — Que FootwearPoint se pueda instalar.
 *
 * Lo que esto cubre y lo que no: comprueba que las piezas estén y se
 * enlacen, no que el navegador ofrezca "Instalar" — eso depende de que el
 * sitio vaya por https y lo decide Chrome, no nosotros.
 *
 * El panel NO funciona sin internet y no se pretende: son 61 pantallas de
 * Livewire, que se dibujan en el servidor. Lo que hay es que la app abra
 * rápido y que al quedarse sin señal se vea una página nuestra en vez del
 * error del navegador.
 */
class InstalableTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_manifiesto_se_sirve_y_dice_lo_que_debe(): void
    {
        $manifiesto = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame('FootwearPoint', $manifiesto['name']);
        $this->assertSame('/', $manifiesto['start_url']);

        // Sin "standalone" el navegador no ofrece instalarla.
        $this->assertSame('standalone', $manifiesto['display']);
        $this->assertSame('#2563EB', $manifiesto['theme_color']);

        // Chrome pide uno de 192 para considerarla instalable.
        $de192 = collect($manifiesto['icons'])->firstWhere('sizes', '192x192');
        $this->assertNotNull($de192, 'Falta el icono de 192 px.');
        $this->assertFileExists(public_path(ltrim($de192['src'], '/')));
    }

    public function test_todos_los_iconos_del_manifiesto_existen(): void
    {
        $manifiesto = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        foreach ($manifiesto['icons'] as $icono) {
            $this->assertFileExists(
                public_path(ltrim($icono['src'], '/')),
                "El manifiesto promete {$icono['src']} y no está.",
            );
        }
    }

    public function test_la_pagina_de_sin_conexion_abre_sin_sesion(): void
    {
        // Si pidiera login no podría dibujarse offline, que es justo cuando
        // hace falta.
        $this->get('/sin-conexion')
            ->assertOk()
            ->assertSee('Sin conexión')
            ->assertSee('Reintentar');
    }

    public function test_la_pagina_de_sin_conexion_no_depende_de_los_assets_compilados(): void
    {
        // Tiene sus estilos dentro: si dependiera del CSS compilado y ese no
        // estuviera en el caché, se vería en blanco.
        $html = $this->get('/sin-conexion')->getContent();

        $this->assertStringNotContainsString('/build/', $html);
        $this->assertStringContainsString('<style>', $html);
    }

    public function test_las_pantallas_enlazan_el_manifiesto(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('manifest.webmanifest', false)
            ->assertSee('theme-color', false);
    }

    public function test_el_service_worker_se_sirve_y_no_guarda_paginas_con_datos(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        // La regla que importa: FootwearPoint es multi-inquilino y una página
        // guardada podría enseñarle a una distribuidora datos de otra.
        $this->assertStringContainsString("peticion.mode === 'navigate'", $sw);
        $this->assertStringContainsString('/sin-conexion', $sw);

        // Un POST repetido desde el caché podría cobrar dos veces.
        $this->assertStringContainsString("peticion.method !== 'GET'", $sw);
    }

    public function test_el_service_worker_se_registra_desde_el_js_de_la_app(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js')", $js);
    }
}
