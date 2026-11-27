<?php

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rediseño del login y de las pantallas de acceso (acordado con Gabriel el
 * 7 nov): formulario limpio junto a un panel con los colores de la marca, que
 * en celular se vuelve una cabecera compacta.
 *
 * Es un cambio de diseño. Lo que estas pruebas cuidan es que con el diseño
 * nuevo nada se haya roto: las pantallas cargan, el formulario y sus enlaces
 * siguen ahí, el login funciona y el layout no mete nada que choque con la
 * CSP (script-src 'self').
 */
class RedisenoLoginTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function hoja(): string
    {
        return (string) file_get_contents(base_path('resources/css/app.css'));
    }

    /** El panel de marca: logo, frase y las formas decorativas. */
    private function assertPanelDeMarca(string $html, string $pantalla): void
    {
        $this->assertStringContainsString('fp-acceso-marco', $html, "{$pantalla}: falta la composición de dos lados.");
        $this->assertStringContainsString('fp-acceso-panel', $html, "{$pantalla}: falta el panel de marca.");
        $this->assertStringContainsString('brand/logo-full-160.png', $html, "{$pantalla}: el panel se quedó sin logo.");
        $this->assertStringContainsString('Calzado, pedidos y entregas en un solo lugar.', $html);
        $this->assertStringContainsString('fp-acceso-burbuja', $html);
        $this->assertStringContainsString('fp-acceso-tarjeta', $html, "{$pantalla}: el formulario ya no va en su tarjeta sólida.");
        $this->assertSame(1, substr_count($html, '<h1'), "{$pantalla}: tiene que haber un solo h1.");
        $this->assertSame(1, substr_count($html, '<main'), "{$pantalla}: falta o sobra la región principal.");
    }

    // --- Las vistas cargan -------------------------------------------------

    public function test_el_login_carga_con_el_panel_de_marca(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertPanelDeMarca($html, 'login');
        $this->assertStringContainsString('Inicia sesión en tu cuenta', $html);
    }

    public function test_recuperar_contrasena_carga_con_el_panel(): void
    {
        $html = $this->get(route('password.request'))->assertOk()->getContent();

        $this->assertPanelDeMarca($html, 'recuperar');
        $this->assertStringContainsString('Recuperar contraseña', $html);
        $this->assertStringContainsString('wire:submit="sendResetLink"', $html);
        $this->assertStringContainsString('href="'.route('login').'"', $html);
    }

    public function test_restablecer_contrasena_carga_con_el_panel(): void
    {
        $html = $this->get(route('password.reset', ['token' => 'token-de-prueba', 'email' => 'alguien@correo.test']))
            ->assertOk()
            ->getContent();

        $this->assertPanelDeMarca($html, 'restablecer');
        $this->assertStringContainsString('Nueva contraseña', $html);
        $this->assertStringContainsString('wire:submit="resetPassword"', $html);
        $this->assertStringContainsString('id="reset-password-password"', $html);
    }

    public function test_confirmar_correo_carga_con_el_panel(): void
    {
        $usuario = Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();
        $usuario->forceFill(['email_verified_at' => null])->save();

        $enlace = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $usuario->id,
            'hash' => sha1($usuario->getEmailForVerification()),
        ]);

        $html = $this->get($enlace)->assertOk()->getContent();

        $this->assertPanelDeMarca($html, 'confirmar correo');
        $this->assertStringContainsString('Correo confirmado', $html);
        $this->assertStringContainsString('href="'.route('login').'"', $html);
    }

    public function test_cambio_de_contrasena_temporal_usa_la_tarjeta_de_acceso(): void
    {
        $usuario = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
        $usuario->forceFill(['debe_cambiar_password' => true])->save();

        $this->actingAs($usuario);

        Livewire::test('auth.cambiar-password')
            ->assertOk()
            ->assertSee('Cambia tu contraseña')
            ->assertSeeHtml('fp-acceso-tarjeta')
            ->assertSeeHtml('fp-acceso-acento')
            ->assertSeeHtml('id="cambiar-actual"')
            ->assertSeeHtml('id="cambiar-nueva"')
            ->assertSeeHtml('wire:submit="guardar"');
    }

    // --- El formulario y los enlaces siguen --------------------------------

    public function test_el_formulario_del_login_conserva_campos_y_enlaces(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('wire:submit="login"', $html);
        $this->assertStringContainsString('<label for="login-email"', $html);
        $this->assertStringContainsString('id="login-email"', $html);
        // La contraseña viene del componente x-ui.campo-password (mostrar u ocultar).
        $this->assertStringContainsString('id="login-password"', $html);
        $this->assertStringContainsString('for="login-password"', $html);
        $this->assertStringContainsString('fpCampoPassword', $html);

        $this->assertStringContainsString('href="'.route('password.request').'"', $html);
        $this->assertStringContainsString('¿Olvidaste tu contraseña?', $html);
        $this->assertStringContainsString('href="'.route('inicio').'"', $html);
        $this->assertStringContainsString('Volver al inicio', $html);

        $this->assertStringContainsString('Entrar', $html);
        // Estado de cargando al enviar, con su giro.
        $this->assertStringContainsString('Entrando…', $html);
        $this->assertStringContainsString('fp-acceso-spinner', $html);
    }

    // --- El login funciona -------------------------------------------------

    public function test_el_login_sigue_funcionando(): void
    {
        $usuario = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();

        Livewire::test('auth.login')
            ->set('email', 'empleado@calzadosramirez.test')
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(Auth::check());
        $this->assertSame($usuario->id, Auth::id());
    }

    public function test_con_credenciales_malas_sigue_avisando_en_espanol(): void
    {
        Livewire::test('auth.login')
            ->set('email', 'empleado@calzadosramirez.test')
            ->set('password', 'no-es-esta')
            ->call('login')
            ->assertHasErrors('email')
            ->assertSee('Las credenciales son incorrectas.');

        $this->assertGuest();
    }

    // --- CSP, movimiento y vidrio ----------------------------------------------

    /** script-src 'self': el layout no trae scripts ni estilos en línea. */
    public function test_el_layout_no_mete_nada_en_linea(): void
    {
        $layout = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(base_path('resources/views/layouts/guest.blade.php')));

        $this->assertStringNotContainsString('<script', $layout);
        $this->assertStringNotContainsString('<style', $layout);
        $this->assertDoesNotMatchRegularExpression('/\sstyle=/', $layout);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $layout, 'Un manejador onclick=… en línea rompe la CSP.');
    }

    /** Las entradas duran entre 200 y 400 ms, nada de efectos largos. */
    public function test_las_entradas_son_cortas(): void
    {
        $hoja = $this->hoja();

        $this->assertMatchesRegularExpression('/\.fp-acceso-tarjeta \{[^}]*animation:\s*fp-acceso-entrada\s*\.35s/s', $hoja);
        $this->assertMatchesRegularExpression('/\.fp-acceso-panel \{[^}]*animation:\s*fp-acceso-cabecera-entrada\s*\.3s/s', $hoja);
        $this->assertMatchesRegularExpression('/animation-name:\s*fp-acceso-panel-entrada;\s*animation-duration:\s*\.4s/', $hoja);
    }

    /** Quien pide menos movimiento no ve la entrada, ni las burbujas, ni el panel. */
    public function test_el_panel_y_las_burbujas_respetan_el_movimiento_reducido(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{[^}]*\.fp-acceso-panel[^}]*\.fp-acceso-burbuja[^}]*animation:\s*none\s*!important/s',
            $this->hoja()
        );
    }

    /** En celular el panel es cabecera; a partir de lg, columna lateral. */
    public function test_el_panel_cambia_de_forma_entre_celular_y_escritorio(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 1024px\) \{\s*\.fp-acceso-marco \{\s*display: grid;/',
            $this->hoja()
        );
    }

    /** Liquid glass solo como acento: la placa del logo y los puntos del panel, nunca el formulario. */
    public function test_el_vidrio_es_solo_un_acento(): void
    {
        $layout = (string) file_get_contents(base_path('resources/views/layouts/guest.blade.php'));

        $this->assertStringContainsString('fp-acceso-placa-logo fp-vidrio', $layout);
        $this->assertStringContainsString('fp-vidrio-oscuro', $layout);

        foreach (['login', 'forgot-password', 'reset-password', 'correo-verificado', 'cambiar-password'] as $pantalla) {
            $vista = (string) file_get_contents(base_path('resources/views/components/auth/⚡'.$pantalla.'.blade.php'));
            $this->assertStringNotContainsString('fp-vidrio', $vista, "{$pantalla}: el formulario va sólido, sin vidrio.");
        }
    }
}
