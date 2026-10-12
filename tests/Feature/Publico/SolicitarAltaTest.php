<?php

namespace Tests\Feature\Publico;

use App\Mail\SolicitudAltaDistribuidoraMail;
use App\Models\AceptacionLegal;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Usuario;
use App\Services\Distribuidora\LimiteSolicitudesAlta;
use App\Services\Tienda\DominioTienda;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-220 (E2-08) — El formulario público "Solicitar mi alta".
 *
 * La solicitud queda como distribuidora PENDIENTE con su administrador, el
 * admin general recibe aviso, y hay defensa contra envíos automáticos.
 */
class SolicitarAltaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const NOMBRE = 'Calzado del Sur';

    private const EMAIL_ADMIN = 'dueno@calzadodelsur.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        RateLimiter::clear('solicitud-alta|127.0.0.1');
    }

    private function plan(): PlanSuscripcion
    {
        return PlanSuscripcion::where('activo', true)->orderBy('precio_base_mensual')->firstOrFail();
    }

    /**
     * El formulario lleno y listo para enviar.
     *
     * El reloj se adelanta después de montarlo, porque una prueba lo llena al
     * instante y el formulario pide un tiempo mínimo de llenado. No se puede
     * poner 'iniciado_en' a mano: es #[Locked], justo para que el navegador
     * tampoco pueda.
     */
    private function formulario(array $cambios = [], int $segundosLlenando = 30)
    {
        $componente = Livewire::test('publico.solicitar-alta');

        $this->travel($segundosLlenando)->seconds();

        $valores = array_merge([
            'nombre_comercial' => self::NOMBRE,
            'razon_social' => 'Calzado del Sur SA de CV',
            'rfc' => 'CSU860101AB1',
            'telefono_publico' => '9637778899',
            'email_publico' => 'hola@calzadodelsur.test',
            'direccion_publica' => 'Calle 5 #20, Comitán, Chiapas',
            'plan_id' => (string) $this->plan()->id,
            'admin_nombre' => 'Lucía Ramos',
            'admin_email' => self::EMAIL_ADMIN,
            'admin_password' => 'clave-segura-1',
            'admin_password_confirmation' => 'clave-segura-1',
            'acepta_legales' => true,
        ], $cambios);

        foreach ($valores as $campo => $valor) {
            $componente->set($campo, $valor);
        }

        return $componente;
    }

    private function distribuidoraCreada(): ?Distribuidora
    {
        return Distribuidora::where('nombre_comercial', self::NOMBRE)->first();
    }

    // ------------------------------------------------------------------
    // 1. El formulario pide los datos y los valida
    // ------------------------------------------------------------------

    public function test_el_formulario_abre_sin_sesion_y_muestra_los_planes_de_la_base(): void
    {
        $this->assertGuest();

        $respuesta = $this->get(route('alta.solicitar'))->assertOk();

        foreach (PlanSuscripcion::where('activo', true)->get() as $plan) {
            $respuesta->assertSee($plan->nombre);
            $respuesta->assertSee('$'.number_format((float) $plan->precio_base_mensual, 2));
        }

        $respuesta->assertSee(route('aviso-privacidad'), false)
            ->assertSee(route('terminos'), false);
    }

    public function test_sin_datos_no_crea_nada_y_pide_lo_obligatorio(): void
    {
        $componente = Livewire::test('publico.solicitar-alta');
        $this->travel(30)->seconds();

        $componente->call('enviar')
            ->assertHasErrors(['nombre_comercial', 'plan_id', 'admin_nombre', 'admin_email', 'admin_password', 'acepta_legales']);

        $this->assertNull($this->distribuidoraCreada());
    }

    public function test_un_correo_que_ya_existe_lo_dice_en_espanol(): void
    {
        $this->formulario(['admin_email' => 'admin@calzadosramirez.test'])
            ->call('enviar')
            ->assertHasErrors(['admin_email']);

        $this->assertNull($this->distribuidoraCreada());
    }

    public function test_un_rfc_repetido_lo_dice_en_espanol(): void
    {
        $rfc = Distribuidora::whereNotNull('rfc')->value('rfc');

        if ($rfc === null) {
            Distribuidora::query()->first()->update(['rfc' => 'XAX010101000']);
            $rfc = 'XAX010101000';
        }

        $this->formulario(['rfc' => $rfc])
            ->call('enviar')
            ->assertHasErrors(['rfc']);
    }

    public function test_las_contrasenas_tienen_que_coincidir(): void
    {
        $this->formulario(['admin_password_confirmation' => 'otra-cosa-9'])
            ->call('enviar')
            ->assertHasErrors(['admin_password']);

        $this->assertNull($this->distribuidoraCreada());
    }

    public function test_sin_aceptar_los_legales_no_se_envia(): void
    {
        $this->formulario(['acepta_legales' => false])
            ->call('enviar')
            ->assertHasErrors(['acepta_legales']);

        $this->assertNull($this->distribuidoraCreada());
    }

    // ------------------------------------------------------------------
    // 2. Queda pendiente, con su administrador y su plan
    // ------------------------------------------------------------------

    public function test_la_solicitud_queda_como_distribuidora_pendiente(): void
    {
        $this->formulario()->call('enviar')->assertHasNoErrors()->assertSet('enviada', true);

        $distribuidora = $this->distribuidoraCreada();

        $this->assertNotNull($distribuidora);
        $this->assertSame('pendiente', $distribuidora->estado);
        $this->assertSame($this->plan()->id, (int) $distribuidora->plan_solicitado_id);
        $this->assertNotNull($distribuidora->fecha_solicitud);
        $this->assertNull($distribuidora->fecha_aprobacion);
        $this->assertFalse((bool) $distribuidora->marketplace_visible);

        // Su administrador quedó creado y ligado.
        $admin = Usuario::where('email', self::EMAIL_ADMIN)->firstOrFail();
        $this->assertTrue(
            Tenant::forzar($distribuidora->id, fn () => $distribuidora->staff()->where('usuario_id', $admin->id)->exists())
        );
    }

    public function test_el_slug_sale_del_nombre_y_sirve_como_subdominio(): void
    {
        $this->formulario()->call('enviar')->assertHasNoErrors();

        $distribuidora = $this->distribuidoraCreada();

        $this->assertSame('calzado-del-sur', $distribuidora->slug);
        $this->assertSame(1, preg_match(DominioTienda::FORMATO, $distribuidora->slug));
        $this->assertFalse(DominioTienda::esReservado($distribuidora->slug));
    }

    public function test_si_el_slug_choca_se_le_agrega_un_sufijo(): void
    {
        Distribuidora::query()->first()->update(['slug' => 'calzado-del-sur']);

        $this->formulario()->call('enviar')->assertHasNoErrors();

        $this->assertSame('calzado-del-sur-2', $this->distribuidoraCreada()->slug);
    }

    public function test_guarda_la_aceptacion_de_los_dos_documentos_version_1_0(): void
    {
        $this->formulario()->call('enviar')->assertHasNoErrors();

        $admin = Usuario::where('email', self::EMAIL_ADMIN)->firstOrFail();

        foreach (['aviso_privacidad', 'terminos_condiciones'] as $documento) {
            $this->assertDatabaseHas('aceptaciones_legales', [
                'usuario_id' => $admin->id,
                'tipo_documento' => $documento,
                'version' => '1.0',
            ]);
        }

        $this->assertSame(2, AceptacionLegal::where('usuario_id', $admin->id)->count());
    }

    public function test_al_administrador_le_llega_el_correo_para_confirmar_su_cuenta(): void
    {
        Notification::fake();

        $this->formulario()->call('enviar')->assertHasNoErrors();

        $admin = Usuario::where('email', self::EMAIL_ADMIN)->firstOrFail();

        $this->assertFalse($admin->hasVerifiedEmail());
        Notification::assertSentTo($admin, VerifyEmail::class);
    }

    public function test_la_pantalla_de_confirmacion_dice_que_sigue(): void
    {
        $this->formulario()->call('enviar')
            ->assertSee('Recibimos tu solicitud')
            ->assertSee('Revisa tu correo')
            ->assertSee('Te avisamos por correo');
    }

    // ------------------------------------------------------------------
    // 3. El admin general recibe aviso
    // ------------------------------------------------------------------

    public function test_avisa_por_correo_a_los_admin_general(): void
    {
        Mail::fake();

        $this->formulario()->call('enviar')->assertHasNoErrors();

        Mail::assertSent(SolicitudAltaDistribuidoraMail::class, function ($correo) {
            return $correo->hasTo('admin.general@footwearpoint.test')
                && $correo->nombreDistribuidora === self::NOMBRE
                && $correo->emailAdministrador === self::EMAIL_ADMIN;
        });
    }

    public function test_si_el_correo_falla_la_solicitud_se_guarda_igual(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('sin servidor de correo'));

        $this->formulario()->call('enviar')->assertHasNoErrors()->assertSet('enviada', true);

        $this->assertNotNull($this->distribuidoraCreada());
    }

    // ------------------------------------------------------------------
    // 4. Contra envíos automáticos
    // ------------------------------------------------------------------

    public function test_el_campo_trampa_lleno_no_crea_nada_y_no_dice_por_que(): void
    {
        $this->formulario(['sitio_web' => 'https://spam.example'])
            ->call('enviar')
            ->assertHasNoErrors()
            // Ve exactamente lo mismo que quien sí envió: no se le da pista.
            ->assertSet('enviada', true)
            ->assertSee('Recibimos tu solicitud');

        $this->assertNull($this->distribuidoraCreada());
    }

    public function test_llenarlo_demasiado_rapido_no_crea_nada(): void
    {
        $this->formulario(segundosLlenando: 0)
            ->call('enviar')
            ->assertHasNoErrors()
            ->assertSet('enviada', true);

        $this->assertNull($this->distribuidoraCreada());
    }

    public function test_despues_del_tope_por_ip_pide_esperar_y_no_crea_nada(): void
    {
        $limite = app(LimiteSolicitudesAlta::class);

        for ($i = 0; $i < $limite->tope(); $i++) {
            $limite->registrar('127.0.0.1');
        }

        $this->formulario()
            ->call('enviar')
            ->assertHasNoErrors()
            ->assertSet('enviada', false)
            ->assertSee('Ya recibimos varias solicitudes desde aquí');

        $this->assertNull($this->distribuidoraCreada());
    }

    public function test_el_tope_por_ip_es_configurable(): void
    {
        config(['footwearpoint.solicitudes_alta.por_hora_por_ip' => 9]);

        $this->assertSame(9, app(LimiteSolicitudesAlta::class)->tope());
    }

    /**
     * El primer límite solo cuenta lo que se guardó, así que sin este segundo
     * se podrían probar correos sin tope para ver quién ya está registrado:
     * la validación dice "Ese correo ya tiene una cuenta en FootwearPoint".
     */
    public function test_los_intentos_fallidos_tambien_cuentan_para_el_limite(): void
    {
        config(['footwearpoint.solicitudes_alta.intentos_por_ip' => 3]);

        // Tres intentos que NO guardan nada: el correo ya existe.
        for ($i = 0; $i < 3; $i++) {
            $this->formulario(['admin_email' => 'admin@calzadosramirez.test'])
                ->call('enviar')
                ->assertHasErrors('admin_email');
        }

        $this->assertNull($this->distribuidoraCreada());

        // El cuarto ya no llega ni a validar: mismo mensaje neutro.
        $this->formulario()
            ->call('enviar')
            ->assertHasNoErrors()
            ->assertSet('enviada', false)
            ->assertSee('Ya recibimos varias solicitudes desde aquí');

        $this->assertNull($this->distribuidoraCreada());
    }

    public function test_el_tope_de_intentos_es_configurable(): void
    {
        config(['footwearpoint.solicitudes_alta.intentos_por_ip' => 25]);

        $this->assertSame(25, app(LimiteSolicitudesAlta::class)->topeDeIntentos());
    }

    // ------------------------------------------------------------------
    // 5. Lo que no se queda en el estado del componente
    // ------------------------------------------------------------------

    public function test_la_contrasena_no_se_queda_al_guardar(): void
    {
        $this->formulario()
            ->call('enviar')
            ->assertHasNoErrors()
            ->assertSet('admin_password', '')
            ->assertSet('admin_password_confirmation', '');

        $this->assertNotNull($this->distribuidoraCreada());
    }

    public function test_la_contrasena_tampoco_se_queda_cuando_falla(): void
    {
        $this->formulario(['admin_email' => 'admin@calzadosramirez.test'])
            ->call('enviar')
            ->assertHasErrors('admin_email')
            ->assertSet('admin_password', '')
            ->assertSet('admin_password_confirmation', '');
    }

    public function test_el_navegador_no_puede_cambiar_el_tiempo_de_llenado(): void
    {
        // iniciado_en es #[Locked]: Livewire rechaza el intento. Sin eso, un
        // cliente se saltaría el tiempo mínimo mandándolo hacia atrás.
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::test('publico.solicitar-alta')->set('iniciado_en', now()->timestamp - 9999);
    }

    public function test_el_navegador_no_puede_ponerse_la_pantalla_de_confirmacion(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::test('publico.solicitar-alta')->set('enviada', true);
    }
}
