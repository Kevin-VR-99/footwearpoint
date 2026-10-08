<?php

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use App\Services\Auth\EnviarEnlaceRecuperacionAction;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-141 — Recuperar contraseña sin revelar quién tiene cuenta.
 *
 * Antes, un correo inexistente recibía otra respuesta (422 en la API, error
 * rojo en la web), y con eso se podía averiguar qué correos están
 * registrados. Ahora la respuesta es la misma siempre.
 *
 * El correo se simula con Notification::fake(): así se comprueba que el
 * enlace sí sale cuando corresponde, sin depender de un servidor de correo.
 */
class RecuperarPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CORREO_EXISTENTE = 'empleado@calzadosramirez.test';
    private const CORREO_INEXISTENTE = 'nadie@no-existe.test';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    // ------------------------------------------------------------------
    // API (app móvil)
    // ------------------------------------------------------------------

    public function test_con_un_correo_que_existe_responde_el_mensaje_neutro_y_si_manda_el_enlace(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => self::CORREO_EXISTENTE])
            ->assertOk()
            ->assertExactJson(['message' => EnviarEnlaceRecuperacionAction::MENSAJE]);

        Notification::assertSentTo(
            Usuario::where('email', self::CORREO_EXISTENTE)->firstOrFail(),
            ResetPassword::class
        );
    }

    public function test_con_un_correo_que_no_existe_responde_igual_y_no_manda_nada(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => self::CORREO_INEXISTENTE])
            ->assertOk()
            ->assertExactJson(['message' => EnviarEnlaceRecuperacionAction::MENSAJE]);

        Notification::assertNothingSent();
    }

    /**
     * La prueba de fondo: desde fuera no hay ninguna diferencia entre un
     * correo registrado y uno que no lo está. Ni el código ni el contenido.
     */
    public function test_la_respuesta_es_identica_exista_o_no_el_correo(): void
    {
        $existe = $this->postJson('/api/auth/forgot-password', ['email' => self::CORREO_EXISTENTE]);
        $noExiste = $this->postJson('/api/auth/forgot-password', ['email' => self::CORREO_INEXISTENTE]);

        $this->assertSame($existe->status(), $noExiste->status());
        $this->assertSame($existe->json(), $noExiste->json());
    }

    /**
     * Pedirlo dos veces seguidas: Laravel frena el segundo envío. Si eso se
     * notara en la respuesta, también delataría la cuenta, porque a un
     * correo inexistente nunca se le pide esperar.
     */
    public function test_pedir_el_enlace_dos_veces_seguidas_tambien_responde_igual(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => self::CORREO_EXISTENTE])->assertOk();

        $this->postJson('/api/auth/forgot-password', ['email' => self::CORREO_EXISTENTE])
            ->assertOk()
            ->assertExactJson(['message' => EnviarEnlaceRecuperacionAction::MENSAJE]);

        // Y de verdad se frenó: solo salió un correo.
        Notification::assertSentToTimes(
            Usuario::where('email', self::CORREO_EXISTENTE)->firstOrFail(),
            ResetPassword::class,
            1
        );
    }

    /** Un correo mal escrito sí da error: eso no revela nada de las cuentas. */
    public function test_un_correo_mal_escrito_si_da_error_de_validacion(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'esto-no-es-un-correo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ------------------------------------------------------------------
    // Pantalla web
    // ------------------------------------------------------------------

    public function test_en_la_web_un_correo_que_no_existe_muestra_el_mismo_aviso_sin_error(): void
    {
        Livewire::test('auth.forgot-password')
            ->set('email', self::CORREO_INEXISTENTE)
            ->call('sendResetLink')
            ->assertHasNoErrors()
            ->assertSet('status', EnviarEnlaceRecuperacionAction::MENSAJE);

        Notification::assertNothingSent();
    }

    public function test_en_la_web_un_correo_que_existe_muestra_el_mismo_aviso_y_manda_el_enlace(): void
    {
        Livewire::test('auth.forgot-password')
            ->set('email', self::CORREO_EXISTENTE)
            ->call('sendResetLink')
            ->assertHasNoErrors()
            ->assertSet('status', EnviarEnlaceRecuperacionAction::MENSAJE);

        Notification::assertSentTo(
            Usuario::where('email', self::CORREO_EXISTENTE)->firstOrFail(),
            ResetPassword::class
        );
    }
}
