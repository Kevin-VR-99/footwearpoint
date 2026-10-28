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

    /**
     * El botón de Reintentar tiene que responder de verdad.
     *
     * Con onclick="" no lo hacía: el CSP bloquea los manejadores puestos en
     * atributo y los nonces no los cubren, solo cubren los <script>. Quedaba
     * muerto justo en la única pantalla que se ve sin internet.
     */
    public function test_el_boton_de_reintentar_no_lo_bloquea_el_csp(): void
    {
        $respuesta = $this->get('/sin-conexion')->assertOk();
        $html = $respuesta->getContent();

        $this->assertStringNotContainsString('onclick=', $html);

        $encontrado = preg_match('/<script\b[^>]*\bnonce="([^"]+)"/', $html, $coincidencias);

        $this->assertSame(1, $encontrado, 'El script del botón no lleva nonce: el CSP lo bloquea.');

        $this->assertStringContainsString(
            "'nonce-{$coincidencias[1]}'",
            (string) $respuesta->headers->get('Content-Security-Policy'),
        );
    }

    public function test_las_pantallas_enlazan_el_manifiesto(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('manifest.webmanifest', false)
            ->assertSee('theme-color', false);
    }

    public function test_el_service_worker_respeta_las_reglas_que_lo_hacen_seguro(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        // Un POST repetido desde el caché podría cobrar dos veces.
        $this->assertStringContainsString("peticion.method !== 'GET'", $sw);

        // Nada de otros dominios: el bucket de las fotos, el API de la IA.
        $this->assertStringContainsString('url.origin !== self.location.origin', $sw);

        // Y queda la página de sin conexión para lo que no se puede guardar.
        $this->assertStringContainsString('/sin-conexion', $sw);
    }

    /**
     * La regla que de verdad protege: el inicio se guarda SIN cookies.
     *
     * Con sesión, el inicio enseña un enlace al panel de quien entró, y para
     * armarlo resuelve a qué distribuidora pertenece (PanelDelUsuario::para).
     * Guardar esa versión significaría poder enseñársela a otra persona.
     */
    public function test_el_inicio_se_guarda_en_su_version_anonima(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("credentials: 'omit'", $sw);

        // Y se guarda llamando siempre a la misma función, no copiando la
        // respuesta que acaba de llegar, que sí puede traer sesión.
        $this->assertStringContainsString('guardarInicioAnonimo', $sw);
    }

    public function test_el_inicio_sin_sesion_no_enseña_el_panel_de_nadie(): void
    {
        // Lo que el service worker va a guardar es justo esto.
        $this->get('/')
            ->assertOk()
            ->assertDontSee('Ir a mi panel');
    }

    public function test_el_inicio_avisa_cuando_se_ve_sin_conexion(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('estás viendo una copia guardada', false)
            // El aviso no puede depender del JS compilado: offline puede no
            // estar en el caché.
            ->assertSee('navigator.onLine', false);
    }

    /**
     * El CSP de producción exige nonce en TODO <script>.
     *
     * Sin él el navegador lo bloquea y la franja no aparece nunca. Pasó: el
     * aviso viajó a producción sin nonce y lo delató un csp-report.
     */
    public function test_el_aviso_sin_conexion_lleva_el_nonce_del_csp(): void
    {
        $respuesta = $this->get('/')->assertOk();

        $encontrado = preg_match(
            '/<script\b[^>]*\bnonce="([^"]+)"[^>]*>(?:(?!<\/script>).)*navigator\.onLine/s',
            $respuesta->getContent(),
            $coincidencias,
        );

        $this->assertSame(1, $encontrado, 'El script del aviso no lleva nonce: el CSP lo bloquea.');

        // Y tiene que ser el nonce de ESTA petición: uno viejo no sirve.
        $this->assertStringContainsString(
            "'nonce-{$coincidencias[1]}'",
            (string) $respuesta->headers->get('Content-Security-Policy'),
        );
    }

    /**
     * El aviso no puede depender de que el navegador admita que no hay red.
     *
     * navigator.onLine dice "sí hay" cuando el aparato sigue pegado a un wifi
     * que no llega a ningún lado, y ahí es justo cuando se entrega la copia.
     * Por eso el service worker le quita el "hidden" al HTML guardado. Si ese
     * trozo cambia en el layout y no en el sw, la franja deja de salir sin
     * que nada falle ni avise.
     */
    public function test_el_service_worker_sabe_descubrir_la_franja_del_aviso(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $encontrado = preg_match("/const MARCA_DEL_AVISO = '([^']+)'/", $sw, $coincidencias);

        $this->assertSame(1, $encontrado, 'El service worker ya no trae MARCA_DEL_AVISO.');

        // Y ese trozo tiene que existir tal cual en lo que se guarda.
        $this->assertStringContainsString($coincidencias[1], $this->get('/')->getContent());
    }

    public function test_el_service_worker_se_registra_desde_el_js_de_la_app(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js')", $js);
    }
}
