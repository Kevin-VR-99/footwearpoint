<?php

namespace Tests\Feature\Publico;

use App\Models\Distribuidora;
use App\Services\Tienda\EnlaceTienda;
use App\Support\ConsentimientoCookies;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-401 (A38) — Banner de consentimiento de cookies.
 *
 * El «listo cuando» de la tarjeta son dos cosas y las dos se prueban aquí:
 * nada de terceros se carga antes de aceptar, y la elección se guarda y se
 * puede cambiar.
 */
class ConsentimientoCookiesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function tienda(): string
    {
        $ramirez = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
        $ramirez->update(['direccion_publica' => 'Av. Central 123, Comitán, Chiapas']);

        return app(EnlaceTienda::class)->url($ramirez);
    }

    // --- Nada de terceros antes de aceptar ---------------------------------

    /**
     * Lo más importante de la tarjeta.
     *
     * El único tercero del proyecto es el mapa de Google. Si su dirección
     * viaja en el src de un iframe, el navegador se conecta con Google sin
     * preguntarle nada a nadie. Tiene que venir guardada en un data-, que el
     * navegador no pide solo.
     */
    public function test_la_tienda_no_trae_el_mapa_dentro_de_un_iframe(): void
    {
        $html = $this->get($this->tienda())->assertOk()->getContent();

        $this->assertStringContainsString('data-mapa-con-permiso', $html, 'Ya no está el hueco del mapa.');

        $this->assertDoesNotMatchRegularExpression(
            '/<iframe[^>]*\bsrc\s*=\s*"[^"]*maps\.google/i',
            $html,
            'El mapa de Google vuelve a cargarse solo, sin que nadie lo permita.'
        );
    }

    /** Sin permiso, el hueco ofrece pedirlo ahí mismo. */
    public function test_el_hueco_del_mapa_tiene_su_propio_boton(): void
    {
        $this->get($this->tienda())
            ->assertOk()
            ->assertSee('data-permitir-mapa', false)
            ->assertSee('Mostrar el mapa');
    }

    // --- El servidor no decide ----------------------------------------------

    /**
     * La decisión es del navegador, no del servidor.
     *
     * El service worker guarda una copia del inicio y se la sirve igual a
     * todo el mundo (TG-282). Si el servidor escondiera o mostrara el banner
     * según la cookie, esa copia le enseñaría a una persona la decisión de
     * otra. Por eso el HTML tiene que ser el mismo con cookie y sin ella.
     */
    public function test_el_banner_sale_igual_con_y_sin_la_cookie(): void
    {
        $etiquetas = function (string $html): array {
            preg_match('/<div id="fp-consentimiento"[^>]*>/', $html, $barra);
            preg_match('/<button[^>]*data-abrir-consentimiento[^>]*>/', $html, $boton);

            return [$barra[0] ?? 'FALTA LA BARRA', $boton[0] ?? 'FALTA EL BOTON'];
        };

        $sinCookie = $etiquetas($this->get('/')->assertOk()->getContent());

        $conCookie = $etiquetas(
            $this->withCookie(ConsentimientoCookies::COOKIE, 'v1:necesarias,terceros')
                ->get('/')->assertOk()->getContent()
        );

        $this->assertSame(
            $sinCookie,
            $conCookie,
            'El servidor cambió el banner según la cookie: la copia guardada le enseñaría a alguien la decisión de otro.'
        );

        $this->assertStringContainsString('hidden', $conCookie[0], 'Con cookie el banner ya no sale escondido.');
    }

    /** Empieza escondido: lo enseña el navegador si todavía no hay decisión. */
    public function test_el_banner_sale_escondido_en_el_html(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<div id="fp-consentimiento"\s+hidden/',
            $html,
            'El banner ya no sale escondido: parpadearía en cada carga de quien ya decidió.'
        );
    }

    // --- Está donde tiene que estar -----------------------------------------

    public function test_el_banner_esta_en_las_pantallas_publicas_y_en_la_tienda(): void
    {
        foreach (['/', '/marketplace', $this->tienda()] as $ruta) {
            $this->get($ruta)
                ->assertOk()
                ->assertSee('id="fp-consentimiento"', false);
        }
    }

    /** «Se puede cambiar»: el botón del pie, en los dos layouts. */
    public function test_el_pie_deja_cambiar_la_decision(): void
    {
        foreach (['/', $this->tienda()] as $ruta) {
            $html = $this->get($ruta)->assertOk()->getContent();

            $this->assertStringContainsString('data-abrir-consentimiento', $html, "Falta el botón de preferencias en {$ruta}.");
            $this->assertStringContainsString('Preferencias de cookies', $html);
        }
    }

    /** Sin JavaScript ese botón no abriría nada: no puede verse. */
    public function test_el_boton_del_pie_sale_escondido(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-abrir-consentimiento\s+hidden/',
            $html,
            'El botón de preferencias se ve aunque no haya JavaScript, y así no abre nada.'
        );
    }

    // --- Lo que dice ---------------------------------------------------------

    /** Dice qué cookies usa, cuál es el tercero y dónde leer más. */
    public function test_el_banner_dice_la_verdad_y_enlaza_al_aviso(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('cookies técnicas')
            ->assertSee('mapa de Google')
            ->assertSee(route('aviso-privacidad'), false)
            ->assertSee('Solo las necesarias');
    }

    // --- La versión ----------------------------------------------------------

    /**
     * El banner lleva la versión de hoy.
     *
     * Es lo que deja que un permiso viejo caduque: si entra otro tercero se
     * sube la versión y el navegador vuelve a preguntar, porque un permiso
     * dado para un mapa no cubre lo que haya entrado después.
     */
    public function test_el_banner_lleva_la_version_vigente(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-version="'.ConsentimientoCookies::VERSION.'"', false);
    }

    /** El dominio lleva punto para que lo compartan el sitio y las tiendas. */
    public function test_el_dominio_de_la_cookie_cubre_los_subdominios(): void
    {
        config(['app.dominio_base' => 'footwearpoint.app']);
        $this->assertSame('.footwearpoint.app', ConsentimientoCookies::dominio());

        // En local no hay dominio base y el navegador no acepta uno: va vacío.
        config(['app.dominio_base' => null]);
        $this->assertSame('', ConsentimientoCookies::dominio());
    }
}
