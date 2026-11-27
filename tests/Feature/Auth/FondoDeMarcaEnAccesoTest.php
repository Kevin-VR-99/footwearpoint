<?php

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * TG-390 (A30) — Login y confirmación de correo con fondo de marca.
 *
 * La tarjeta pide cuatro cosas y las cuatro se vigilan aquí: fondo no vacío,
 * formulario sólido y legible, estados de confirmación claros, y SIN ampliar
 * funciones. Esa última es la mitad del trabajo: es una tarjeta de diseño, y
 * lo fácil es que se le cuelguen cosas que nadie pidió.
 *
 * LO QUE SE MIDIÓ, porque «legible» sin número no quiere decir nada. Sobre el
 * blanco de la tarjeta, el borde de los campos daba 1.48 de contraste; WCAG
 * 1.4.11 pide 3.0 para el borde de un control. El botón secundario daba 1.23.
 * Los dos quedaron en 3.67.
 */
class FondoDeMarcaEnAccesoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Las siete pantallas que comparten layouts.guest. */
    private const ACCESO = [
        'login', 'forgot-password', 'reset-password', 'register-empleado',
        'correo-verificado', 'confirmar-cambio-contacto', 'recapturar-telefono',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        // El seeder deja las cuentas de la demostración con el correo ya
        // confirmado (TG-278), porque sus correos son inventados y nadie podría
        // abrir el enlace. Aquí hace falta una sin confirmar para poder ver el
        // estado «listo»: es lo mismo que hace VerificarCorreoTest.
        Usuario::where('email', 'maria.lopez@revendedor.test')
            ->firstOrFail()
            ->forceFill(['email_verified_at' => null])
            ->save();
    }

    private function vista(string $pantalla): string
    {
        return (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) file_get_contents(base_path('resources/views/components/auth/⚡'.$pantalla.'.blade.php'))
        );
    }

    private function hoja(): string
    {
        return (string) file_get_contents(base_path('resources/css/app.css'));
    }

    private function maria(): Usuario
    {
        return Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();
    }

    private function enlaceDeVerificacion(Usuario $usuario, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $usuario->id,
            'hash' => $hash ?? sha1($usuario->getEmailForVerification()),
        ]);
    }

    // --- Fondo no vacío -------------------------------------------------------

    /**
     * El fondo lleva marca, no es blanco pelón.
     *
     * Es lo primero del «listo cuando». Las formas salen de la paleta
     * (fp-primary y fp-danger), no de colores escritos a mano: si alguien
     * cambia la marca, el fondo la sigue.
     */
    public function test_el_fondo_de_acceso_no_esta_vacio(): void
    {
        $hoja = $this->hoja();

        $this->assertMatchesRegularExpression('/\.fp-acceso-fondo\s*\{[^}]*background:\s*linear-gradient/', $hoja);
        $this->assertMatchesRegularExpression('/\.fp-acceso-fondo::before[^}]*var\(--color-fp-primary\)/s', $hoja);
        $this->assertMatchesRegularExpression('/\.fp-acceso-fondo::after[^}]*var\(--color-fp-danger\)/s', $hoja);

        $this->assertStringContainsString('fp-acceso-fondo', (string) file_get_contents(base_path('resources/views/layouts/guest.blade.php')));
    }

    // --- Formulario sólido y legible ------------------------------------------

    /**
     * La tarjeta va sólida, que es la palabra de la tarjeta de Taiga.
     *
     * Era blanco al 92% con desenfoque: encima de las manchas de color el
     * formulario se teñía de lo que tuviera detrás, así que el contraste
     * cambiaba según dónde cayera la tarjeta.
     */
    public function test_la_tarjeta_de_acceso_es_solida(): void
    {
        $bloque = $this->bloqueCss('.fp-acceso-tarjeta');

        $this->assertStringContainsString('background-color: #fff', $bloque, 'La tarjeta volvió a ser semitransparente.');
        $this->assertStringNotContainsString('backdrop-filter', $bloque, 'Volvió el desenfoque: ya no hace falta y vuelve a teñir el formulario.');
    }

    /** El borde de los campos y del botón secundario, visible. */
    public function test_los_controles_de_acceso_tienen_borde_visible(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.fp-acceso-tarjeta\s*:where\(input[^{]*\{\s*border-color:\s*color-mix\([^}]*--color-fp-sidebar/s',
            $this->hoja(),
            'Los campos de acceso volvieron al borde que no se veía (contraste 1.48; WCAG pide 3.0).'
        );

        $this->assertStringContainsString(
            '.fp-acceso-tarjeta .fp-btn-secundario',
            $this->hoja(),
            'El botón secundario volvió a quedarse sin borde visible al lado de los campos.'
        );
    }

    /**
     * El arreglo NO se desbordó al resto del panel.
     *
     * El borde flojo sale de una regla global que usan los formularios de todo
     * el sistema. Arreglarlo ahí habría retocado 22 archivos, y la tarjeta dice
     * «login + confirmación de correo, solo UI». Que el resto tenga el mismo
     * problema es cierto y merece su propia tarjeta; lo que esta prueba impide
     * es que se cuele en esta sin que nadie lo decida.
     */
    public function test_el_borde_del_resto_del_panel_no_se_toco(): void
    {
        // Anclado a ESA regla y no a cualquier slate-300 de la hoja: hay dos
        // mas antes del bloque de acceso, y con una busqueda suelta la prueba
        // pasaba aunque la regla global ya estuviera cambiada.
        $this->assertMatchesRegularExpression(
            '/:where\(input:not\(\[type=.checkbox.\][^{]*\{[^}]*border-color:\s*var\(--color-slate-300\)/s',
            $this->hoja(),
            'Se cambió el borde global de los formularios: eso es otra tarjeta y toca el panel entero.'
        );
    }

    // --- Un solo encabezado ---------------------------------------------------

    /**
     * Las siete pantallas de acceso usan el mismo encabezado, y el logo sale
     * una sola vez.
     *
     * Ya se habían separado solas: el login lo enseñaba a h-28 y las otras seis
     * a h-16. Con el rediseño del 7 nov el logo se fue al panel de marca del
     * layout; el encabezado de cada tarjeta es ahora el trazo de color.
     */
    public function test_todas_las_pantallas_de_acceso_usan_el_mismo_encabezado(): void
    {
        foreach (self::ACCESO as $pantalla) {
            $vista = $this->vista($pantalla);

            $this->assertStringContainsString('<x-auth.encabezado', $vista, "{$pantalla}: no usa el encabezado compartido.");
            $this->assertStringNotContainsString('logo-full-', $vista, "{$pantalla}: volvió a poner su propio logo a mano.");
        }

        // SIN LOS COMENTARIOS: si no, la prueba pasaría por lo que dice un
        // comentario aunque el código hubiera cambiado.
        $sinComentarios = fn (string $ruta) => (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(base_path($ruta)));

        $encabezado = $sinComentarios('resources/views/components/auth/encabezado.blade.php');
        $this->assertStringContainsString('fp-acceso-acento', $encabezado);
        $this->assertStringContainsString('aria-hidden="true"', $encabezado, 'El trazo es decoración: no se lee en voz alta.');
        $this->assertStringNotContainsString('logo-full-', $encabezado, 'El logo volvió a la tarjeta y sale dos veces con el panel.');

        $layout = $sinComentarios('resources/views/layouts/guest.blade.php');
        $this->assertSame(1, substr_count($layout, 'logo-full-'), 'El logo de acceso tiene que salir una sola vez, en el panel.');
    }

    /**
     * El encabezado no trae título.
     *
     * Cada pantalla tiene el suyo y dicen cosas distintas. Si el título
     * viviera en el componente, las que ya tienen h1 acabarían con dos, y el
     * orden de encabezados es lo que usa un lector de pantalla para orientarse.
     */
    public function test_el_login_sigue_teniendo_un_solo_h1(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'), 'La pantalla de acceso se quedó con más de un h1.');
        $this->assertStringContainsString('Inicia sesión en tu cuenta', $html);
    }

    /** La del teléfono ya no se queda suelta encima del fondo. */
    public function test_la_pantalla_del_telefono_tambien_va_en_tarjeta(): void
    {
        $vista = $this->vista('recapturar-telefono');

        $this->assertStringContainsString('fp-acceso-tarjeta', $vista);
        $this->assertStringContainsString('data-recapturar-telefono', $vista, 'Se perdió el campo al cambiar la envoltura.');
    }

    // --- Estados de confirmación claros ---------------------------------------

    /**
     * Los tres estados, con el mismo trato.
     *
     * Antes solo el éxito traía círculo y color; los otros dos eran un título
     * suelto. Quien abría el enlace y leía «Ya estaba confirmado» no tenía más
     * señal que el texto para saber si eso era bueno o un problema.
     */
    public function test_los_tres_estados_de_confirmacion_se_distinguen(): void
    {
        $maria = $this->maria();

        $esperados = [
            'listo' => 'Correo confirmado',
            'ya_estaba' => 'Ya estaba confirmado',
            'invalido' => 'Ese enlace no sirve',
        ];

        // listo y ya_estaba salen del mismo enlace: la segunda vez ya está.
        $enlace = $this->enlaceDeVerificacion($maria);

        $htmls = [
            'listo' => $this->get($enlace)->assertOk()->getContent(),
            'ya_estaba' => $this->get($enlace)->assertOk()->getContent(),
            'invalido' => $this->get($this->enlaceDeVerificacion($maria, sha1('otro@correo.test')))->assertOk()->getContent(),
        ];

        foreach ($esperados as $estado => $titulo) {
            $html = $htmls[$estado];

            $this->assertStringContainsString('data-estado-confirmacion="'.$estado.'"', $html, "Falta el estado {$estado}.");
            $this->assertStringContainsString($titulo, $html);

            // El círculo con su icono: es lo que antes solo tenía el éxito.
            // Se mira el trozo alrededor de la marca y no toda la página, que
            // trae de todo, pero sin regex frágil contra el orden de atributos.
            $trozo = substr($html, max(0, strpos($html, 'data-estado-confirmacion="'.$estado.'"') - 80), 800);

            $this->assertStringContainsString('rounded-full', $trozo, "El estado {$estado} se quedó sin su marca: solo el texto lo distingue.");

            // Un icono no se lee en voz alta y el color tampoco.
            $this->assertStringContainsString('role="status"', $trozo, "El estado {$estado} no se anuncia a quien no ve la pantalla.");
            $this->assertStringContainsString('aria-hidden="true"', $trozo);
        }

        // Y no son todos iguales: cada uno con su color.
        $this->assertStringContainsString('bg-fp-badge-success-bg', $htmls['listo']);
        $this->assertStringContainsString('bg-fp-danger-soft', $htmls['invalido']);
        $this->assertStringNotContainsString('bg-fp-badge-success-bg', $htmls['invalido'], 'El enlace roto se pinta como si todo hubiera salido bien.');
    }

    // --- Sin ampliar funciones -------------------------------------------------

    /**
     * «Estados existentes» y «sin ampliar funciones», dice la tarjeta.
     *
     * Es una tarjeta de diseño: lo fácil es colgarle un botón de reenvío o un
     * estado «expirado» nuevo porque suenan útiles. Serían backend y serían
     * otra tarjeta.
     */
    public function test_no_se_agregaron_estados_ni_funciones_a_la_confirmacion(): void
    {
        $vista = $this->vista('correo-verificado');

        foreach (['expirado', 'reenviar', 'reenvío', 'wire:click', 'wire:submit'] as $cosaNueva) {
            $this->assertStringNotContainsString(
                $cosaNueva,
                mb_strtolower($vista),
                "La confirmación de correo creció con «{$cosaNueva}», y la tarjeta dice sin ampliar funciones."
            );
        }

        // Siguen siendo los tres de siempre, los que decide mount(): se cuentan
        // las definiciones de estado, no las veces que aparece la palabra.
        preg_match_all("/'(listo|ya_estaba|invalido)' => \['titulo'/", $vista, $definidos);

        $this->assertCount(3, $definidos[0], 'Cambió el número de estados de confirmación.');
    }

    /** «Sin flip 3D», dice la tarjeta con todas sus letras. */
    public function test_no_hay_volteretas_en_tres_dimensiones(): void
    {
        $acceso = substr($this->hoja(), strpos($this->hoja(), '@keyframes fp-acceso-deriva'));

        foreach (['rotateY', 'rotateX', 'preserve-3d', 'perspective', 'backface'] as $rastro) {
            $this->assertStringNotContainsString($rastro, $acceso, "Entró un {$rastro} a las pantallas de acceso, y la tarjeta lo prohíbe.");
        }
    }

    /** El movimiento de estas pantallas se sigue apagando a quien lo pide. */
    public function test_el_acceso_respeta_el_movimiento_reducido(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{[^}]*\.fp-acceso-tarjeta[^}]*animation:\s*none\s*!important/s',
            $this->hoja(),
            'Las pantallas de acceso volvieron a animarse para quien pidió movimiento reducido.'
        );
    }

    /** El trozo de app.css de una regla, hasta su llave de cierre. */
    private function bloqueCss(string $selector): string
    {
        $hoja = $this->hoja();
        $inicio = strpos($hoja, $selector.' {');
        $this->assertNotFalse($inicio, "No está la regla {$selector}.");

        return substr($hoja, $inicio, strpos($hoja, '}', $inicio) - $inicio);
    }
}
