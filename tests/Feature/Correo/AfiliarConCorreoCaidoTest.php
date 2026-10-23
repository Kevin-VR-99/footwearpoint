<?php

namespace Tests\Feature\Correo;

use App\Models\Revendedor;
use App\Models\RevendedorDistribuidora;
use App\Models\Usuario;
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
 * Afiliar a alguien con acceso a la app cuando el correo está caído.
 *
 * Este es el caso que se vio en producción y el más feo de los tres: la
 * cuenta se crea, se guarda, y el correo de confirmación se manda DESPUÉS
 * (con DB::afterCommit, para no mandarlo si el guardado falla). Si ese envío
 * reventaba, la pantalla mostraba «Algo salió mal» por un cliente que sí
 * había quedado registrado.
 *
 * Nota: los callbacks de DB::afterCommit SÍ corren con RefreshDatabase.
 * Laravel instala su propio gestor de transacciones en las pruebas, que los
 * ejecuta cuando la transacción de la aplicación hace commit dentro de la
 * del test. Estas pruebas usaban DatabaseTruncation por una idea equivocada
 * mía; se cambiaron porque esa limpia al EMPEZAR y no al terminar, así que
 * lo que creaban se quedaba y hacía fallar pruebas de más adelante.
 */
class AfiliarConCorreoCaidoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

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

    public function test_el_cliente_mayorista_queda_afiliado_aunque_el_correo_no_salga(): void
    {
        $admin = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        $this->actingAs($admin);

        $correo = 'mayorista.correo.caido@example.test';

        Livewire::test('distribuidora.usuarios')
            ->call('abrirFormularioAfiliarRevendedor')
            ->set('revendedor_nombre', 'Zapatería del Centro')
            ->set('revendedor_telefono', '9611234567')
            ->set('revendedor_acceso_email', $correo)
            ->call('guardarRevendedor')
            ->assertHasNoErrors();

        // Lo importante: la cuenta existe y quedó ligada, aunque el correo
        // de confirmación no haya salido.
        $cuenta = Usuario::withoutGlobalScopes()->where('email', $correo)->first();
        $this->assertNotNull($cuenta, 'La cuenta de acceso no se creó.');
        $this->assertNull($cuenta->email_verified_at, 'No debería quedar confirmada sola.');

        $revendedor = Revendedor::withoutGlobalScopes()->where('usuario_id', $cuenta->id)->first();
        $this->assertNotNull($revendedor, 'La cuenta no quedó ligada a su cliente mayorista.');
        $this->assertSame('Zapatería del Centro', $revendedor->nombre);

        $this->assertTrue(
            RevendedorDistribuidora::withoutGlobalScopes()
                ->where('revendedor_id', $revendedor->id)
                ->exists(),
            'El cliente mayorista no quedó afiliado a la distribuidora.',
        );
    }

    public function test_desde_el_panel_se_puede_reintentar_el_correo_despues(): void
    {
        $admin = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        $this->actingAs($admin);

        $correo = 'mayorista.reintento@example.test';

        Livewire::test('distribuidora.usuarios')
            ->call('abrirFormularioAfiliarRevendedor')
            ->set('revendedor_nombre', 'Calzado La Esperanza')
            ->set('revendedor_acceso_email', $correo)
            ->call('guardarRevendedor')
            ->assertHasNoErrors();

        $cuenta = Usuario::withoutGlobalScopes()->where('email', $correo)->firstOrFail();
        $revendedor = Revendedor::withoutGlobalScopes()->where('usuario_id', $cuenta->id)->firstOrFail();
        $afiliacion = RevendedorDistribuidora::withoutGlobalScopes()
            ->where('revendedor_id', $revendedor->id)
            ->firstOrFail();

        // Con el correo todavía caído, el botón avisa en vez de tumbar todo.
        Livewire::test('distribuidora.usuarios')
            ->call('reenviarVerificacionRevendedor', $afiliacion->id)
            ->assertSet('avisoEnlaceEsError', true);
    }
}
