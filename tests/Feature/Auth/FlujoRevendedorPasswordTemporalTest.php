<?php

namespace Tests\Feature\Auth;

use App\Models\AceptacionLegal;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Flujo completo de un revendedor dado de alta desde el panel con
 * contrasena temporal: alta -> confirma correo -> entra a la app con la
 * temporal -> acepta terminos -> cambia la contrasena -> entra con la nueva.
 *
 * Bug de produccion: la app pide aceptar los legales ANTES de cambiar la
 * contrasena, pero el middleware DebeCambiarPassword respondia 403 a
 * /api/auth/aceptar-legales, asi que el revendedor se quedaba atorado en la
 * pantalla de terminos y nunca llegaba a cambiarla.
 */
class FlujoRevendedorPasswordTemporalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const EMAIL = 'nuevo.revendedor@revendedor.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** La distribuidora lo da de alta y regresa la temporal que se le mostro. */
    private function altaDesdeElPanel(): string
    {
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());

        Livewire::test('distribuidora.usuarios')
            ->call('abrirFormularioAfiliarRevendedor')
            ->set('revendedor_nombre', 'Nuevo Revendedor')
            ->set('revendedor_acceso_email', self::EMAIL)
            ->call('guardarRevendedor')
            ->assertHasNoErrors();

        preg_match('/temporal para '.preg_quote(self::EMAIL, '/').': (\S+)\. /u', (string) session('aviso_password_temporal'), $m);
        $this->assertNotEmpty($m[1] ?? null, 'Debe mostrar la contrasena temporal.');

        return $m[1];
    }

    private function confirmarCorreo(): void
    {
        $cuenta = Usuario::where('email', self::EMAIL)->firstOrFail();

        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id'   => $cuenta->id,
            'hash' => sha1($cuenta->email),
        ]))->assertOk();

        $this->assertTrue($cuenta->fresh()->hasVerifiedEmail());
    }

    /** Login de la app; regresa el token Bearer. */
    private function loginApp(string $password): array
    {
        $this->app['auth']->forgetGuards();

        $respuesta = $this->postJson('/api/auth/login', [
            'email'       => self::EMAIL,
            'password'    => $password,
            'device_name' => 'prueba',
        ])->assertOk();

        return $respuesta->json('data');
    }

    private function conToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function aceptarLegales(string $token)
    {
        return $this->conToken($token)->postJson('/api/auth/aceptar-legales', [
            'documentos' => [
                ['tipo_documento' => 'aviso_privacidad', 'version' => '1.0'],
                ['tipo_documento' => 'terminos_condiciones', 'version' => '1.0'],
            ],
        ]);
    }

    public function test_con_contrasena_temporal_puede_aceptar_los_terminos(): void
    {
        $temporal = $this->altaDesdeElPanel();
        $this->confirmarCorreo();
        $sesion = $this->loginApp($temporal);

        $this->assertTrue($sesion['debe_cambiar_password']);
        $this->assertFalse($sesion['legales_aceptados']);

        $this->aceptarLegales($sesion['token'])->assertCreated();

        $cuenta = Usuario::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame(2, AceptacionLegal::where('usuario_id', $cuenta->id)->count());
        // Aceptar no toca la contrasena ni la marca de temporal.
        $this->assertTrue(Hash::check($temporal, $cuenta->password));
        $this->assertTrue((bool) $cuenta->debe_cambiar_password);
    }

    public function test_flujo_completo_alta_correo_terminos_cambio_y_login_con_la_nueva(): void
    {
        $temporal = $this->altaDesdeElPanel();
        $this->confirmarCorreo();

        $sesion = $this->loginApp($temporal);
        $token = $sesion['token'];

        $this->aceptarLegales($token)->assertCreated();

        $this->conToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.legales_aceptados', true)
            ->assertJsonPath('data.debe_cambiar_password', true)
            ->assertJsonPath('data.email_verificado', true);

        // Lo demas sigue bloqueado hasta que la cambie.
        $this->conToken($token)->getJson('/api/catalogo')->assertStatus(403);

        $this->conToken($token)->postJson('/api/perfil/password', [
            'password_actual'       => $temporal,
            'password'              => 'MiClaveNueva2026',
            'password_confirmation' => 'MiClaveNueva2026',
        ])->assertOk();

        $cuenta = Usuario::where('email', self::EMAIL)->firstOrFail();
        $this->assertFalse((bool) $cuenta->debe_cambiar_password);

        // La temporal ya no sirve; la nueva si, y entra directo.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => $temporal])
            ->assertStatus(422);

        $nueva = $this->loginApp('MiClaveNueva2026');
        $this->assertFalse($nueva['debe_cambiar_password']);
        $this->assertTrue($nueva['legales_aceptados']);

        $this->conToken($nueva['token'])->getJson('/api/catalogo')->assertOk();
    }
    // ------------------------------------------------------------------
    // Segundo sintoma: restablecer con el enlace del correo no quitaba la
    // marca de temporal y la app seguia pidiendo cambiarla.
    // ------------------------------------------------------------------

    private function tokenDeRestablecer(): string
    {
        return Password::broker('users')->createToken(Usuario::where('email', self::EMAIL)->firstOrFail());
    }

    public function test_restablecer_por_enlace_en_la_web_quita_la_temporal_y_muestra_el_aviso(): void
    {
        $this->altaDesdeElPanel();
        $this->confirmarCorreo();
        $this->app['auth']->guard('web')->logout();

        Livewire::withQueryParams(['email' => self::EMAIL])
            ->test('auth.reset-password', ['token' => $this->tokenDeRestablecer()])
            ->set('password', 'ClaveDelCorreo2026')
            ->set('password_confirmation', 'ClaveDelCorreo2026')
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSee('Listo, se ha restablecido tu contraseña.')
            ->assertSee('Ya puedes volver a iniciar sesión desde la app')
            ->assertSee('Abrir la app');

        $this->assertFalse((bool) Usuario::where('email', self::EMAIL)->value('debe_cambiar_password'));

        $sesion = $this->loginApp('ClaveDelCorreo2026');
        $this->assertFalse($sesion['debe_cambiar_password']);

        $this->aceptarLegales($sesion['token'])->assertCreated();
        $this->conToken($sesion['token'])->getJson('/api/catalogo')->assertOk();
    }

    public function test_restablecer_por_la_api_tambien_quita_la_temporal(): void
    {
        $this->altaDesdeElPanel();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/reset-password', [
            'email'                 => self::EMAIL,
            'token'                 => $this->tokenDeRestablecer(),
            'password'              => 'ClaveDelCorreo2026',
            'password_confirmation' => 'ClaveDelCorreo2026',
        ])->assertOk();

        $this->assertFalse($this->loginApp('ClaveDelCorreo2026')['debe_cambiar_password']);
    }

    public function test_solo_la_distribuidora_activa_la_temporal(): void
    {
        $this->altaDesdeElPanel();
        $this->assertTrue((bool) Usuario::where('email', self::EMAIL)->value('debe_cambiar_password'));

        // La persona la cambia ella misma (enlace) y luego la distribuidora
        // le genera otra: vuelve a quedar marcada.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/reset-password', [
            'email'                 => self::EMAIL,
            'token'                 => $this->tokenDeRestablecer(),
            'password'              => 'ClaveDelCorreo2026',
            'password_confirmation' => 'ClaveDelCorreo2026',
        ])->assertOk();
        $this->assertFalse((bool) Usuario::where('email', self::EMAIL)->value('debe_cambiar_password'));

        $cuenta = Usuario::where('email', self::EMAIL)->firstOrFail();
        app(\App\Services\Auth\GenerarPasswordTemporalAction::class)
            ->ejecutar($cuenta, 'revendedor', (int) \App\Models\Revendedor::where('usuario_id', $cuenta->id)->value('id'));

        $this->assertTrue((bool) $cuenta->fresh()->debe_cambiar_password);
    }
}
