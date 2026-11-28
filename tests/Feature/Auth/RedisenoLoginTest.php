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
 * En celular (menos de 1024 px) se copia el login de la app Flutter
 * (fondo marino, siluetas, línea de marca, logo centrado) y se mejora
 * con vidrio claro. En escritorio (lg) vuelve la composición de la ronda
 * 2, aclarada: panel de marca a la izquierda y formulario de vidrio a
 * la derecha, sin las siluetas ni la escena centrada de la app.
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

    /** Las dos caras del acceso: panel de escritorio y escena de la app en celular. */
    private function assertPanelDeMarca(string $html, string $pantalla): void
    {
        $this->assertStringContainsString('fp-acceso-marco', $html, "{$pantalla}: falta la escena de acceso.");
        $this->assertStringContainsString('fp-acceso-panel', $html, "{$pantalla}: falta el panel de marca de escritorio.");
        $this->assertStringContainsString('Calzado, pedidos y entregas en un solo lugar.', $html);
        $this->assertStringContainsString('<li>Catálogo</li>', $html);
        $this->assertStringContainsString('<li>Pedidos</li>', $html);
        $this->assertStringContainsString('<li>Pagos</li>', $html);
        $this->assertStringContainsString('<li>Stock</li>', $html);
        $this->assertStringNotContainsString('<li>Entregas</li>', $html);
        $this->assertStringContainsString('fp-acceso-linea', $html, "{$pantalla}: falta la línea de marca de celular.");
        $this->assertStringContainsString('fp-acceso-siluetas', $html, "{$pantalla}: faltan las siluetas de tenis de celular.");
        $this->assertStringContainsString('fp-acceso-marca', $html, "{$pantalla}: falta la cabecera de la app en celular.");
        $this->assertStringContainsString('brand/logo-mark-white-192.png', $html, "{$pantalla}: no usa el PNG de la app.");
        $this->assertStringContainsString('Footwear Point', $html);
        $this->assertStringContainsString('brand/logo-full-160.png', $html, "{$pantalla}: el encabezado compartido ya no trae el logo.");
        $this->assertStringContainsString('fp-acceso-tarjeta', $html, "{$pantalla}: el formulario ya no va en su tarjeta.");
        $this->assertSame(1, substr_count($html, '<h1'), "{$pantalla}: tiene que haber un solo h1.");
        $this->assertSame(1, substr_count($html, '<main'), "{$pantalla}: falta o sobra la región principal.");
    }

    /** El encabezado compartido sigue en el HTML (en pantalla se esconde: el logo ya está arriba o en el panel). */
    private function assertLogoEnLaTarjeta(string $html, string $pantalla): void
    {
        $this->assertStringContainsString('fp-acceso-logo', $html, "{$pantalla}: desapareció el encabezado compartido.");
        $this->assertStringContainsString('brand/logo-full-160.png', $html, "{$pantalla}: desapareció el encabezado compartido.");
    }

    // --- Las vistas cargan -------------------------------------------------

    public function test_el_login_carga_con_el_panel_de_marca(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertPanelDeMarca($html, 'login');
        $this->assertLogoEnLaTarjeta($html, 'login');
        $this->assertStringContainsString('Inicia sesión en tu cuenta', $html);
        $this->assertStringContainsString('Inicia sesión para continuar', $html);
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
     * En celular sale la escena de la app (línea, siluetas, cabecera) y el
     * panel de escritorio se esconde. Desde lg vuelve el panel, se apagan
     * las siluetas y el fondo del formulario es claro.
     */
    public function test_en_celular_sale_la_escena_de_la_app_y_en_escritorio_el_panel(): void
    {
        $hoja = $this->hoja();
        $corte = '/* --- Escritorio: panel de marca';
        $this->assertNotFalse(strpos($hoja, $corte), 'Falta el bloque de escritorio en app.css.');
        $escritorio = substr($hoja, (int) strpos($hoja, $corte));
        $celular = substr($hoja, 0, (int) strpos($hoja, $corte));

        $this->assertStringContainsString('.fp-acceso-panel { display: none; }', $celular, 'En celular salió el panel de escritorio.');
        $this->assertMatchesRegularExpression('/\.fp-acceso-linea \{[^}]*display:\s*block;/s', $celular);
        $this->assertMatchesRegularExpression('/\.fp-acceso-siluetas \{[^}]*display:\s*block;/s', $celular);
        $this->assertMatchesRegularExpression('/\.fp-acceso-marca \{[^}]*display:\s*flex;/s', $celular);
        $this->assertStringContainsString('#111E38', $celular, 'El fondo de celular tiene que partir del marino de la app.');
        $this->assertStringContainsString('silueta-tenis.svg', $celular);
        $this->assertStringContainsString('icono-correo.svg', $celular);
        $this->assertStringContainsString('icono-candado.svg', $celular);
        $this->assertStringContainsString('.fp-acceso-logo, .fp-acceso-acento { display: none; }', $celular);

        $this->assertStringContainsString('.fp-acceso-linea, .fp-acceso-siluetas, .fp-acceso-marca { display: none; }', $escritorio, 'En escritorio no se apagó la escena de la app.');
        $this->assertMatchesRegularExpression('/\.fp-marca-luminosa \{[^}]*#1E3A8A[^}]*#2563EB/s', $hoja, 'El panel de escritorio tiene que usar la paleta luminosa compartida.');
        $this->assertStringContainsString('fp-marca-luminosa', (string) file_get_contents(base_path('resources/views/layouts/guest.blade.php')));
        $this->assertStringContainsString('#DBEAFE', $escritorio, 'El fondo del formulario en escritorio tiene que ser luminoso.');
        $this->assertStringContainsString('background-image: none', $escritorio, 'En escritorio no van los íconos de campo de la app.');
        $this->assertStringNotContainsString('#111E38', $escritorio, 'El marino de la app no puede ser el fondo de escritorio.');
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

    /** El logo (panel y celular) es el mismo PNG de la app, centrado y sin placa. */
    public function test_el_logo_del_panel_es_png_centrado_y_sin_placa(): void
    {
        $layout = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(base_path('resources/views/layouts/guest.blade.php')));
        $hoja = $this->hoja();
        $escritorio = substr($hoja, (int) strpos($hoja, '/* --- Escritorio: panel de marca'));
        $celular = substr($hoja, 0, (int) strpos($hoja, '/* --- Escritorio: panel de marca'));

        $this->assertStringContainsString('brand/logo-mark-white-192.png', $layout);
        $this->assertStringContainsString('Footwear Point', $layout);
        $this->assertStringContainsString('fp-acceso-panel-contenido', $layout, 'Falta el panel lateral de escritorio.');
        $this->assertStringContainsString('fp-acceso-siluetas', $layout);
        $this->assertStringContainsString('silueta-tenis.svg', $hoja);
        $this->assertStringNotContainsString('fp-acceso-placa-logo', $layout);
        $this->assertStringNotContainsString('fp-vidrio', $layout, 'El logo ya no va en una placa de vidrio.');

        $this->assertMatchesRegularExpression('/\.fp-acceso-panel-contenido \{[^}]*align-items:\s*center;[^}]*text-align:\s*center;/s', $escritorio);
        $this->assertMatchesRegularExpression('/\.fp-acceso-marca \{[^}]*align-items:\s*center;[^}]*text-align:\s*center;/s', $celular);
        $this->assertMatchesRegularExpression('/\.fp-acceso-linea \{[^}]*--color-fp-primary[^}]*--color-fp-danger/s', $celular);

        foreach (['login', 'forgot-password', 'reset-password', 'correo-verificado', 'cambiar-password'] as $pantalla) {
            $vista = (string) file_get_contents(base_path('resources/views/components/auth/⚡'.$pantalla.'.blade.php'));
            $this->assertStringNotContainsString('fp-vidrio', $vista, "{$pantalla}: el vidrio de la tarjeta lo pone app.css, no la vista.");
        }
    }
}
