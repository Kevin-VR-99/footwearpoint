<?php

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use App\Services\Auth\EnviarEnlaceRestablecerPanelAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use Tests\TestCase as Base;

/**
 * TG-188 (A6) — Que el correo de recuperacion sirva de verdad.
 *
 * Las pruebas de TG-141 usan Notification::fake(), que comprueba que el
 * correo SALE pero no lee lo que dice. Por eso nadie habia visto que llegaba
 * con la plantilla de fabrica de Laravel: en ingles y firmado "Laravel".
 *
 * Aqui no se simula nada: en pruebas el correo va al transporte "array"
 * (phpunit.xml), asi que se puede abrir el mensaje armado, leerlo y sacarle
 * el enlace para seguirlo hasta el final. Mail::fake() no sirve para esto:
 * cuando el correo sale de una notificacion, Laravel le entrega al mailer la
 * vista ya armada y el falso no registra nada.
 */
class CorreoRecuperacionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CORREO = 'maria.lopez@revendedor.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** Los correos que de verdad se armaron en esta prueba. */
    private function correosEnviados()
    {
        return Mail::getSymfonyTransport()->messages();
    }

    private function correoEnviado(): Email
    {
        $mensajes = $this->correosEnviados();

        $this->assertCount(1, $mensajes, 'No salió el correo del enlace.');

        return $mensajes->first()->getOriginalMessage();
    }

    private function pedirEnlaceDesdeLaApp(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => self::CORREO])->assertOk();
    }

    /** Saca del correo el enlace que ve la persona. */
    private function enlaceDelCorreo(Email $correo): string
    {
        preg_match('#https?://[^\s"<]+/reset-password/[^\s"<]+#', (string) $correo->getHtmlBody(), $partes);

        $this->assertNotEmpty($partes, 'El correo no trae el enlace para restablecer.');

        return html_entity_decode($partes[0]);
    }

    public function test_el_correo_llega_en_espanol_y_con_el_nombre_de_footwearpoint(): void
    {
        $this->pedirEnlaceDesdeLaApp();

        $correo = $this->correoEnviado();
        $cuerpo = (string) $correo->getHtmlBody();

        $this->assertSame('Restablece tu contraseña de FootwearPoint', $correo->getSubject());
        $this->assertStringContainsString('Pediste cambiar tu contraseña de FootwearPoint', $cuerpo);
        $this->assertStringContainsString('Cambiar mi contraseña', $cuerpo);
        $this->assertStringContainsString('vence en 60 minutos', $cuerpo);
        $this->assertStringContainsString('Si no lo pediste, ignora este correo', $cuerpo);

        // Nada de la plantilla de fabrica de Laravel.
        $this->assertStringNotContainsString('Reset Password', $cuerpo);
        $this->assertStringNotContainsString('Regards', $cuerpo);
        $this->assertStringNotContainsString('Laravel', $cuerpo);
    }

    public function test_el_correo_va_dirigido_a_la_persona_por_su_nombre(): void
    {
        $this->pedirEnlaceDesdeLaApp();

        $correo = $this->correoEnviado();

        $this->assertSame(self::CORREO, $correo->getTo()[0]->getAddress());
        $this->assertSame('María López', $correo->getTo()[0]->getName());
        $this->assertStringContainsString('Hola María López', (string) $correo->getHtmlBody());
    }

    public function test_el_enlace_del_correo_abre_la_pagina_con_el_correo_ya_escrito(): void
    {
        $this->pedirEnlaceDesdeLaApp();

        $enlace = $this->enlaceDelCorreo($this->correoEnviado());

        // Tal cual: se abre la direccion que venia en el correo.
        $this->get($enlace)->assertOk();

        $this->assertStringContainsString('email=maria.lopez%40revendedor.test', $enlace);

        Livewire::withQueryParams(['email' => self::CORREO])
            ->test('auth.reset-password', ['token' => $this->tokenDe($enlace)])
            ->assertSet('email', self::CORREO);
    }

    public function test_siguiendo_el_enlace_se_cambia_la_contrasena_y_se_puede_entrar(): void
    {
        $this->pedirEnlaceDesdeLaApp();

        $token = $this->tokenDe($this->enlaceDelCorreo($this->correoEnviado()));

        Livewire::withQueryParams(['email' => self::CORREO])
            ->test('auth.reset-password', ['token' => $token])
            ->set('password', 'miClaveNueva123')
            ->set('password_confirmation', 'miClaveNueva123')
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        // La vieja ya no sirve; la nueva si, desde la app.
        $this->postJson('/api/auth/login', ['email' => self::CORREO, 'password' => 'password'])
            ->assertStatus(422);

        $this->postJson('/api/auth/login', ['email' => self::CORREO, 'password' => 'miClaveNueva123'])
            ->assertOk();
    }

    public function test_el_boton_del_panel_manda_el_mismo_correo(): void
    {
        // TG-192: el enlace que el personal manda desde Configuracion.
        $admin = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        $this->actingAs($admin);

        app(EnviarEnlaceRestablecerPanelAction::class)->ejecutar(
            Usuario::where('email', self::CORREO)->firstOrFail(),
            'revendedor',
            1,
        );

        $this->assertSame(
            'Restablece tu contraseña de FootwearPoint',
            $this->correoEnviado()->getSubject()
        );
    }

    public function test_a_un_correo_que_no_existe_no_se_le_manda_nada(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'nadie@no-existe.test'])
            ->assertOk();

        $this->assertCount(0, $this->correosEnviados());
    }

    /** El token viene en la direccion: /reset-password/{token}?email=... */
    private function tokenDe(string $enlace): string
    {
        preg_match('#/reset-password/([^/?]+)#', $enlace, $partes);

        $this->assertNotEmpty($partes, 'El enlace no trae token.');

        return $partes[1];
    }
}
