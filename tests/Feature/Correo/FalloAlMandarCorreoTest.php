<?php

namespace Tests\Feature\Correo;

use App\Models\ClienteDirecto;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
use App\Services\Correo\IntentarEnviarCorreoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Cuando el proveedor de correo falla, el panel no se cae.
 *
 * Pasó de verdad en producción: al afiliar un cliente mayorista con acceso a
 * la app, la pantalla mostró «Algo salió mal» (error 500)… y el cliente SÍ se
 * había guardado. Quien lo afilió no tenía forma de saberlo. Lo mismo con
 * «Enviar enlace» y «Reenviar confirmación».
 *
 * El correo sale del servidor hacia afuera y puede fallar por cosas que no
 * tienen que ver con lo que la persona acaba de hacer: el proveedor caído, el
 * dominio sin verificar, la cuota agotada. Eso tiene que verse como un aviso,
 * no como una pantalla de error.
 */
class FalloAlMandarCorreoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** Un proveedor de correo que siempre rechaza, como el de producción ese día. */
    private function elCorreoFalla(): void
    {
        Mail::extend('explota', fn (array $config) => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('El proveedor rechazo el envio.');
            }

            public function __toString(): string
            {
                return 'explota://';
            }
        });

        config([
            'mail.default' => 'explota',
            'mail.mailers.explota' => ['transport' => 'explota'],
        ]);

        Mail::purge('explota');
    }

    private function entrarComoAdmin(): Usuario
    {
        $admin = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    private function afiliacionDeMaria(): RevendedorDistribuidora
    {
        $cuenta = Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();

        return RevendedorDistribuidora::withoutGlobalScopes()
            ->whereHas('revendedor', fn ($q) => $q->where('usuario_id', $cuenta->id))
            ->firstOrFail();
    }

    private function clienteDeJose(): ClienteDirecto
    {
        $cuenta = Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail();

        return ClienteDirecto::withoutGlobalScopes()->where('usuario_id', $cuenta->id)->firstOrFail();
    }

    public function test_enviar_enlace_avisa_en_vez_de_tirar_la_pantalla(): void
    {
        $this->entrarComoAdmin();
        $this->elCorreoFalla();

        Livewire::test('distribuidora.usuarios')
            ->call('enviarEnlaceRevendedor', $this->afiliacionDeMaria()->id)
            ->assertSet('avisoEnlaceEsError', true)
            ->assertSet('avisoEnlace', IntentarEnviarCorreoAction::AVISO);
    }

    public function test_enviar_enlace_a_un_cliente_directo_tampoco_tira_la_pantalla(): void
    {
        $this->entrarComoAdmin();
        $this->elCorreoFalla();

        Livewire::test('distribuidora.clientes')
            ->call('enviarEnlaceCliente', $this->clienteDeJose()->id)
            ->assertSet('avisoEnlaceEsError', true)
            ->assertSet('avisoEnlace', IntentarEnviarCorreoAction::AVISO);
    }

    public function test_reenviar_la_confirmacion_avisa_en_vez_de_tirar_la_pantalla(): void
    {
        $this->entrarComoAdmin();

        $cuenta = Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail();
        $cuenta->forceFill(['email_verified_at' => null])->save();

        $this->elCorreoFalla();

        Livewire::test('distribuidora.usuarios')
            ->call('reenviarVerificacionRevendedor', $this->afiliacionDeMaria()->id)
            ->assertSet('avisoEnlaceEsError', true)
            ->assertSet('avisoEnlace', IntentarEnviarCorreoAction::AVISO);
    }

    public function test_reenviar_la_confirmacion_a_un_cliente_directo_tampoco_tira_la_pantalla(): void
    {
        $this->entrarComoAdmin();

        $cuenta = Usuario::where('email', 'jose.hernandez@cliente.test')->firstOrFail();
        $cuenta->forceFill(['email_verified_at' => null])->save();

        $this->elCorreoFalla();

        Livewire::test('distribuidora.clientes')
            ->call('reenviarVerificacionCliente', $this->clienteDeJose()->id)
            ->assertSet('avisoEnlaceEsError', true)
            ->assertSet('avisoEnlace', IntentarEnviarCorreoAction::AVISO);
    }

    public function test_cuando_el_correo_si_sale_el_aviso_sigue_siendo_el_bueno(): void
    {
        $this->entrarComoAdmin();

        Livewire::test('distribuidora.usuarios')
            ->call('enviarEnlaceRevendedor', $this->afiliacionDeMaria()->id)
            ->assertSet('avisoEnlaceEsError', false);
    }
}
