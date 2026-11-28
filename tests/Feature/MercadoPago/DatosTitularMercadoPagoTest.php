<?php

namespace Tests\Feature\MercadoPago;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El titular, correo y usuario de la cuenta de Mercado Pago se consultan en
 * GET /users/me y se muestran en la tarjeta «Cuenta conectada». El token
 * nunca viaja a la pantalla ni al log de la acción.
 */
class DatosTitularMercadoPagoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    private const TOKEN = 'TEST-1111111111111111-100726-abcdefabcdefabcdefabcdefabcdef12-987654321';

    private const PUBLIC_KEY = 'TEST-aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private const CUENTA = '987654321';

    private const URL_USUARIOS = 'https://api.mercadopago.com/users/me';

    private const TITULAR = 'María Ramírez';

    private const CORREO = 'maria.ramirez@example.com';

    private const USUARIO = 'MARIARAMIREZ';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Http::preventStrayRequests();

        config([
            'services.mercadopago.client_id'     => '1234567890123456',
            'services.mercadopago.client_secret' => 'secreto-de-prueba-no-real',
            'services.mercadopago.redirect_uri'  => 'https://footwearpoint.test/mercado-pago/callback',
            'services.mercadopago.modo'          => 'sandbox',
            'services.mercadopago.pkce'          => true,
        ]);
    }

    public function test_al_abrir_la_pestana_consulta_users_me_y_muestra_el_titular(): void
    {
        $this->conectarDirecto();
        $this->fingirIdentidad();

        $this->actingAs($this->admin());

        Livewire::test('distribuidora.mercado-pago')
            ->assertSet('titular', self::TITULAR)
            ->assertSet('correo', self::CORREO)
            ->assertSet('usuario', self::USUARIO)
            ->assertSet('cuentaId', self::CUENTA)
            ->assertSee(self::TITULAR)
            ->assertSee(self::CORREO)
            ->assertSee(self::USUARIO)
            ->assertSee(self::CUENTA)
            ->assertDontSee(self::TOKEN, false);

        Http::assertSent(fn (PeticionHttp $peticion) => $peticion->url() === self::URL_USUARIOS
            && $peticion->method() === 'GET'
            && $peticion->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        Http::assertSentCount(1);

        $configuracion = $this->configuracion();
        $this->assertSame(self::TITULAR, $configuracion->mp_nombre);
        $this->assertSame(self::CORREO, $configuracion->mp_email);
        $this->assertSame(self::USUARIO, $configuracion->mp_nickname);
        $this->assertNotNull($configuracion->mp_identidad_consultada_at);
        $this->assertArrayNotHasKey('mp_access_token', $configuracion->toArray());
    }

    public function test_si_la_api_falla_muestra_no_disponible_sin_romper_la_pantalla(): void
    {
        $this->conectarDirecto();
        Http::fake([self::URL_USUARIOS => Http::response(['message' => 'unauthorized'], 401)]);

        $this->actingAs($this->admin());

        Livewire::test('distribuidora.mercado-pago')
            ->assertSet('conectado', true)
            ->assertSet('cuentaId', self::CUENTA)
            ->assertSet('titular', null)
            ->assertSet('correo', null)
            ->assertSet('usuario', null)
            ->assertSee('Cuenta conectada')
            ->assertSee(self::CUENTA)
            ->assertSee('No disponible')
            ->assertDontSee(self::TOKEN, false);

        $configuracion = $this->configuracion();
        $this->assertNull($configuracion->mp_nombre);
        $this->assertNull($configuracion->mp_identidad_consultada_at);
        $this->assertSame(self::TOKEN, $configuracion->mercadoPagoAccessToken());
    }

    public function test_si_los_datos_ya_estan_guardados_no_vuelve_a_consultar_la_api(): void
    {
        $this->conectarDirecto();
        $this->configuracion()->update([
            'mp_nombre'                  => self::TITULAR,
            'mp_email'                   => self::CORREO,
            'mp_nickname'                => self::USUARIO,
            'mp_identidad_consultada_at' => now()->subHour(),
        ]);

        Http::fake([self::URL_USUARIOS => Http::response([
            'id'         => (int) self::CUENTA,
            'first_name' => 'Otro',
            'last_name'  => 'Nombre',
            'email'      => 'otro@example.com',
            'nickname'   => 'OTRONICK',
        ], 200)]);

        $this->actingAs($this->admin());

        Livewire::test('distribuidora.mercado-pago')
            ->assertSet('titular', self::TITULAR)
            ->assertSet('correo', self::CORREO)
            ->assertSet('usuario', self::USUARIO)
            ->assertSee(self::TITULAR)
            ->assertDontSee('Otro Nombre')
            ->assertDontSee('otro@example.com')
            ->assertDontSee(self::TOKEN, false);

        Http::assertNothingSent();
    }

    public function test_el_comando_sincroniza_las_cuentas_que_aun_no_tienen_titular(): void
    {
        $this->conectarDirecto();
        $this->fingirIdentidad();

        $this->artisan('mercado-pago:sincronizar-identidad')
            ->expectsOutputToContain('Actualizadas: 1.')
            ->assertSuccessful();

        $configuracion = $this->configuracion();
        $this->assertSame(self::TITULAR, $configuracion->mp_nombre);
        $this->assertSame(self::CORREO, $configuracion->mp_email);
        $this->assertSame(self::USUARIO, $configuracion->mp_nickname);
        $this->assertNotNull($configuracion->mp_identidad_consultada_at);
        Http::assertSentCount(1);

        Http::fake([self::URL_USUARIOS => Http::response([
            'id'         => (int) self::CUENTA,
            'first_name' => 'Otro',
            'last_name'  => 'Nombre',
            'email'      => 'otro@example.com',
            'nickname'   => 'OTRONICK',
        ], 200)]);

        $this->artisan('mercado-pago:sincronizar-identidad')
            ->expectsOutputToContain('Cuentas revisadas: 0.')
            ->assertSuccessful();

        $this->assertSame(self::TITULAR, $this->configuracion()->fresh()->mp_nombre);
        Http::assertNothingSent();
    }

    public function test_al_desconectar_se_borran_los_datos_del_titular(): void
    {
        $this->conectarDirecto();
        $this->configuracion()->update([
            'mp_nombre'                  => self::TITULAR,
            'mp_email'                   => self::CORREO,
            'mp_nickname'                => self::USUARIO,
            'mp_identidad_consultada_at' => now(),
        ]);

        $this->actingAs($this->admin());

        Livewire::test('distribuidora.mercado-pago')
            ->call('desconectar')
            ->assertSet('conectado', false)
            ->assertDontSee(self::TITULAR)
            ->assertDontSee(self::CORREO);

        $fila = $this->configuracion();
        $this->assertNull($fila->mp_nombre);
        $this->assertNull($fila->mp_email);
        $this->assertNull($fila->mp_nickname);
        $this->assertNull($fila->mp_identidad_consultada_at);
        $this->assertNull($fila->mercado_pago_account_id);
    }

    private function admin(): Usuario
    {
        return Usuario::where('email', self::ADMIN)->firstOrFail();
    }

    private function configuracion(): ConfiguracionDistribuidora
    {
        $distribuidora = Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();

        return Tenant::forzar($distribuidora->id, fn () => ConfiguracionDistribuidora::query()->firstOrFail());
    }

    private function conectarDirecto(): void
    {
        $this->configuracion()->update([
            'mp_access_token'         => self::TOKEN,
            'mp_public_key'           => self::PUBLIC_KEY,
            'mercado_pago_account_id' => self::CUENTA,
            'mp_conectado_at'         => now(),
        ]);
    }

    private function fingirIdentidad(): void
    {
        Http::fake([self::URL_USUARIOS => Http::response([
            'id'         => (int) self::CUENTA,
            'first_name' => 'María',
            'last_name'  => 'Ramírez',
            'email'      => self::CORREO,
            'nickname'   => self::USUARIO,
        ], 200)]);
    }
}
