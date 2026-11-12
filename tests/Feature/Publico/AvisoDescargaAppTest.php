<?php

namespace Tests\Feature\Publico;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TG-403 A40 — El aviso de descarga de la app, en el inicio público.
 *
 * Son dos enlaces y cada uno puede faltar por su cuenta:
 *
 * - El APK directo sale del bucket de R2. En un entorno sin R2 configurado,
 *   pedirle la URL al disco revienta.
 * - La ficha de Uptodown sale de APP_MOVIL_DESCARGA_URL, y esa variable está
 *   vacía hasta que Uptodown publique la app (ellos la revisan antes). Hoy,
 *   11 de noviembre de 2026, sigue vacía en producción.
 *
 * Por eso cada enlace aparece solo si existe, y el aviso entero desaparece si
 * no hay ninguno: un botón a una URL vacía es peor que no tener botón.
 */
class AvisoDescargaAppTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Finge que R2 responde, que es lo que pasa en producción. */
    private function conApk(): void
    {
        Storage::fake('productos');
        config()->set('footwearpoint.apk.disco', 'productos');
    }

    private function conTienda(?string $url): void
    {
        config()->set('services.app_movil.descarga_url', $url);
    }

    public function test_con_los_dos_enlaces_salen_los_dos(): void
    {
        $this->conApk();
        $this->conTienda('https://footwear-point.uptodown.com/android');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-descarga-app', $html);
        $this->assertStringContainsString('¿Eres cliente mayorista?', $html);
        $this->assertStringContainsString('https://footwear-point.uptodown.com/android', $html);
        $this->assertStringContainsString('data-descarga="apk"', $html);
        $this->assertStringContainsString('data-descarga="uptodown"', $html);
    }

    /** El caso de hoy: la app está subida pero Uptodown todavía no la publica. */
    public function test_sin_la_ficha_de_uptodown_queda_solo_el_apk_y_su_aviso(): void
    {
        $this->conApk();
        $this->conTienda(null);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-descarga="apk"', $html);
        $this->assertStringNotContainsString('data-descarga="uptodown"', $html);

        // Instalar un APK suelto pide permiso en Android; se avisa antes.
        $this->assertStringContainsString('permiso para instalar apps', $html);
    }

    /** Una variable con espacios es como no tenerla. */
    public function test_una_ficha_en_blanco_no_pinta_un_boton_vacio(): void
    {
        $this->conApk();
        $this->conTienda('   ');

        $this->assertStringNotContainsString('data-descarga="uptodown"', $this->get('/')->getContent());
    }

    public function test_sin_ningun_enlace_el_aviso_entero_desaparece(): void
    {
        // Disco inexistente: pedirle la URL revienta, igual que sin R2.
        config()->set('footwearpoint.apk.disco', 'no-existe-este-disco');
        $this->conTienda(null);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-descarga-app', $html);
        $this->assertStringNotContainsString('¿Eres cliente mayorista?', $html);
    }

    /**
     * El aviso no puede depender del navegador de quien entra.
     *
     * El service worker (TG-282) guarda una copia ANÓNIMA de este inicio y se
     * la entrega a cualquiera cuando no hay internet. Si el aviso cambiara
     * según el teléfono, la copia guardada le enseñaría a uno lo que le toca
     * a otro.
     */
    public function test_sale_igual_en_cualquier_navegador(): void
    {
        $this->conApk();
        $this->conTienda('https://footwear-point.uptodown.com/android');

        $android = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Chrome/120')->get('/')->getContent();
        $iphone = $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604')->get('/')->getContent();

        foreach ([$android, $iphone] as $html) {
            $this->assertStringContainsString('data-descarga="apk"', $html);
            $this->assertStringContainsString('data-descarga="uptodown"', $html);
            // Y se dice con palabras para quien no pueda instalarla.
            $this->assertStringContainsString('Solo Android', $html);
        }
    }
}
