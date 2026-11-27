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
 * Rediseño del login y de las pantallas de acceso (Gabriel, 7 y 27 nov).
 *
 * En celular todo se ve como antes: tarjeta blanca centrada con el logo
 * dentro y sin panel. En escritorio (lg) sale el panel de marca y el
 * formulario va en una tarjeta de vidrio oscuro con los colores del panel.
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
        $this->assertStringContainsString('fp-acceso-tarjeta', $html, "{$pantalla}: el formulario ya no va en su tarjeta.");
        $this->assertSame(1, substr_count($html, '<h1'), "{$pantalla}: tiene que haber un solo h1.");
        $this->assertSame(1, substr_count($html, '<main'), "{$pantalla}: falta o sobra la región principal.");
    }

    /** El logo dentro de la tarjeta, que es el que se ve en celular. */
    private function assertLogoEnLaTarjeta(string $html, string $pantalla): void
    {
        $tarjeta = substr($html, (int) strpos($html, 'fp-acceso-tarjeta'));

        $this->assertStringContainsString('fp-acceso-logo', $tarjeta, "{$pantalla}: en celular se quedó sin logo.");
        $this->assertStringContainsString('brand/logo-full-160.png', $tarjeta, "{$pantalla}: en celular se quedó sin logo.");
    }

    // --- Las vistas cargan -------------------------------------------------

    public function test_el_login_carga_con_el_panel_de_marca(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertPanelDeMarca($html, 'login');
        $this->assertLogoEnLaTarjeta($html, 'login');
        $this->assertStringContainsString('Inicia sesión en tu cuenta', $html);
    }

    public function test_recuperar_contrasena_carga_con_el_panel(): void
    {
        $html = $this->get(route('password.request'))->assertOk()->getContent();

        $this->assertPanelDeMarca($html, 'recuperar');
        $this->assertLogoEnLaTarjeta($html, 'recuperar');
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
        $this->assertLogoEnLaTarjeta($html, 'restablecer');
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
        $this->assertLogoEnLaTarjeta($html, 'confirmar correo');
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
            // Como antes: esta pantalla nunca llevó logo en la tarjeta.
            ->assertDontSeeHtml('fp-acceso-logo')
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
        $this->assertMatchesRegularExpression('/animation:\s*fp-acceso-panel-entrada\s*\.4s/', $hoja);
        $this->assertMatchesRegularExpression('/\.fp-acceso-logo \{\s*animation:\s*fp-acceso-logo\s*\.4s/', $hoja);
    }

    /** Quien pide menos movimiento no ve la entrada, ni las burbujas, ni el panel. */
    public function test_el_panel_y_las_burbujas_respetan_el_movimiento_reducido(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{[^}]*\.fp-acceso-panel[^}]*\.fp-acceso-burbuja[^}]*animation:\s*none\s*!important/s',
            $this->hoja()
        );
    }

    /**
     * En celular no sale el panel y el logo va en la tarjeta, como antes; desde
     * lg aparece el panel y el logo de la tarjeta se esconde (ya está en el panel).
     */
    public function test_en_celular_no_sale_el_panel_y_el_logo_va_en_la_tarjeta(): void
    {
        $hoja = $this->hoja();
        $escritorio = substr($hoja, (int) strpos($hoja, '/* --- Escritorio: panel de marca'));
        $celular = substr($hoja, 0, (int) strpos($hoja, '/* --- Escritorio: panel de marca'));

        $this->assertMatchesRegularExpression('/\.fp-acceso-panel \{ display: none; \}/', $celular, 'En celular volvió a salir el panel.');
        $this->assertMatchesRegularExpression('/\.fp-acceso-acento \{\s*display: none;/', $celular, 'En celular sale el trazo de escritorio.');
        $this->assertStringNotContainsString('.fp-acceso-logo { display: none; }', $celular, 'En celular se escondió el logo de la tarjeta.');

        $this->assertMatchesRegularExpression('/@media \(min-width: 1024px\) \{\s*\.fp-acceso-marco \{\s*display: grid;/', $escritorio);
        $this->assertMatchesRegularExpression('/\.fp-acceso-panel \{[^}]*display: flex;/s', $escritorio);
        $this->assertStringContainsString('.fp-acceso-logo { display: none; }', $escritorio);

        // El fondo de celular es el de siempre: claro, sin el marino de escritorio.
        $this->assertMatchesRegularExpression('/\.fp-acceso-fondo \{[^}]*linear-gradient\(160deg, var\(--color-fp-page\) 0%, #fff 45%, var\(--color-fp-page\) 100%\)/s', $celular);
    }

    /** En escritorio la tarjeta es vidrio oscuro, con respaldo casi sólido y texto claro. */
    public function test_en_escritorio_el_formulario_es_vidrio_oscuro(): void
    {
        $hoja = $this->hoja();
        $vidrio = substr($hoja, (int) strpos($hoja, 'Escritorio: el formulario en vidrio oscuro.'));
        $vidrio = substr($vidrio, 0, (int) strpos($vidrio, '@media (prefers-reduced-motion: reduce)'));

        $this->assertStringContainsString('backdrop-filter: blur(', $vidrio);
        $this->assertStringContainsString('background-color: rgb(17 30 56 / .92)', $vidrio, 'Sin soporte de desenfoque la tarjeta tiene que quedar casi sólida.');
        $this->assertStringContainsString('@supports', $vidrio);
        // Errores, enlaces y el botón Mostrar de x-ui.campo-password en claro.
        $this->assertStringContainsString('#FCA5A5', $vidrio);
        $this->assertStringContainsString('.fp-enlace { color: #BFD4FF; }', $vidrio);
        $this->assertStringContainsString('.relative > button[type=button]', $vidrio);
        // Entrar en blanco con texto marino.
        $this->assertMatchesRegularExpression('/\.fp-btn-oscuro \{[^}]*background-color: #fff;[^}]*color: var\(--color-fp-sidebar\)/s', $vidrio);
        // Foco visible en claro.
        $this->assertStringContainsString('outline: 2px solid #fff', $vidrio);
        // Quien pide menos transparencia la ve sólida.
        $this->assertMatchesRegularExpression('/prefers-reduced-transparency: reduce\)\s*\{[^}]*\.fp-acceso-tarjeta[^}]*backdrop-filter: none/s', $hoja);
    }

    /** El vidrio de la tarjeta vive en app.css; en las vistas no hay clases de vidrio sueltas. */
    public function test_el_vidrio_es_solo_un_acento(): void
    {
        $layout = (string) file_get_contents(base_path('resources/views/layouts/guest.blade.php'));

        $this->assertStringContainsString('fp-acceso-placa-logo fp-vidrio', $layout);
        $this->assertStringContainsString('fp-vidrio-oscuro', $layout);

        foreach (['login', 'forgot-password', 'reset-password', 'correo-verificado', 'cambiar-password'] as $pantalla) {
            $vista = (string) file_get_contents(base_path('resources/views/components/auth/⚡'.$pantalla.'.blade.php'));
            $this->assertStringNotContainsString('fp-vidrio', $vista, "{$pantalla}: el vidrio de la tarjeta lo pone app.css, no la vista.");
        }
    }
}
