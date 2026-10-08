<?php

namespace Tests\Feature\MercadoPago;

use App\Models\Auditoria;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Services\MercadoPago\MercadoPagoException;
use App\Services\MercadoPago\VincularMercadoPagoService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-225 (G6) — La distribuidora conecta SU cuenta de Mercado Pago con el
 * flujo oficial (OAuth + PKCE + state) y el token se guarda cifrado.
 *
 * Nunca se llama a Mercado Pago de verdad: Http::preventStrayRequests() hace
 * fallar cualquier petición que no esté en Http::fake().
 */
class VincularMercadoPagoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    private const CLIENT_ID = '1234567890123456';

    private const CLIENT_SECRET = 'secreto-de-prueba-no-real';

    private const REDIRECT_URI = 'https://footwearpoint.test/mercado-pago/callback';

    private const URL_TOKEN = 'https://api.mercadopago.com/oauth/token';

    private const TOKEN = 'TEST-1111111111111111-100726-abcdefabcdefabcdefabcdefabcdef12-987654321';

    private const PUBLIC_KEY = 'TEST-aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private const CUENTA = '987654321';

    private const STATE = 'state-de-prueba-0123456789abcdefghijklmn';

    private const VERIFIER = 'verifier-de-prueba-0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHI';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Http::preventStrayRequests();

        config([
            'services.mercadopago.client_id'     => self::CLIENT_ID,
            'services.mercadopago.client_secret' => self::CLIENT_SECRET,
            'services.mercadopago.redirect_uri'  => self::REDIRECT_URI,
            'services.mercadopago.modo'          => 'sandbox',
            'services.mercadopago.pkce'          => true,
        ]);
    }

    // ---------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------

    private function admin(): Usuario
    {
        return Usuario::where('email', self::ADMIN)->firstOrFail();
    }

    private function distribuidora(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function configuracion(): ConfiguracionDistribuidora
    {
        return Tenant::forzar($this->distribuidora()->id, fn () => ConfiguracionDistribuidora::query()->firstOrFail());
    }

    private function filaConfiguracion(): object
    {
        return DB::table('configuraciones_distribuidora')
            ->where('distribuidora_id', $this->distribuidora()->id)
            ->first();
    }

    /** La solicitud que conectar() deja en la sesión. */
    private function solicitud(array $cambios = []): array
    {
        return array_merge([
            'state'            => self::STATE,
            'verifier'         => self::VERIFIER,
            'usuario_id'       => $this->admin()->id,
            'distribuidora_id' => $this->distribuidora()->id,
            'expira'           => now()->addMinutes(10)->getTimestamp(),
        ], $cambios);
    }

    private function respuestaToken(array $cambios = []): array
    {
        return array_merge([
            'access_token'  => self::TOKEN,
            'token_type'    => 'bearer',
            'expires_in'    => 15552000,
            'scope'         => 'offline_access payments read write',
            'user_id'       => (int) self::CUENTA,
            'refresh_token' => 'TG-refresh-de-prueba',
            'public_key'    => self::PUBLIC_KEY,
            'live_mode'     => false,
        ], $cambios);
    }

    private function fingirToken(array $cambios = [], int $estado = 200): void
    {
        Http::fake([self::URL_TOKEN => Http::response($this->respuestaToken($cambios), $estado)]);
    }

    private function volverDeMercadoPago(array $query = ['code' => 'TG-codigo-de-prueba', 'state' => self::STATE], ?array $solicitud = null)
    {
        return $this->actingAs($this->admin())
            ->withSession([VincularMercadoPagoService::CLAVE_SESION => $solicitud ?? $this->solicitud()])
            ->get(route('mercado-pago.callback', $query));
    }

    private function urlPestana(): string
    {
        return route('distribuidora.configuracion', ['pestana' => 'mercado-pago']);
    }

    private function conectarDirecto(string $cuenta = self::CUENTA, ?\DateTimeInterface $cuando = null): void
    {
        $this->configuracion()->update([
            'mp_access_token'         => self::TOKEN,
            'mp_public_key'           => self::PUBLIC_KEY,
            'mercado_pago_account_id' => $cuenta,
            'mp_conectado_at'         => $cuando ?? now(),
        ]);
    }

    private function assertSinConexion(): void
    {
        $fila = $this->filaConfiguracion();

        $this->assertNull($fila->mp_access_token);
        $this->assertNull($fila->mp_public_key);
        $this->assertNull($fila->mercado_pago_account_id);
        $this->assertNull($fila->mp_conectado_at);
    }

    private function assertError(\Illuminate\Testing\TestResponse $respuesta, string $mensaje): void
    {
        $respuesta->assertRedirect($this->urlPestana())
            ->assertSessionHas('mercado_pago_error', $mensaje)
            ->assertSessionMissing('mercado_pago_exito');
    }

    // ---------------------------------------------------------------
    // Inicio del flujo
    // ---------------------------------------------------------------

    public function test_conectar_redirige_a_la_autorizacion_oficial_con_state_y_pkce(): void
    {
        $respuesta = $this->actingAs($this->admin())->get(route('mercado-pago.conectar'));

        $respuesta->assertRedirect();
        $solicitud = session(VincularMercadoPagoService::CLAVE_SESION);
        $this->assertIsArray($solicitud);
        $this->assertSame(40, strlen($solicitud['state']));
        $this->assertSame(64, strlen($solicitud['verifier']));
        $this->assertSame($this->admin()->id, $solicitud['usuario_id']);
        $this->assertSame($this->distribuidora()->id, $solicitud['distribuidora_id']);

        $url = parse_url($respuesta->headers->get('Location'));
        parse_str($url['query'], $parametros);

        $this->assertSame('https', $url['scheme']);
        $this->assertSame('auth.mercadopago.com', $url['host']);
        $this->assertSame('/authorization', $url['path']);
        $this->assertSame(self::CLIENT_ID, $parametros['client_id']);
        $this->assertSame('code', $parametros['response_type']);
        $this->assertSame('mp', $parametros['platform_id']);
        $this->assertSame(self::REDIRECT_URI, $parametros['redirect_uri']);
        $this->assertSame($solicitud['state'], $parametros['state']);
        $this->assertSame('S256', $parametros['code_challenge_method']);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $solicitud['verifier'], true)), '+/', '-_'), '='),
            $parametros['code_challenge']
        );
        // El secreto nunca viaja en la URL.
        $this->assertStringNotContainsString(self::CLIENT_SECRET, $respuesta->headers->get('Location'));

        Http::assertNothingSent();
    }

    public function test_sin_pkce_no_manda_code_challenge_ni_code_verifier(): void
    {
        config(['services.mercadopago.pkce' => false]);

        $respuesta = $this->actingAs($this->admin())->get(route('mercado-pago.conectar'));

        parse_str(parse_url($respuesta->headers->get('Location'), PHP_URL_QUERY), $parametros);
        $this->assertArrayNotHasKey('code_challenge', $parametros);
        $this->assertNull(session(VincularMercadoPagoService::CLAVE_SESION)['verifier']);

        $this->fingirToken();
        $this->volverDeMercadoPago(solicitud: $this->solicitud(['verifier' => null]))
            ->assertSessionHas('mercado_pago_exito');

        Http::assertSent(fn (PeticionHttp $peticion) => ! isset($peticion->data()['code_verifier']));
    }

    public function test_si_falta_configurar_la_aplicacion_avisa_sin_salir_a_mercado_pago(): void
    {
        config(['services.mercadopago.client_secret' => null]);

        $this->assertError(
            $this->actingAs($this->admin())->get(route('mercado-pago.conectar')),
            MercadoPagoException::NO_CONFIGURADO
        );
        $this->assertNull(session(VincularMercadoPagoService::CLAVE_SESION));

        $this->actingAs($this->admin());
        Livewire::test('distribuidora.mercado-pago')
            ->assertSee(MercadoPagoException::NO_CONFIGURADO)
            ->assertDontSee('Conectar con Mercado Pago');
    }

    // ---------------------------------------------------------------
    // Callback correcto
    // ---------------------------------------------------------------

    public function test_el_callback_guarda_las_credenciales_cifradas_y_audita_sin_el_token(): void
    {
        $this->fingirToken();

        $respuesta = $this->volverDeMercadoPago();

        $respuesta->assertRedirect($this->urlPestana())
            ->assertSessionHas('mercado_pago_exito', 'Listo: tu cuenta de Mercado Pago quedó conectada.')
            ->assertSessionMissing(VincularMercadoPagoService::CLAVE_SESION);

        Http::assertSentCount(1);
        Http::assertSent(function (PeticionHttp $peticion) {
            $datos = $peticion->data();

            return $peticion->url() === self::URL_TOKEN
                && $peticion->method() === 'POST'
                && $datos['client_id'] === self::CLIENT_ID
                && $datos['client_secret'] === self::CLIENT_SECRET
                && $datos['grant_type'] === 'authorization_code'
                && $datos['code'] === 'TG-codigo-de-prueba'
                && $datos['redirect_uri'] === self::REDIRECT_URI
                && $datos['code_verifier'] === self::VERIFIER
                && $datos['test_token'] === true;
        });

        // En la base el token está cifrado; el modelo lo descifra.
        $fila = $this->filaConfiguracion();
        $this->assertNotNull($fila->mp_access_token);
        $this->assertStringNotContainsString(self::TOKEN, $fila->mp_access_token);
        $this->assertSame(self::PUBLIC_KEY, $fila->mp_public_key);
        $this->assertSame(self::CUENTA, $fila->mercado_pago_account_id);
        $this->assertNotNull($fila->mp_conectado_at);
        $this->assertSame(self::TOKEN, $this->configuracion()->mercadoPagoAccessToken());
        $this->assertTrue($this->configuracion()->tieneMercadoPago());

        // El refresh token no se guarda en ningún lado.
        $this->assertStringNotContainsString('TG-refresh-de-prueba', json_encode($fila));

        $auditoria = Auditoria::where('accion', 'mercado_pago.conectado')->sole();
        $this->assertSame('configuracion_distribuidora', $auditoria->entidad_tipo);
        $this->assertSame($this->distribuidora()->id, $auditoria->distribuidora_id);
        $this->assertSame(['cuenta_mercado_pago' => self::CUENTA, 'modo' => 'sandbox'], $auditoria->datos_nuevos);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($auditoria->getAttributes()));
    }

    public function test_el_flujo_completo_desde_conectar_hasta_el_callback(): void
    {
        $this->actingAs($this->admin())->get(route('mercado-pago.conectar'));
        $solicitud = session(VincularMercadoPagoService::CLAVE_SESION);

        $this->fingirToken();
        $this->volverDeMercadoPago(['code' => 'TG-otro-codigo', 'state' => $solicitud['state']], $solicitud)
            ->assertSessionHas('mercado_pago_exito');

        Http::assertSent(fn (PeticionHttp $peticion) => $peticion->data()['code_verifier'] === $solicitud['verifier']);
        $this->assertSame(self::CUENTA, $this->filaConfiguracion()->mercado_pago_account_id);
    }

    public function test_la_pantalla_muestra_la_cuenta_pero_nunca_el_token(): void
    {
        $this->conectarDirecto();

        $respuesta = $this->actingAs($this->admin())->get($this->urlPestana());

        $respuesta->assertOk()
            ->assertSee('Cuenta conectada')
            ->assertSee(self::CUENTA)
            ->assertSee('Volver a conectar')
            ->assertSee('Desconectar')
            ->assertSee('Modo de prueba (sandbox)')
            ->assertDontSee(self::TOKEN, false);

        // Ni por la API de configuración ni al serializar el modelo.
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/distribuidora/config')->assertOk()->assertDontSee(self::TOKEN, false);
        $this->assertArrayNotHasKey('mp_access_token', $this->configuracion()->toArray());
        $this->assertStringNotContainsString(self::TOKEN, $this->configuracion()->toJson());
    }

    public function test_la_pestana_se_abre_por_url_y_un_valor_raro_vuelve_a_datos_generales(): void
    {
        $this->actingAs($this->admin());

        Livewire::withQueryParams(['pestana' => 'mercado-pago'])
            ->test('distribuidora.configuracion')
            ->assertSet('pestanaActiva', 'mercado-pago')
            ->assertSee('Todavía no has conectado una cuenta de Mercado Pago.')
            ->assertSee('Conectar con Mercado Pago');

        Livewire::withQueryParams(['pestana' => 'no-existe'])
            ->test('distribuidora.configuracion')
            ->assertSet('pestanaActiva', 'perfil');
    }

    public function test_reconectar_la_misma_cuenta_actualiza_el_token(): void
    {
        $this->conectarDirecto(cuando: now()->subDays(100));
        $this->fingirToken(['access_token' => 'TEST-token-nuevo']);

        $this->volverDeMercadoPago()->assertSessionHas('mercado_pago_exito');

        $configuracion = $this->configuracion();
        $this->assertSame('TEST-token-nuevo', $configuracion->mercadoPagoAccessToken());
        $this->assertTrue($configuracion->mp_conectado_at->isToday());
        $this->assertSame(
            ['cuenta_mercado_pago' => self::CUENTA],
            Auditoria::where('accion', 'mercado_pago.conectado')->sole()->datos_previos
        );
    }

    public function test_en_produccion_pide_token_real_y_acepta_live_mode(): void
    {
        config(['services.mercadopago.modo' => 'produccion']);
        $this->fingirToken(['live_mode' => true]);

        $this->volverDeMercadoPago()->assertSessionHas('mercado_pago_exito');

        Http::assertSent(fn (PeticionHttp $peticion) => $peticion->data()['test_token'] === false);
    }

    // ---------------------------------------------------------------
    // Callback rechazado: state, cancelación y errores de Mercado Pago
    // ---------------------------------------------------------------

    public function test_sin_solicitud_en_la_sesion_no_se_conecta(): void
    {
        $respuesta = $this->actingAs($this->admin())
            ->get(route('mercado-pago.callback', ['code' => 'TG-codigo', 'state' => self::STATE]));

        $this->assertError($respuesta, MercadoPagoException::SOLICITUD_INVALIDA);
        Http::assertNothingSent();
        $this->assertSinConexion();
    }

    public function test_un_state_distinto_no_se_acepta(): void
    {
        $this->assertError(
            $this->volverDeMercadoPago(['code' => 'TG-codigo', 'state' => 'otro-state']),
            MercadoPagoException::SOLICITUD_INVALIDA
        );
        Http::assertNothingSent();
        $this->assertSinConexion();
    }

    public function test_una_solicitud_vencida_no_se_acepta(): void
    {
        $this->assertError(
            $this->volverDeMercadoPago(solicitud: $this->solicitud(['expira' => now()->subMinute()->getTimestamp()])),
            MercadoPagoException::SOLICITUD_INVALIDA
        );
        Http::assertNothingSent();
    }

    public function test_una_solicitud_de_otro_usuario_no_se_acepta(): void
    {
        $empleado = Usuario::where('email', self::EMPLEADO)->firstOrFail();

        $this->assertError(
            $this->volverDeMercadoPago(solicitud: $this->solicitud(['usuario_id' => $empleado->id])),
            MercadoPagoException::SOLICITUD_INVALIDA
        );
        Http::assertNothingSent();
    }

    public function test_el_callback_sin_codigo_no_se_acepta(): void
    {
        $this->assertError($this->volverDeMercadoPago(['state' => self::STATE]), MercadoPagoException::SOLICITUD_INVALIDA);
        Http::assertNothingSent();
    }

    public function test_el_state_es_de_un_solo_uso(): void
    {
        $this->fingirToken();

        $this->volverDeMercadoPago()->assertSessionMissing(VincularMercadoPagoService::CLAVE_SESION);

        // Si alguien repite el mismo callback ya no hay solicitud pendiente.
        $this->assertError(
            $this->actingAs($this->admin())->get(route('mercado-pago.callback', ['code' => 'TG-codigo-de-prueba', 'state' => self::STATE])),
            MercadoPagoException::SOLICITUD_INVALIDA
        );
        Http::assertSentCount(1);
    }

    public function test_si_la_distribuidora_cancela_en_mercado_pago_lo_explica(): void
    {
        $respuesta = $this->volverDeMercadoPago(['error' => 'access_denied', 'state' => self::STATE]);

        $this->assertError($respuesta, MercadoPagoException::CANCELADA);
        $respuesta->assertSessionMissing(VincularMercadoPagoService::CLAVE_SESION);
        Http::assertNothingSent();
        $this->assertSinConexion();
    }

    public function test_si_mercado_pago_rechaza_el_codigo_se_reporta_sin_secretos(): void
    {
        Exceptions::fake();
        Http::fake([self::URL_TOKEN => Http::response([
            'error'   => 'invalid_grant',
            'message' => 'invalid_grant',
            'status'  => 400,
        ], 400)]);

        $this->assertError($this->volverDeMercadoPago(), MercadoPagoException::RECHAZADA);
        $this->assertSinConexion();

        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'HTTP 400')
            && str_contains($e->getMessage(), 'invalid_grant')
            && ! str_contains($e->getMessage(), self::CLIENT_SECRET)
            && ! str_contains($e->getMessage(), 'TG-codigo-de-prueba'));
    }

    public function test_si_mercado_pago_no_responde_pide_intentar_mas_tarde(): void
    {
        Exceptions::fake();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->assertError($this->volverDeMercadoPago(), MercadoPagoException::SIN_RESPUESTA);
        $this->assertSinConexion();
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_si_mercado_pago_falla_por_dentro_pide_intentar_mas_tarde(): void
    {
        Exceptions::fake();
        Http::fake([self::URL_TOKEN => Http::response(['message' => 'internal_error'], 503)]);

        $this->assertError($this->volverDeMercadoPago(), MercadoPagoException::SIN_RESPUESTA);
        $this->assertSinConexion();
    }

    public function test_una_respuesta_sin_access_token_no_conecta(): void
    {
        Exceptions::fake();
        Http::fake([self::URL_TOKEN => Http::response(['user_id' => (int) self::CUENTA], 200)]);

        $this->assertError($this->volverDeMercadoPago(), MercadoPagoException::RECHAZADA);
        $this->assertSinConexion();
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_en_sandbox_se_rechaza_una_cuenta_real(): void
    {
        $this->fingirToken(['live_mode' => true]);

        $this->assertError($this->volverDeMercadoPago(), MercadoPagoException::CUENTA_REAL);
        $this->assertSinConexion();
        $this->assertSame(0, Auditoria::where('accion', 'mercado_pago.conectado')->count());
    }

    public function test_la_misma_cuenta_no_se_puede_conectar_a_dos_distribuidoras(): void
    {
        $otra = Distribuidora::create([
            'nombre_comercial' => 'Calzado Norte',
            'slug'             => 'calzado-norte',
            'estado'           => 'activa',
        ]);
        DB::table('configuraciones_distribuidora')->insert([
            'distribuidora_id'        => $otra->id,
            'mercado_pago_account_id' => self::CUENTA,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
        $this->fingirToken();

        $this->assertError($this->volverDeMercadoPago(), MercadoPagoException::CUENTA_EN_USO);
        $this->assertSinConexion();
    }

    // ---------------------------------------------------------------
    // Acceso a las rutas
    // ---------------------------------------------------------------

    public function test_solo_el_admin_de_la_distribuidora_puede_conectar(): void
    {
        $this->get(route('mercado-pago.conectar'))->assertRedirect(route('login'));

        $empleado = Usuario::where('email', self::EMPLEADO)->firstOrFail();
        $this->actingAs($empleado)->get(route('mercado-pago.conectar'))->assertForbidden();
        $this->actingAs($empleado)->get(route('mercado-pago.callback', ['code' => 'x', 'state' => 'y']))->assertForbidden();

        $adminGeneral = Usuario::create([
            'nombre'   => 'Admin General',
            'email'    => 'admin.general@footwearpoint.test',
            'password' => Hash::make('password'),
            'estado'   => 'activo',
        ]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $adminGeneral->assignRole('admin_general');
        $registrar->forgetCachedPermissions();

        $this->actingAs($adminGeneral)->get(route('mercado-pago.conectar'))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_una_distribuidora_suspendida_ve_el_aviso_en_vez_de_un_403(): void
    {
        $this->distribuidora()->update(['estado' => 'suspendida']);

        $this->actingAs($this->admin())
            ->get(route('mercado-pago.conectar'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('aviso_acceso');

        $this->assertGuest();
    }

    // ---------------------------------------------------------------
    // Desconectar, vencimiento y token ilegible
    // ---------------------------------------------------------------

    public function test_desconectar_borra_las_credenciales_y_lo_audita(): void
    {
        $this->conectarDirecto();
        $this->actingAs($this->admin());

        Livewire::test('distribuidora.mercado-pago')
            ->assertSet('conectado', true)
            ->call('desconectar')
            ->assertSet('conectado', false)
            ->assertSet('cuentaId', null)
            ->assertSee('Desconectamos tu cuenta de Mercado Pago de FootwearPoint.')
            ->assertSee('Conectar con Mercado Pago');

        $this->assertSinConexion();
        $auditoria = Auditoria::where('accion', 'mercado_pago.desconectado')->sole();
        $this->assertSame(['cuenta_mercado_pago' => self::CUENTA], $auditoria->datos_previos);
        Http::assertNothingSent();
    }

    public function test_avisa_cuando_la_conexion_esta_por_vencer(): void
    {
        $this->conectarDirecto(cuando: now()->subDays(170));
        $this->actingAs($this->admin());

        Livewire::test('distribuidora.mercado-pago')
            ->assertSet('porVencer', true)
            ->assertSet('vencido', false)
            ->assertSet('venceEl', now()->subDays(170)->addDays(180)->format('d/m/Y'))
            ->assertSee('Tu conexión con Mercado Pago vence pronto.');
    }

    public function test_avisa_cuando_la_conexion_ya_vencio(): void
    {
        $this->conectarDirecto(cuando: now()->subDays(181));
        $this->actingAs($this->admin());

        Livewire::test('distribuidora.mercado-pago')
            ->assertSet('vencido', true)
            ->assertSee('La conexión con Mercado Pago venció.')
            ->assertSee('Volver a conectar');
    }

    public function test_una_conexion_reciente_no_muestra_avisos(): void
    {
        $this->conectarDirecto(cuando: now()->subDays(10));
        $this->actingAs($this->admin());

        Livewire::test('distribuidora.mercado-pago')
            ->assertSet('porVencer', false)
            ->assertSet('vencido', false)
            ->assertDontSee('vence pronto')
            ->assertDontSee('venció');
    }

    public function test_un_token_que_no_se_puede_descifrar_pide_volver_a_conectar(): void
    {
        Exceptions::fake();
        DB::table('configuraciones_distribuidora')
            ->where('distribuidora_id', $this->distribuidora()->id)
            ->update([
                'mp_access_token'         => 'no-es-un-valor-cifrado',
                'mercado_pago_account_id' => self::CUENTA,
                'mp_conectado_at'         => now(),
            ]);
        $this->actingAs($this->admin());

        Livewire::test('distribuidora.mercado-pago')
            ->assertSet('conectado', false)
            ->assertSet('ilegible', true)
            ->assertSee('Hay que renovar la conexión con Mercado Pago.')
            ->assertSee('Volver a conectar');

        $this->assertFalse($this->configuracion()->tieneMercadoPago());
        Exceptions::assertReported(DecryptException::class);
    }
}
