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

    /**
     * TG-144: aunque la app no mande fcm_token, al cerrar la sesión la base
     * borra sola el celular que se registró con ella. Antes de TG-144 el
     * celular se quedaba y seguía recibiendo push de una cuenta sin sesión.
     */
    public function test_cerrar_sesion_sin_mandar_el_token_del_celular_tambien_lo_quita(): void
    {
        $sesion = $this->iniciarSesion(self::MARIA);
        $this->registrar($sesion, 'token-celular-maria');

        $this->withToken($sesion)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseMissing('dispositivos_fcm', ['token' => 'token-celular-maria']);
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

    // ------------------------------------------------------------------
    // TG-144: cada celular ligado a su sesión
    // ------------------------------------------------------------------

    /** El token de Sanctum en texto plano empieza con su id: "15|abc...". */
    private function idDeSesion(string $sesion): int
    {
        return (int) explode('|', $sesion, 2)[0];
    }

    public function test_el_celular_queda_ligado_a_la_sesion_con_la_que_se_registro(): void
    {
        $sesion = $this->iniciarSesion(self::MARIA);
        $this->registrar($sesion, 'token-celular-maria');

        $this->assertDatabaseHas('dispositivos_fcm', [
            'token'                    => 'token-celular-maria',
            'personal_access_token_id' => $this->idDeSesion($sesion),
        ]);
    }

    /**
     * Revocar todas las sesiones de la cuenta (como hace restablecer la
     * contraseña, TG-142) quita todos sus celulares, sin código extra.
     */
    public function test_revocar_todas_las_sesiones_de_la_cuenta_quita_todos_sus_celulares(): void
    {
        $this->registrar($this->iniciarSesion(self::MARIA), 'celular-1');
        $this->registrar($this->iniciarSesion(self::MARIA), 'celular-2');
        $this->registrar($this->iniciarSesion(self::JOSE), 'celular-de-jose');

        Usuario::where('email', self::MARIA)->firstOrFail()->tokens()->delete();

        $this->assertSame(0, DispositivoFcm::where('usuario_id', $this->idDe(self::MARIA))->count());
        $this->assertDatabaseHas('dispositivos_fcm', ['token' => 'celular-de-jose']);
    }

    /**
     * Lo que va a usar el cambio de contraseña del perfil (TG-143): se revocan
     * las OTRAS sesiones y el celular desde donde se hizo sigue recibiendo push.
     */
    public function test_revocar_las_otras_sesiones_deja_el_celular_de_la_sesion_actual(): void
    {
        $sesionActual = $this->iniciarSesion(self::MARIA);
        $this->registrar($sesionActual, 'celular-actual');
        $this->registrar($this->iniciarSesion(self::MARIA), 'celular-viejo');

        Usuario::where('email', self::MARIA)->firstOrFail()
            ->tokens()
            ->where('id', '!=', $this->idDeSesion($sesionActual))
            ->delete();

        $this->assertDatabaseHas('dispositivos_fcm', ['token' => 'celular-actual']);
        $this->assertDatabaseMissing('dispositivos_fcm', ['token' => 'celular-viejo']);
    }

    /**
     * Si el mismo celular inicia sesión otra vez y se vuelve a registrar, queda
     * ligado a la sesión nueva: cerrar la vieja ya no lo borra.
     */
    public function test_al_volver_a_registrarse_el_celular_pasa_a_la_sesion_nueva(): void
    {
        $sesionVieja = $this->iniciarSesion(self::MARIA);
        $this->registrar($sesionVieja, 'mismo-celular');

        $sesionNueva = $this->iniciarSesion(self::MARIA);
        $this->registrar($sesionNueva, 'mismo-celular')->assertOk();

        $this->withToken($sesionVieja)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseHas('dispositivos_fcm', [
            'token'                    => 'mismo-celular',
            'personal_access_token_id' => $this->idDeSesion($sesionNueva),
        ]);
    }

    /** auth/me revoca la sesión de una cuenta desactivada: su celular se va con ella. */
    public function test_una_cuenta_desactivada_pierde_su_celular_al_consultar_me(): void
    {
        $sesion = $this->iniciarSesion(self::MARIA);
        $this->registrar($sesion, 'token-celular-maria');

        Usuario::where('email', self::MARIA)->update(['estado' => 'bloqueado']);

        $this->withToken($sesion)->getJson('/api/auth/me')->assertStatus(401);

        $this->assertDatabaseMissing('dispositivos_fcm', ['token' => 'token-celular-maria']);
    }
}
