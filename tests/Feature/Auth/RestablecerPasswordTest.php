<?php

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use App\Services\Auth\RestablecerPasswordAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-142 — Al restablecer la contraseña con el enlace del correo, se revocan
 * TODOS los tokens de Sanctum de la cuenta.
 *
 * El restablecimiento se hace sin sesión iniciada, así que no hay un
 * "dispositivo actual" que conservar: todos los celulares tienen que volver a
 * iniciar sesión.
 */
class RestablecerPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const MARIA = 'maria.lopez@revendedor.test';
    private const JOSE = 'jose.hernandez@cliente.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function usuario(string $email): Usuario
    {
        return Usuario::where('email', $email)->firstOrFail();
    }

    /** Un login real = un celular con la sesión abierta. */
    private function abrirSesion(string $email, string $password = 'password'): string
    {
        $token = $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])
            ->assertOk()
            ->json('data.token');

        $this->olvidarSesionEnMemoria();

        return $token;
    }

    private function olvidarSesionEnMemoria(): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** El token que iría dentro del enlace del correo. */
    private function tokenDelCorreo(string $email): string
    {
        return Password::broker('users')->createToken($this->usuario($email));
    }

    // ------------------------------------------------------------------
    // API (la app)
    // ------------------------------------------------------------------

    public function test_restablecer_revoca_las_sesiones_de_todos_los_celulares(): void
    {
        $celular1 = $this->abrirSesion(self::MARIA);
        $celular2 = $this->abrirSesion(self::MARIA);
        $this->assertSame(2, $this->usuario(self::MARIA)->tokens()->count());

        $this->postJson('/api/auth/reset-password', [
            'email'                 => self::MARIA,
            'token'                 => $this->tokenDelCorreo(self::MARIA),
            'password'              => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ])->assertOk();

        $this->assertSame(0, $this->usuario(self::MARIA)->tokens()->count());

        // Ninguno de los dos celulares puede seguir usando su sesión.
        foreach ([$celular1, $celular2] as $sesionVieja) {
            $this->olvidarSesionEnMemoria();
            $this->withToken($sesionVieja)->getJson('/api/auth/me')->assertStatus(401);
        }

        // Y con la contraseña nueva sí entra.
        $this->olvidarSesionEnMemoria();
        $this->abrirSesion(self::MARIA, 'clave-nueva-123');
    }

    /** Con un enlace falso o vencido no se cambia nada, ni se cierra nada. */
    public function test_con_un_enlace_invalido_no_se_revoca_ninguna_sesion(): void
    {
        $this->abrirSesion(self::MARIA);

        $this->postJson('/api/auth/reset-password', [
            'email'                 => self::MARIA,
            'token'                 => 'enlace-inventado',
            'password'              => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ])->assertStatus(422);

        $this->assertSame(1, $this->usuario(self::MARIA)->tokens()->count());
    }

    public function test_solo_se_revocan_las_sesiones_de_esa_cuenta(): void
    {
        $this->abrirSesion(self::MARIA);
        $this->abrirSesion(self::JOSE);

        $this->postJson('/api/auth/reset-password', [
            'email'                 => self::MARIA,
            'token'                 => $this->tokenDelCorreo(self::MARIA),
            'password'              => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ])->assertOk();

        $this->assertSame(1, $this->usuario(self::JOSE)->tokens()->count());
    }

    // ------------------------------------------------------------------
    // Sin revelar quién tiene cuenta (mismo criterio que TG-141)
    // ------------------------------------------------------------------

    /**
     * Antes, con un enlace inventado, un correo inexistente respondía "no
     * encontramos un usuario con ese correo" y uno registrado "el enlace no es
     * válido". Así se podía averiguar quién tiene cuenta sin tener ningún
     * enlace. Ahora la respuesta es idéntica.
     */
    public function test_con_un_enlace_inventado_la_respuesta_es_identica_exista_o_no_el_correo(): void
    {
        $datos = fn (string $email) => [
            'email'                 => $email,
            'token'                 => 'enlace-inventado',
            'password'              => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ];

        $existe = $this->postJson('/api/auth/reset-password', $datos(self::MARIA));
        $noExiste = $this->postJson('/api/auth/reset-password', $datos('nadie@no-existe.test'));

        $this->assertSame(422, $existe->status());
        $this->assertSame($existe->status(), $noExiste->status());
        $this->assertSame($existe->json(), $noExiste->json());

        $noExiste->assertJsonPath('errors.token.0', RestablecerPasswordAction::MENSAJE_ENLACE_INVALIDO);
    }

    public function test_en_la_web_el_error_es_el_mismo_exista_o_no_el_correo(): void
    {
        foreach ([self::MARIA, 'nadie@no-existe.test'] as $email) {
            Livewire::test('auth.reset-password')
                ->set('token', 'enlace-inventado')
                ->set('email', $email)
                ->set('password', 'clave-nueva-123')
                ->set('password_confirmation', 'clave-nueva-123')
                ->call('resetPassword')
                ->assertHasErrors(['email'])
                ->assertSee(RestablecerPasswordAction::MENSAJE_ENLACE_INVALIDO);
        }
    }

    // ------------------------------------------------------------------
    // Pantalla web (a donde lleva el enlace del correo)
    // ------------------------------------------------------------------

    public function test_restablecer_desde_la_pantalla_web_tambien_revoca_las_sesiones(): void
    {
        $this->abrirSesion(self::MARIA);
        $this->abrirSesion(self::MARIA);

        Livewire::test('auth.reset-password')
            ->set('token', $this->tokenDelCorreo(self::MARIA))
            ->set('email', self::MARIA)
            ->set('password', 'clave-nueva-123')
            ->set('password_confirmation', 'clave-nueva-123')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $this->assertSame(0, $this->usuario(self::MARIA)->tokens()->count());
    }
}
