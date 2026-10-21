<?php

namespace Tests\Feature\MercadoPago;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\Notificacion;
use App\Services\MercadoPago\RenovarTokensMercadoPagoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Renovación automática del token OAuth de Mercado Pago de las distribuidoras
 * (`php artisan mercado-pago:renovar-tokens`). Nunca se llama a Mercado Pago
 * de verdad: todo pasa por Http::fake().
 */
class RenovarTokensMercadoPagoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const URL_TOKEN = 'https://api.mercadopago.com/oauth/token';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Http::preventStrayRequests();
        Mail::fake();

        config([
            'services.mercadopago.client_id'     => '1234567890123456',
            'services.mercadopago.client_secret' => 'secreto-de-prueba-no-real',
            'services.mercadopago.redirect_uri'  => 'https://footwearpoint.test/mercado-pago/callback',
            'services.mercadopago.modo'          => 'produccion',
        ]);
    }

    private function distribuidora(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    private function configuracion(): ConfiguracionDistribuidora
    {
        return ConfiguracionDistribuidora::withoutGlobalScopes()
            ->where('distribuidora_id', $this->distribuidora()->id)->firstOrFail();
    }

    private function conectar(array $cambios): void
    {
        $this->configuracion()->forceFill(array_merge([
            'mercado_pago_account_id' => '987654321',
            'mp_access_token'         => 'APP_USR-viejo',
            'mp_conectado_at'         => now()->subDays(170),
        ], $cambios))->save();
    }

    private function avisos(): int
    {
        return Notificacion::withoutGlobalScopes()->where('tipo', RenovarTokensMercadoPagoAction::TIPO_NOTIFICACION)->count();
    }

    public function test_renueva_el_token_que_vence_pronto_y_lo_guarda_cifrado(): void
    {
        $this->conectar(['mp_refresh_token' => 'TG-refresh-viejo', 'mp_token_expira_en' => now()->addDays(10)]);

        Http::fake([self::URL_TOKEN => Http::response([
            'access_token'  => 'APP_USR-nuevo',
            'refresh_token' => 'TG-refresh-nuevo',
            'expires_in'    => 15552000,
            'user_id'       => 987654321,
        ])]);

        $this->artisan('mercado-pago:renovar-tokens')
            ->expectsOutputToContain('Renovadas: 1')
            ->assertSuccessful();

        Http::assertSent(fn (PeticionHttp $p) => $p->url() === self::URL_TOKEN
            && $p['grant_type'] === 'refresh_token'
            && $p['refresh_token'] === 'TG-refresh-viejo'
            && $p['client_id'] === '1234567890123456'
            && $p['client_secret'] === 'secreto-de-prueba-no-real');

        $config = $this->configuracion();
        $this->assertSame('APP_USR-nuevo', $config->mercadoPagoAccessToken());
        $this->assertSame('TG-refresh-nuevo', $config->mercadoPagoRefreshToken());
        $this->assertTrue($config->mp_token_expira_en->gt(now()->addDays(170)));

        $fila = DB::table('configuraciones_distribuidora')->where('id', $config->id)->first();
        $this->assertStringNotContainsString('TG-refresh-nuevo', (string) $fila->mp_refresh_token);
        $this->assertSame(0, $this->avisos());
    }

    public function test_no_toca_los_tokens_que_vencen_en_mas_de_30_dias(): void
    {
        $this->conectar(['mp_refresh_token' => 'TG-refresh', 'mp_token_expira_en' => now()->addDays(90)]);
        Http::fake();

        $this->artisan('mercado-pago:renovar-tokens')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('APP_USR-viejo', $this->configuracion()->mercadoPagoAccessToken());
    }

    public function test_sin_refresh_token_avisa_al_administrador_una_sola_vez_por_semana(): void
    {
        // Conexión vieja: sin refresh token, vence aprox. conectado + 180 días.
        $this->conectar(['mp_conectado_at' => now()->subDays(160)]);
        Http::fake();

        $this->artisan('mercado-pago:renovar-tokens')
            ->expectsOutputToContain('Avisos enviados: 1')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertGreaterThan(0, $this->avisos());
        $primeros = $this->avisos();
        Mail::assertSentCount($primeros);

        $this->artisan('mercado-pago:renovar-tokens')->assertSuccessful();
        $this->assertSame($primeros, $this->avisos());

        $this->travel(7)->days();
        $this->artisan('mercado-pago:renovar-tokens')->assertSuccessful();
        $this->assertSame($primeros * 2, $this->avisos());
    }

    public function test_si_mercado_pago_rechaza_la_renovacion_avisa_y_conserva_el_token(): void
    {
        $this->conectar(['mp_refresh_token' => 'TG-refresh-invalido', 'mp_token_expira_en' => now()->addDays(5)]);
        Http::fake([self::URL_TOKEN => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->artisan('mercado-pago:renovar-tokens', ['--sin-correo' => true])
            ->expectsOutputToContain('Fallidas: 1')
            ->assertSuccessful();

        $this->assertGreaterThan(0, $this->avisos());
        Mail::assertNothingSent();
        $this->assertSame('APP_USR-viejo', $this->configuracion()->mercadoPagoAccessToken());
    }

    public function test_dry_run_no_cambia_nada(): void
    {
        $this->conectar(['mp_conectado_at' => now()->subDays(175)]);
        Http::fake();

        $this->artisan('mercado-pago:renovar-tokens', ['--dry-run' => true])
            ->expectsOutputToContain('Conexiones por vencer: 1')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, $this->avisos());
    }
}
