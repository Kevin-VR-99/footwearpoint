<?php

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sesion persistente en el panel web: la sesion solo termina cuando el
 * usuario pulsa "Cerrar sesion" (admin general, admin de distribuidora y
 * empleado).
 */
class SesionPersistenteTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    public static function usuariosDelPanel(): array
    {
        return [
            'admin de distribuidora'  => [DemoDistribuidoraSeeder::ADMIN],
            'empleado (staff)'        => [DemoDistribuidoraSeeder::EMPLEADO],
            'admin de otra distrib.'  => [DemoDistribuidoraSeeder::SEGUNDA_ADMIN],
        ];
    }

    private function conPassword(string $email): Usuario
    {
        $usuario = Usuario::where('email', $email)->firstOrFail();
        $usuario->forceFill(['password' => Hash::make('Sesion-Larga-2026!'), 'estado' => 'activo'])->save();

        return $usuario;
    }

    public function test_la_configuracion_no_cierra_la_sesion_sola(): void
    {
        $this->assertFalse(config('session.expire_on_close'));
    }

    /** Evalua config/session.php con SESSION_LIFETIME puesta o sin poner. */
    private function lifetimeCon(?string $valor): int
    {
        $antes = getenv('SESSION_LIFETIME');
        $antesEnv = $_ENV['SESSION_LIFETIME'] ?? null;
        $antesServer = $_SERVER['SESSION_LIFETIME'] ?? null;
        unset($_ENV['SESSION_LIFETIME'], $_SERVER['SESSION_LIFETIME']);
        putenv($valor === null ? 'SESSION_LIFETIME' : 'SESSION_LIFETIME='.$valor);

        try {
            return (require config_path('session.php'))['lifetime'];
        } finally {
            putenv($antes === false ? 'SESSION_LIFETIME' : 'SESSION_LIFETIME='.$antes);
            if ($antesEnv !== null) { $_ENV['SESSION_LIFETIME'] = $antesEnv; }
            if ($antesServer !== null) { $_SERVER['SESSION_LIFETIME'] = $antesServer; }
        }
    }

    public function test_sin_variable_la_sesion_dura_diez_anios(): void
    {
        $this->assertSame(5256000, $this->lifetimeCon(null));
    }

    public function test_la_variable_session_lifetime_se_respeta(): void
    {
        $this->assertSame(120, $this->lifetimeCon('120'));
        $this->assertSame(9000000, $this->lifetimeCon('9000000'));
    }

    #[DataProvider('usuariosDelPanel')]
    public function test_el_login_web_siempre_recuerda_al_usuario(string $email): void
    {
        $usuario = $this->conPassword($email);

        Livewire::test('auth.login')
            ->set('email', $email)
            ->set('password', 'Sesion-Larga-2026!')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertTrue(Auth::check());
        $this->assertSame($usuario->id, Auth::id());
        $this->assertTrue(Cookie::hasQueued(Auth::guard('web')->getRecallerName()), 'Falta la cookie remember.');
        $this->assertNotEmpty($usuario->fresh()->remember_token);
    }

    public function test_el_admin_general_no_tiene_sesion_persistente(): void
    {
        $usuario = $this->conPassword(AdminGeneralSeeder::EMAIL);

        Livewire::test('auth.login')
            ->set('email', AdminGeneralSeeder::EMAIL)
            ->set('password', 'Sesion-Larga-2026!')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertSame($usuario->id, Auth::id());
        $this->assertFalse(Cookie::hasQueued(Auth::guard('web')->getRecallerName()), 'El admin general no debe llevar cookie remember.');
    }

    public function test_la_cookie_de_sesion_del_admin_general_es_de_navegador(): void
    {
        $admin = Usuario::where('email', AdminGeneralSeeder::EMAIL)->firstOrFail();
        $cookie = collect($this->actingAs($admin)->get('/')->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertSame(0, $cookie->getExpiresTime());

        $this->app['auth']->forgetGuards();
        config(['session.expire_on_close' => false]);
        $distribuidora = Usuario::where('email', DemoDistribuidoraSeeder::ADMIN)->firstOrFail();
        $cookie = collect($this->actingAs($distribuidora)->get('/')->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertGreaterThan(0, $cookie->getExpiresTime());
    }

    public function test_session_admin_general_persistente_regresa_al_comportamiento_anterior(): void
    {
        config(['session.admin_general_persistente' => true]);
        $usuario = $this->conPassword(AdminGeneralSeeder::EMAIL);

        $this->assertTrue(\App\Support\SesionDelPanel::esPersistente($usuario));
    }

    public function test_la_sesion_se_cifra_por_defecto(): void
    {
        $antes = getenv('SESSION_ENCRYPT');
        $antesEnv = $_ENV['SESSION_ENCRYPT'] ?? null;
        $antesServer = $_SERVER['SESSION_ENCRYPT'] ?? null;
        unset($_ENV['SESSION_ENCRYPT'], $_SERVER['SESSION_ENCRYPT']);
        putenv('SESSION_ENCRYPT');

        try {
            $this->assertTrue((require config_path('session.php'))['encrypt']);
            putenv('SESSION_ENCRYPT=false');
            $this->assertFalse((require config_path('session.php'))['encrypt']);
        } finally {
            putenv($antes === false ? 'SESSION_ENCRYPT' : 'SESSION_ENCRYPT='.$antes);
            if ($antesEnv !== null) { $_ENV['SESSION_ENCRYPT'] = $antesEnv; }
            if ($antesServer !== null) { $_SERVER['SESSION_ENCRYPT'] = $antesServer; }
        }
    }

    public function test_el_login_ya_no_tiene_casilla_recordarme(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('wire:model="remember"', false);
    }

    #[DataProvider('usuariosDelPanel')]
    public function test_la_cookie_remember_restaura_la_sesion_perdida(string $email): void
    {
        $usuario = $this->conPassword($email);
        $usuario->forceFill(['remember_token' => 'token-recordar-123'])->save();

        $guard = Auth::guard('web');
        $valor = $usuario->id.'|token-recordar-123|'.$usuario->getAuthPassword();

        // Sin sesion en el servidor (deploy, limpieza de la tabla sessions):
        // solo con la cookie remember vuelve a entrar.
        $this->withCookie($guard->getRecallerName(), $valor)
            ->get('/');

        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($usuario->id, Auth::guard('web')->id());
        $this->assertTrue(Auth::guard('web')->viaRemember());
    }

    public function test_cerrar_sesion_no_saca_al_usuario_de_sus_otros_navegadores(): void
    {
        $usuario = $this->conPassword(DemoDistribuidoraSeeder::ADMIN);
        $usuario->forceFill(['remember_token' => 'token-otro-navegador'])->save();

        $this->actingAs($usuario)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame('token-otro-navegador', $usuario->fresh()->remember_token);
    }

    public function test_un_formulario_web_con_token_vencido_regresa_en_vez_de_page_expired(): void
    {
        Route::middleware('web')->post('/_prueba-419', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

        $this->from('/login')->post('/_prueba-419', ['email' => 'a@b.test', 'password' => 'x'])
            ->assertRedirect('/login')
            ->assertSessionHasInput('email')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_livewire_y_la_api_siguen_recibiendo_419(): void
    {
        Route::middleware('web')->post('/_prueba-419', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

        $this->post('/_prueba-419', [], ['X-Livewire' => '1'])->assertStatus(419);
        $this->postJson('/_prueba-419')->assertStatus(419);
    }

    public function test_livewire_recarga_la_pagina_con_un_419(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('status === 419', $js);
        $this->assertStringContainsString('window.location.reload()', $js);
    }
}