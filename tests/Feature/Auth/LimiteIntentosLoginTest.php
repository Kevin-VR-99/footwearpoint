<?php

namespace Tests\Feature\Auth;

use App\Services\Auth\LimiteIntentosLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-185 (A2) — Limite de intentos de inicio de sesion.
 *
 * En el recorrido del equipo (29-sep) se escribio diez veces la contrasena
 * equivocada sin que pasara nada, y despues, con la buena, entro igual.
 *
 * Se cubren las dos puertas (panel web y app) porque las dos usan el mismo
 * servicio y deben comportarse igual.
 */
class LimiteIntentosLoginTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Cuenta de personal que trae el seeder. */
    private const CORREO = 'empleado@calzadosramirez.test';

    /** Cuenta de la app que trae el seeder. */
    private const CORREO_APP = 'maria.lopez@revendedor.test';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login|'.self::CORREO.'|127.0.0.1');
        RateLimiter::clear('login|'.self::CORREO_APP.'|127.0.0.1');
    }

    private function intentarEnLaWeb(string $password, string $correo = self::CORREO)
    {
        return Livewire::test('auth.login')
            ->set('email', $correo)
            ->set('password', $password)
            ->call('login');
    }

    public function test_la_web_bloquea_despues_de_cinco_intentos_fallidos(): void
    {
        for ($i = 1; $i <= LimiteIntentosLogin::INTENTOS_MAXIMOS; $i++) {
            $this->intentarEnLaWeb('mala'.$i)
                ->assertHasErrors('email');
        }

        $componente = $this->intentarEnLaWeb('otra-mala');
        $errores = $componente->errors()->get('email');

        $this->assertNotEmpty($errores);
        $this->assertStringContainsString('Demasiados intentos', $errores[0]);
        $this->assertStringContainsString('segundos', $errores[0]);
    }

    public function test_estando_bloqueado_ni_con_la_contrasena_correcta_entra(): void
    {
        for ($i = 1; $i <= LimiteIntentosLogin::INTENTOS_MAXIMOS; $i++) {
            $this->intentarEnLaWeb('mala'.$i);
        }

        $this->intentarEnLaWeb('password')
            ->assertHasErrors('email')
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_al_pasar_la_espera_se_puede_entrar_de_nuevo(): void
    {
        for ($i = 1; $i <= LimiteIntentosLogin::INTENTOS_MAXIMOS; $i++) {
            $this->intentarEnLaWeb('mala'.$i);
        }

        $this->travel(LimiteIntentosLogin::SEGUNDOS_DE_ESPERA + 1)->seconds();

        $this->intentarEnLaWeb('password')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_un_login_correcto_borra_los_intentos_fallidos(): void
    {
        // Cuatro fallos, uno bueno, y vuelve a tener sus cinco intentos.
        for ($i = 1; $i <= 4; $i++) {
            $this->intentarEnLaWeb('mala'.$i);
        }

        $this->intentarEnLaWeb('password')->assertHasNoErrors();
        $this->post(route('logout'));

        for ($i = 1; $i <= 4; $i++) {
            $componente = $this->intentarEnLaWeb('mala-otra'.$i);
            $errores = $componente->errors()->get('email');

            $this->assertSame('Las credenciales son incorrectas.', $errores[0]);
        }
    }

    public function test_el_bloqueo_es_por_cuenta_y_no_alcanza_a_las_demas(): void
    {
        for ($i = 1; $i <= LimiteIntentosLogin::INTENTOS_MAXIMOS; $i++) {
            $this->intentarEnLaWeb('mala'.$i);
        }

        // El admin, desde la misma máquina, sigue pudiendo entrar.
        $this->intentarEnLaWeb('password', 'admin@calzadosramirez.test')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));
    }

    public function test_la_app_bloquea_despues_de_cinco_intentos_y_responde_429(): void
    {
        for ($i = 1; $i <= LimiteIntentosLogin::INTENTOS_MAXIMOS; $i++) {
            $this->postJson('/api/auth/login', [
                'email'    => self::CORREO_APP,
                'password' => 'mala'.$i,
            ])->assertStatus(422);
        }

        $respuesta = $this->postJson('/api/auth/login', [
            'email'    => self::CORREO_APP,
            'password' => 'password',
        ]);

        $respuesta->assertStatus(429);
        $this->assertStringContainsString('Demasiados intentos', $respuesta->json('message'));
    }

    public function test_la_app_vuelve_a_dejar_entrar_al_pasar_la_espera(): void
    {
        for ($i = 1; $i <= LimiteIntentosLogin::INTENTOS_MAXIMOS; $i++) {
            $this->postJson('/api/auth/login', [
                'email'    => self::CORREO_APP,
                'password' => 'mala'.$i,
            ]);
        }

        $this->travel(LimiteIntentosLogin::SEGUNDOS_DE_ESPERA + 1)->seconds();

        $this->postJson('/api/auth/login', [
            'email'    => self::CORREO_APP,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.rol', 'revendedor');
    }
}
