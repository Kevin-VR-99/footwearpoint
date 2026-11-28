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
 * dentro y sin la escena de la app. En escritorio (lg) se copia el login
 * de la app (fondo marino, siluetas, línea de marca, logo centrado) y se
 * mejora con vidrio claro y más luz.
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
        $this->assertStringContainsString('fp-acceso-marco', $html, "{$pantalla}: falta la escena de acceso.");
        $this->assertStringContainsString('fp-acceso-linea', $html, "{$pantalla}: falta la línea de marca.");
        $this->assertStringContainsString('fp-acceso-siluetas', $html, "{$pantalla}: faltan las siluetas de tenis.");
        $this->assertStringContainsString('brand/logo-mark-white-192.png', $html, "{$pantalla}: no usa el PNG de la app.");
        $this->assertStringContainsString('Footwear Point', $html);
        $this->assertStringContainsString('brand/logo-full-160.png', $html, "{$pantalla}: en celular se quedó sin el logo de la tarjeta.");
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
        $this->assertMatchesRegularExpression('/\.fp-acceso-marca \{[^}]*animation:\s*fp-acceso-entrada\s*\.35s/s', $hoja);
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
     * En celular no salen la línea, las siluetas ni la cabecera de la app, y
     * el logo va en la tarjeta, como antes. Desde lg aparece la escena de la
     * app y el logo de la tarjeta se esconde (ya está arriba, centrado).
     */
    public function test_en_celular_no_sale_el_panel_y_el_logo_va_en_la_tarjeta(): void
    {
        $hoja = $this->hoja();
        $escritorio = substr($hoja, (int) strpos($hoja, '/* --- Escritorio: escena de la app'));
        $celular = substr($hoja, 0, (int) strpos($hoja, '/* --- Escritorio: escena de la app'));

        $this->assertStringContainsString('.fp-acceso-marca, .fp-acceso-siluetas, .fp-acceso-linea { display: none; }', $celular, 'En celular salió la escena de la app.');
        $this->assertMatchesRegularExpression('/\.fp-acceso-acento \{\s*display: none;/', $celular);
        $this->assertStringNotContainsString('.fp-acceso-logo { display: none; }', $celular, 'En celular se escondió el logo de la tarjeta.');

        $this->assertMatchesRegularExpression('/\.fp-acceso-marca \{\s*display: flex;/', $escritorio);
        $this->assertMatchesRegularExpression('/\.fp-acceso-siluetas \{\s*display: block;/', $escritorio);
        $this->assertStringContainsString('.fp-acceso-linea', $escritorio);
        $this->assertStringContainsString('.fp-acceso-logo { display: none; }', $escritorio);
        $this->assertStringContainsString('#111E38', $escritorio, 'El fondo de escritorio tiene que partir del marino de la app.');

        // El fondo de celular es el de siempre: claro, sin el marino de escritorio.
        $this->assertMatchesRegularExpression('/\.fp-acceso-fondo \{[^}]*linear-gradient\(160deg, var\(--color-fp-page\) 0%, #fff 45%, var\(--color-fp-page\) 100%\)/s', $celular);
    }

    /** En escritorio la tarjeta es vidrio claro, con respaldo casi sólido y texto oscuro. */
    public function test_en_escritorio_el_formulario_es_vidrio_claro(): void
    {
        $hoja = $this->hoja();
        $vidrio = substr($hoja, (int) strpos($hoja, 'Escritorio: el formulario en vidrio claro'));
        $vidrio = substr($vidrio, 0, (int) strpos($vidrio, '@media (prefers-reduced-motion: reduce)'));

        $this->assertStringContainsString('backdrop-filter: blur(', $vidrio);
        $this->assertStringContainsString('background-color: rgb(255 255 255 / .92)', $vidrio, 'Sin soporte de desenfoque la tarjeta tiene que quedar casi sólida y clara.');
        $this->assertStringContainsString('background-color: rgb(255 255 255 / .65)', $vidrio, 'El vidrio tiene que ser blanco translúcido, no oscuro.');
        $this->assertStringContainsString('@supports', $vidrio);
        // Campos y errores en oscuro sobre claro; el botón Mostrar también.
        $this->assertStringContainsString('color: var(--color-slate-800)', $vidrio);
        $this->assertStringContainsString('color: var(--color-fp-danger)', $vidrio);
        $this->assertStringContainsString('.relative > button[type=button]', $vidrio);
        // Entrar: degradado azul de la marca con texto blanco.
        $this->assertMatchesRegularExpression('/\.fp-btn-oscuro \{[^}]*background-image:\s*linear-gradient\([^}]*#2563EB[^}]*color:\s*#fff/s', $vidrio);
        // Foco visible en el azul de la marca.
        $this->assertStringContainsString('outline: 2px solid var(--color-fp-primary)', $vidrio);
        // Quien pide menos transparencia la ve sólida y blanca.
        $this->assertMatchesRegularExpression('/prefers-reduced-transparency: reduce\)\s*\{[^}]*\.fp-acceso-tarjeta[^}]*background-color: #fff/s', $hoja);
    }

    /** El logo de escritorio es el mismo PNG de la app, centrado y sin placa. */
    public function test_el_logo_del_panel_es_png_centrado_y_sin_placa(): void
    {
        $layout = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(base_path('resources/views/layouts/guest.blade.php')));
        $hoja = $this->hoja();
        $escritorio = substr($hoja, (int) strpos($hoja, '/* --- Escritorio: escena de la app'));

        $this->assertStringContainsString('brand/logo-mark-white-192.png', $layout);
        $this->assertStringContainsString('Footwear Point', $layout);
        $this->assertStringContainsString('fp-acceso-siluetas', $layout);
        $this->assertStringContainsString('silueta-tenis.svg', $hoja);
        $this->assertStringNotContainsString('fp-acceso-placa-logo', $layout);
        $this->assertStringNotContainsString('fp-vidrio', $layout, 'El logo ya no va en una placa de vidrio.');
        $this->assertStringNotContainsString('fp-acceso-panel-contenido', $layout, 'Volvió el panel lateral: se prioriza la composición de la app.');

        $this->assertMatchesRegularExpression('/\.fp-acceso-marca \{[^}]*align-items:\s*center;[^}]*text-align:\s*center;/s', $escritorio);
        $this->assertMatchesRegularExpression('/\.fp-acceso-linea \{[^}]*--color-fp-primary[^}]*--color-fp-danger/s', $escritorio);

        foreach (['login', 'forgot-password', 'reset-password', 'correo-verificado', 'cambiar-password'] as $pantalla) {
            $vista = (string) file_get_contents(base_path('resources/views/components/auth/⚡'.$pantalla.'.blade.php'));
            $this->assertStringNotContainsString('fp-vidrio', $vista, "{$pantalla}: el vidrio de la tarjeta lo pone app.css, no la vista.");
        }
    }
}
