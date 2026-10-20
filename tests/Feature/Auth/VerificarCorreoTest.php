<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\CorreoVerificado;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * TG-189 (A11 / E1-03) — Confirmar que el correo de la cuenta es suyo.
 *
 * La cuenta queda "sin confirmar" hasta que la persona abre el enlace, y
 * enviar un pedido pide tenerla confirmada.
 */
class VerificarCorreoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        // TG-278: el seeder deja las dos cuentas de la demostracion con el
        // correo ya confirmado, porque sus correos son inventados y nadie
        // podria abrir el enlace. Aqui Maria hace de "cuenta sin confirmar",
        // asi que se le quita a proposito, igual que al empleado mas abajo.
        Usuario::where('email', 'maria.lopez@revendedor.test')
            ->firstOrFail()
            ->forceFill(['email_verified_at' => null])
            ->save();
    }

    private function maria(): Usuario
    {
        return Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();
    }

    private function enlaceDeVerificacion(Usuario $usuario): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id'   => $usuario->id,
            'hash' => sha1($usuario->getEmailForVerification()),
        ]);
    }

    /** Los correos que de verdad se armaron (phpunit usa el transporte "array"). */
    private function correoEnviado(): Email
    {
        $mensajes = \Illuminate\Support\Facades\Mail::getSymfonyTransport()->messages();

        $this->assertCount(1, $mensajes, 'No salió el correo de confirmación.');

        return $mensajes->first()->getOriginalMessage();
    }

    // ------------------------------------------------------------------
    // La cuenta nace sin confirmar
    // ------------------------------------------------------------------

    public function test_una_cuenta_nueva_nace_sin_confirmar_y_recibe_su_correo(): void
    {
        Notification::fake();

        $afiliacion = RevendedorDistribuidora::withoutGlobalScopes()
            ->whereHas('revendedor', fn ($q) => $q->whereNull('usuario_id'))
            ->firstOrFail();

        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());

        $cuenta = app(\App\Services\Distribuidora\ActivarCuentaAccesoAction::class)
            ->paraRevendedor($afiliacion, 'nuevo.mayorista@prueba.test', 'claveDePrueba1');

        $this->assertFalse($cuenta->hasVerifiedEmail(), 'La cuenta nació ya confirmada.');

        Notification::assertSentTo($cuenta, VerifyEmail::class);
    }

    public function test_el_correo_llega_en_espanol_y_con_el_enlace(): void
    {
        $this->maria()->sendEmailVerificationNotification();

        $correo = $this->correoEnviado();
        $cuerpo = (string) $correo->getHtmlBody();

        $this->assertSame('Confirma tu correo de FootwearPoint', $correo->getSubject());
        $this->assertStringContainsString('Confirmar mi correo', $cuerpo);
        $this->assertStringContainsString('/verificar-correo/', $cuerpo);

        // Nada de la plantilla de fábrica de Laravel.
        $this->assertStringNotContainsString('Verify Email Address', $cuerpo);
        $this->assertStringNotContainsString('Laravel', $cuerpo);
    }

    public function test_al_cliente_le_habla_de_su_cuenta_para_pedir_desde_la_app(): void
    {
        $this->maria()->sendEmailVerificationNotification();

        $cuerpo = (string) $this->correoEnviado()->getHtmlBody();

        $this->assertStringContainsString('Tu distribuidora te dio una cuenta en FootwearPoint', $cuerpo);
        $this->assertStringContainsString('hacer pedidos desde la app', $cuerpo);
        $this->assertStringNotContainsString('solicitud de alta', $cuerpo);
    }

    // ------------------------------------------------------------------
    // El enlace
    // ------------------------------------------------------------------

    public function test_el_enlace_confirma_el_correo_sin_pedir_iniciar_sesion(): void
    {
        // Quien lo recibe usa la app, no el panel: pedirle entrar aquí haría
        // el enlace inservible.
        $maria = $this->maria();

        $this->get($this->enlaceDeVerificacion($maria))
            ->assertOk()
            ->assertSee('Correo confirmado');

        $this->assertTrue($maria->fresh()->hasVerifiedEmail());
    }

    public function test_abrirlo_dos_veces_no_truena(): void
    {
        $maria = $this->maria();
        $enlace = $this->enlaceDeVerificacion($maria);

        $this->get($enlace)->assertOk();

        $this->get($enlace)->assertOk()->assertSee('Ya estaba confirmado');
    }

    public function test_un_enlace_sin_firma_no_sirve(): void
    {
        $maria = $this->maria();

        $this->get('/verificar-correo/'.$maria->id.'/'.sha1($maria->email))
            ->assertForbidden();

        $this->assertFalse($maria->fresh()->hasVerifiedEmail());
    }

    public function test_un_enlace_con_el_hash_cambiado_no_confirma_nada(): void
    {
        $maria = $this->maria();

        // Firmado, pero apuntando a otro correo: no debe confirmar.
        $enlace = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id'   => $maria->id,
            'hash' => sha1('otro@correo.test'),
        ]);

        $this->get($enlace)->assertOk()->assertSee('no sirve');

        $this->assertFalse($maria->fresh()->hasVerifiedEmail());
    }

    public function test_un_enlace_vencido_no_sirve(): void
    {
        $maria = $this->maria();
        $enlace = $this->enlaceDeVerificacion($maria);

        $this->travel(61)->minutes();

        $this->get($enlace)->assertForbidden();

        $this->assertFalse($maria->fresh()->hasVerifiedEmail());
    }

    // ------------------------------------------------------------------
    // La API
    // ------------------------------------------------------------------

    public function test_la_sesion_de_la_app_dice_si_el_correo_esta_confirmado(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'maria.lopez@revendedor.test',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.email_verificado', false);

        $this->maria()->markEmailAsVerified();

        $this->actingAs($this->maria()->fresh(), 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email_verificado', true);
    }

    public function test_la_app_puede_pedir_que_le_reenvien_el_enlace(): void
    {
        Notification::fake();

        $this->actingAs($this->maria(), 'sanctum')
            ->postJson('/api/auth/reenviar-verificacion')
            ->assertOk();

        Notification::assertSentTo($this->maria(), VerifyEmail::class);
    }

    public function test_si_ya_esta_confirmado_responde_igual_pero_no_manda_nada(): void
    {
        // La respuesta no delata el estado de la cuenta.
        Notification::fake();

        $maria = $this->maria();
        $maria->markEmailAsVerified();

        $this->actingAs($maria->fresh(), 'sanctum')
            ->postJson('/api/auth/reenviar-verificacion')
            ->assertOk();

        Notification::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Lo que se bloquea
    // ------------------------------------------------------------------

    public function test_sin_confirmar_no_puede_enviar_un_pedido(): void
    {
        config(['auth.verification.obligatoria' => true]);

        $this->actingAs($this->maria(), 'sanctum')
            ->postJson('/api/pedidos/1/enviar')
            ->assertStatus(403)
            ->assertJsonPath('message', CorreoVerificado::MENSAJE);
    }

    public function test_al_confirmar_ya_puede_enviar(): void
    {
        config(['auth.verification.obligatoria' => true]);

        $maria = $this->maria();
        $maria->markEmailAsVerified();

        // Ya no lo frena el correo. Que el pedido exista o no es otra cosa:
        // lo importante es que la respuesta no sea el 403 de verificación.
        $respuesta = $this->actingAs($maria->fresh(), 'sanctum')
            ->postJson('/api/pedidos/1/enviar');

        $this->assertNotSame(CorreoVerificado::MENSAJE, $respuesta->json('message'));
    }

    public function test_el_personal_de_mostrador_nunca_se_bloquea(): void
    {
        // Al personal lo dio de alta la distribuidora en persona; frenarlo por
        // un correo seria frenar el negocio.
        config(['auth.verification.obligatoria' => true]);

        $empleado = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();

        // El seeder le pone el correo confirmado, asi que para probar la
        // regla del ROL hay que quitarselo a proposito.
        $empleado->forceFill(['email_verified_at' => null])->save();
        $this->assertFalse($empleado->fresh()->hasVerifiedEmail());

        $respuesta = $this->actingAs($empleado->fresh(), 'sanctum')
            ->postJson('/api/pedidos/1/enviar');

        $this->assertNotSame(CorreoVerificado::MENSAJE, $respuesta->json('message'));
    }

    public function test_apagado_no_bloquea_a_nadie(): void
    {
        // Es como viene por omision, porque las cuentas que ya existen tienen
        // el correo sin confirmar.
        config(['auth.verification.obligatoria' => false]);

        $respuesta = $this->actingAs($this->maria(), 'sanctum')
            ->postJson('/api/pedidos/1/enviar');

        $this->assertNotSame(CorreoVerificado::MENSAJE, $respuesta->json('message'));
    }

    public function test_armar_el_borrador_no_pide_el_correo_confirmado(): void
    {
        // Solo enviar. Si no, la persona se topa con un muro antes de
        // entender que le estan pidiendo.
        config(['auth.verification.obligatoria' => true]);

        $respuesta = $this->actingAs($this->maria(), 'sanctum')
            ->postJson('/api/pedidos', []);

        $this->assertNotSame(CorreoVerificado::MENSAJE, $respuesta->json('message'));
    }

    // ------------------------------------------------------------------
    // El panel
    // ------------------------------------------------------------------

    public function test_el_personal_puede_reenviar_la_confirmacion_desde_el_panel(): void
    {
        Notification::fake();

        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());

        $afiliacion = RevendedorDistribuidora::withoutGlobalScopes()
            ->whereHas('revendedor', fn ($q) => $q->where('usuario_id', $this->maria()->id))
            ->firstOrFail();

        Livewire::test('distribuidora.usuarios')
            ->call('reenviarVerificacionRevendedor', $afiliacion->id)
            ->assertSet('avisoEnlaceEsError', false);

        Notification::assertSentTo($this->maria(), VerifyEmail::class);
    }

    public function test_si_ya_estaba_confirmado_el_panel_lo_dice_y_no_manda_nada(): void
    {
        Notification::fake();

        $this->maria()->markEmailAsVerified();

        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());

        $afiliacion = RevendedorDistribuidora::withoutGlobalScopes()
            ->whereHas('revendedor', fn ($q) => $q->where('usuario_id', $this->maria()->id))
            ->firstOrFail();

        Livewire::test('distribuidora.usuarios')
            ->call('reenviarVerificacionRevendedor', $afiliacion->id)
            ->assertSet('avisoEnlaceEsError', false);

        Notification::assertNothingSent();
    }

    public function test_sin_confirmar_tampoco_puede_iniciar_un_pago(): void
    {
        // La tarjeta pide "pagos y pedidos".
        config(['auth.verification.obligatoria' => true]);

        $jose = Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail();

        // TG-278: el seeder tambien lo deja confirmado; aqui hace de cuenta
        // sin confirmar, asi que se le quita a proposito.
        $jose->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($jose->fresh(), 'sanctum')
            ->postJson('/api/pedidos/1/anticipo/mercado-pago')
            ->assertStatus(403)
            ->assertJsonPath('message', CorreoVerificado::MENSAJE);
    }

    public function test_pero_si_puede_confirmar_un_pago_que_ya_hizo(): void
    {
        // Esta ruta solo le pregunta a Mercado Pago si el dinero ya entro.
        // Bloquearla podria perder la confirmacion de un pago ya hecho.
        config(['auth.verification.obligatoria' => true]);

        $jose = Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail();

        $respuesta = $this->actingAs($jose, 'sanctum')
            ->postJson('/api/pedidos/1/anticipo/mercado-pago/verificar');

        $this->assertNotSame(CorreoVerificado::MENSAJE, $respuesta->json('message'));
    }
}
