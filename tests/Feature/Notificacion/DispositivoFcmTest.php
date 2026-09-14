<?php

namespace Tests\Feature\Notificacion;

use App\Models\DispositivoFcm;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E16-03 (TG-136) — Registrar el celular para recibir notificaciones push.
 *
 * Criterios de la historia:
 *   1. El token del dispositivo se guarda ligado a mi usuario.
 *   2. El token se puede invalidar al cerrar sesión.
 *
 * Se usan las cuentas de demo de la app (María y José) y tokens de sesión
 * reales sacados del login, porque el logout necesita un token de verdad.
 */
class DispositivoFcmTest extends TestCase
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

    private function iniciarSesion(string $email): string
    {
        $token = $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()
            ->json('data.token');

        $this->olvidarSesionEnMemoria();

        return $token;
    }

    /** Cada petición se autentica solo con su token, como en la vida real. */
    private function olvidarSesionEnMemoria(): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function registrar(string $sesion, string $tokenFcm, string $plataforma = 'android')
    {
        $respuesta = $this->withToken($sesion)->postJson('/api/dispositivos-fcm', [
            'token'      => $tokenFcm,
            'plataforma' => $plataforma,
        ]);

        $this->olvidarSesionEnMemoria();

        return $respuesta;
    }

    private function idDe(string $email): int
    {
        return Usuario::where('email', $email)->firstOrFail()->id;
    }

    // ------------------------------------------------------------------
    // Criterio 1: el token se guarda ligado a mi usuario
    // ------------------------------------------------------------------

    public function test_registrar_el_celular_lo_guarda_ligado_al_usuario(): void
    {
        $sesion = $this->iniciarSesion(self::MARIA);

        $this->registrar($sesion, 'token-celular-maria')
            ->assertStatus(201)
            ->assertJsonPath('data.plataforma', 'android');

        $this->assertDatabaseHas('dispositivos_fcm', [
            'token'      => 'token-celular-maria',
            'usuario_id' => $this->idDe(self::MARIA),
            'plataforma' => 'android',
        ]);
    }

    public function test_registrar_el_mismo_token_otra_vez_no_duplica_y_actualiza_el_ultimo_uso(): void
    {
        $sesion = $this->iniciarSesion(self::MARIA);

        $this->travelTo(now()->subDay());
        $this->registrar($sesion, 'token-celular-maria')->assertStatus(201);
        $this->travelBack();

        $this->registrar($sesion, 'token-celular-maria')->assertOk();

        $this->assertSame(1, DispositivoFcm::where('token', 'token-celular-maria')->count());
        $this->assertTrue(
            DispositivoFcm::where('token', 'token-celular-maria')->first()->ultimo_uso_at->isToday()
        );
    }

    /**
     * Mismo celular, otra cuenta. Si el token se quedara ligado a María, a
     * José le llegarían en ese celular los avisos de los pedidos de María.
     */
    public function test_si_otra_cuenta_entra_en_el_mismo_celular_el_token_pasa_a_esa_cuenta(): void
    {
        $this->registrar($this->iniciarSesion(self::MARIA), 'token-celular-compartido');
        $this->registrar($this->iniciarSesion(self::JOSE), 'token-celular-compartido')->assertOk();

        $this->assertSame(1, DispositivoFcm::where('token', 'token-celular-compartido')->count());
        $this->assertDatabaseHas('dispositivos_fcm', [
            'token'      => 'token-celular-compartido',
            'usuario_id' => $this->idDe(self::JOSE),
        ]);
    }

    public function test_un_usuario_puede_tener_varios_celulares(): void
    {
        $sesion = $this->iniciarSesion(self::MARIA);

        $this->registrar($sesion, 'token-celular-1')->assertStatus(201);
        $this->registrar($sesion, 'token-celular-2')->assertStatus(201);

        $this->assertSame(2, DispositivoFcm::where('usuario_id', $this->idDe(self::MARIA))->count());
    }

    public function test_sin_sesion_no_se_puede_registrar(): void
    {
        $this->postJson('/api/dispositivos-fcm', ['token' => 'x', 'plataforma' => 'android'])
            ->assertStatus(401);
    }

    public function test_valida_el_token_y_la_plataforma(): void
    {
        $sesion = $this->iniciarSesion(self::MARIA);

        $this->withToken($sesion)->postJson('/api/dispositivos-fcm', ['plataforma' => 'blackberry'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token', 'plataforma']);
    }

    // ------------------------------------------------------------------
    // Criterio 2: el token se puede invalidar al cerrar sesión
    // ------------------------------------------------------------------

    public function test_cerrar_sesion_con_el_token_del_celular_lo_quita_y_cierra_la_sesion(): void
    {
        $sesion = $this->iniciarSesion(self::MARIA);
        $this->registrar($sesion, 'token-celular-maria');

        $this->withToken($sesion)->postJson('/api/auth/logout', ['fcm_token' => 'token-celular-maria'])
            ->assertOk();
        $this->olvidarSesionEnMemoria();

        $this->assertDatabaseMissing('dispositivos_fcm', ['token' => 'token-celular-maria']);

        // Y la sesión sí se cerró.
        $this->withToken($sesion)->getJson('/api/auth/me')->assertStatus(401);
    }

    /** Regresión: el logout de siempre, sin mandar nada, sigue funcionando. */
    public function test_cerrar_sesion_sin_mandar_el_token_del_celular_sigue_funcionando(): void
    {
        $sesion = $this->iniciarSesion(self::MARIA);
        $this->registrar($sesion, 'token-celular-maria');

        $this->withToken($sesion)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseHas('dispositivos_fcm', ['token' => 'token-celular-maria']);
    }

    public function test_no_se_puede_quitar_el_celular_de_otra_persona(): void
    {
        $this->registrar($this->iniciarSesion(self::JOSE), 'token-celular-jose');

        $sesionMaria = $this->iniciarSesion(self::MARIA);
        $this->withToken($sesionMaria)->postJson('/api/auth/logout', ['fcm_token' => 'token-celular-jose'])
            ->assertOk();

        $this->assertDatabaseHas('dispositivos_fcm', [
            'token'      => 'token-celular-jose',
            'usuario_id' => $this->idDe(self::JOSE),
        ]);
    }
}
