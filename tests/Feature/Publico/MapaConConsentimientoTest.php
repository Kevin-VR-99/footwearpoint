<?php

namespace Tests\Feature\Publico;

use App\Support\ConsentimientoCookies;
use App\Support\DocumentosLegales;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-402 (A39) — El aviso dice la verdad sobre el mapa y los terceros.
 *
 * El código ya quedó en TG-401: el mapa no se carga sin permiso. Lo que
 * prueba este archivo es lo otro, que es igual de importante y se olvida más
 * fácil: que el AVISO diga lo que el sistema hace de verdad.
 *
 * Un aviso que describe un comportamiento que ya cambió no es un descuido de
 * redacción: es el documento con el que la gente decide si acepta.
 */
class MapaConConsentimientoTest extends TestCase
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

    /** El texto como lo lee la persona: sin etiquetas y con espacios simples. */
    private function texto(): string
    {
        $html = $this->get(route('aviso-privacidad'))->assertOk()->getContent();
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    private function assertDice(string ...$frases): void
    {
        $texto = $this->texto();

        foreach ($frases as $frase) {
            $this->assertStringContainsString($frase, $texto, "El aviso debería decir «{$frase}»");
        }
    }

    // --- El mapa ------------------------------------------------------------

    /** Lo que decía antes era que el mapa se conectaba con Google al cargar. */
    public function test_dice_que_el_mapa_no_se_carga_solo(): void
    {
        $this->assertDice(
            'El mapa no se carga solo',
            'hasta que tú lo presionas tu navegador no se conecta con Google',
        );
    }

    /** «Cómo llegar» es un enlace, y eso no es lo mismo que cargar un mapa. */
    public function test_distingue_el_mapa_insertado_de_los_enlaces_a_google(): void
    {
        $this->assertDice('son enlaces: solo abren el sitio de Google si tú los tocas');
    }

    /**
     * Google Maps no es encargado, y la diferencia importa.
     *
     * Un encargado trata datos por cuenta nuestra; con el mapa es la persona
     * la que se conecta directamente con Google y nosotros ni intervenimos ni
     * recibimos nada. Meterlo en la misma lista sería decir que lo
     * controlamos, y no es cierto.
     */
    public function test_separa_el_mapa_de_la_lista_de_encargados(): void
    {
        $this->assertDice(
            'El mapa de Google Maps no entra en esa lista',
            'Google no trata esos datos por cuenta nuestra',
        );
    }

    // --- La cookie del consentimiento ---------------------------------------

    public function test_declara_la_cookie_que_guarda_la_decision(): void
    {
        $this->assertDice(
            'si permites o no el contenido de terceros',
            'dura seis meses',
        );
    }

    /**
     * El texto dice «seis meses» y el código dice 180 días.
     *
     * Si alguien cambia la duración en el código, el aviso queda mintiendo y
     * nadie se entera, porque el aviso no se vuelve a leer. Esta prueba los
     * amarra: cambiar DIAS obliga a pasar por aquí.
     */
    public function test_los_seis_meses_del_aviso_son_los_del_codigo(): void
    {
        $this->assertSame(
            180,
            ConsentimientoCookies::DIAS,
            'Cambió la duración de la cookie: el aviso dice «seis meses» y hay que actualizarlo.'
        );
    }

    // --- Dónde se guarda cada cosa ------------------------------------------

    /**
     * TG-411 movió los comprobantes de transferencia a R2.
     *
     * OJO AL DESPLEGAR: el disco se elige con COMPROBANTES_DISK, que por
     * defecto vale 'local'. Este aviso solo es cierto con la variable puesta
     * en Railway (ver docs/railway-produccion.md).
     */
    public function test_dice_que_los_comprobantes_estan_en_r2_y_ya_no_en_railway(): void
    {
        $texto = $this->texto();

        $this->assertStringContainsString(
            'respaldos y comprobantes de transferencia',
            $texto,
            'R2 debería incluir los comprobantes (TG-411).'
        );

        $this->assertStringNotContainsString(
            'plataforma, donde también se guardan los comprobantes',
            $texto,
            'Railway ya no guarda los comprobantes: quedó la frase vieja.'
        );
    }

    // --- Cómo se cambia de opinión -------------------------------------------

    public function test_explica_como_retirar_el_permiso_sin_escribir_a_nadie(): void
    {
        $this->assertDice('cambiar tu permiso para el contenido de terceros desde «Preferencias de cookies»');
    }

    // --- La versión -----------------------------------------------------------

    public function test_el_aviso_subio_a_la_version_nueva(): void
    {
        $this->assertSame('1.4', DocumentosLegales::version(DocumentosLegales::AVISO));
        $this->assertDice('Versión 1.4');
    }

    /**
     * La huella de una versión ya publicada no se toca.
     *
     * Hubo personas que aceptaron la 1.3 con ese texto exacto. Si alguien
     * cambia esa huella para «arreglar» una prueba, esas aceptaciones pasan a
     * referirse a un texto que nadie aceptó.
     */
    public function test_la_huella_de_la_1_3_sigue_siendo_la_publicada(): void
    {
        $huellas = require base_path('tests/Feature/Legales/huellas.php');

        $this->assertSame(
            '5d8abcc0bf722e6b80cac0a0200fe854180b5ad48ec56f97fb6f834f51524e93',
            $huellas['aviso_privacidad']['1.3'] ?? null,
            'Cambió la huella de una versión ya publicada: eso invalida las aceptaciones que ya existen.'
        );
    }
}
