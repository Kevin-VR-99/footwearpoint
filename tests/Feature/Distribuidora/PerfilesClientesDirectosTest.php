<?php

namespace Tests\Feature\Distribuidora;

use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Clientes directos con el mismo formato que Empleados y Clientes mayoristas:
 * modal, foto de perfil, «Eliminar definitivamente» y lista a todo el ancho
 * con acciones separadas.
 */
class PerfilesClientesDirectosTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Mail::fake();
        Storage::fake('s3');

        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
    }

    private function distribuidoraId(): int
    {
        return (int) DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', auth()->id())->value('distribuidora_id');
    }

    private function crearCliente(string $nombre, array $extra = []): ClienteDirecto
    {
        $pantalla = Livewire::test('distribuidora.clientes')
            ->call('abrirFormularioCrearCliente')
            ->set('cliente_nombre', $nombre);

        foreach ($extra as $campo => $valor) {
            $pantalla->set($campo, $valor);
        }

        $pantalla->call('guardarCliente')->assertHasNoErrors();

        return ClienteDirecto::withoutGlobalScopes()->where('nombre', $nombre)->latest('id')->firstOrFail();
    }

    public function test_el_formulario_es_un_modal_con_foto(): void
    {
        Livewire::test('distribuidora.clientes')
            ->call('abrirFormularioCrearCliente')
            ->assertSeeHtml('role="dialog"')
            ->assertSeeHtml('data-modal="cliente"')
            ->assertSeeHtml('data-foto="cliente"')
            ->assertSee('Subir foto');
    }

    public function test_alta_con_foto_y_avatar_en_la_lista(): void
    {
        $cliente = $this->crearCliente('Rosa Foto', ['cliente_foto' => UploadedFile::fake()->image('rosa.jpg', 200, 200)]);

        $this->assertNotNull($cliente->foto_url);
        $this->assertCount(1, Storage::disk('s3')->allFiles('perfiles/clientes-directos'));

        Livewire::test('distribuidora.clientes')->assertSeeHtml('alt="Foto de Rosa Foto"');
    }

    public function test_editar_cambia_la_foto_y_borra_la_anterior(): void
    {
        $cliente = $this->crearCliente('Cambio Foto', ['cliente_foto' => UploadedFile::fake()->image('a.jpg')]);

        Livewire::test('distribuidora.clientes')
            ->call('abrirFormularioEditarCliente', $cliente->id)
            ->assertSet('cliente_foto_actual', $cliente->foto_url)
            ->set('cliente_foto', UploadedFile::fake()->image('b.png'))
            ->call('guardarCliente')
            ->assertHasNoErrors();

        $this->assertNotSame($cliente->foto_url, $cliente->fresh()->foto_url);
        $this->assertCount(1, Storage::disk('s3')->allFiles('perfiles/clientes-directos'));
    }

    public function test_la_foto_debe_ser_imagen_y_pesar_poco(): void
    {
        Livewire::test('distribuidora.clientes')
            ->call('abrirFormularioCrearCliente')
            ->set('cliente_nombre', 'Sin Foto Valida')
            ->set('cliente_foto', UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf'))
            ->call('guardarCliente')
            ->assertHasErrors(['cliente_foto']);

        Livewire::test('distribuidora.clientes')
            ->call('abrirFormularioCrearCliente')
            ->set('cliente_nombre', 'Sin Foto Valida')
            ->set('cliente_foto', UploadedFile::fake()->image('grande.jpg')->size(3000))
            ->call('guardarCliente')
            ->assertHasErrors(['cliente_foto']);
    }

    public function test_sin_foto_se_ven_las_iniciales(): void
    {
        $this->crearCliente('Xochitl Yanez');

        Livewire::test('distribuidora.clientes')->assertSeeHtml('<span aria-hidden="true">XY</span>');
    }

    public function test_eliminar_un_cliente_sin_movimientos(): void
    {
        $cliente = $this->crearCliente('Cliente Borrable', [
            'cliente_email' => 'cliente.borrable@gmail.com',
            'cliente_foto' => UploadedFile::fake()->image('c.jpg'),
        ]);

        Livewire::test('distribuidora.clientes')
            ->call('pedirEliminar', 'cliente', $cliente->id)
            ->assertSeeHtml('data-modal="eliminar"')
            ->assertSee('Cliente Borrable')
            ->call('eliminarDefinitivamente')
            ->assertSet('porEliminar', null)
            ->assertDispatched('guardado');

        $this->assertNull(ClienteDirecto::withoutGlobalScopes()->find($cliente->id));
        $this->assertSame([], Storage::disk('s3')->allFiles('perfiles/clientes-directos'));
        $this->assertTrue(Auditoria::where('accion', 'cliente_directo.eliminado_definitivamente')
            ->where('entidad_id', $cliente->id)->exists());
    }

    public function test_no_elimina_a_un_cliente_con_pedidos(): void
    {
        $cliente = $this->crearCliente('Cliente Con Pedido');
        $distribuidoraId = $this->distribuidoraId();

        Tenant::forzar($distribuidoraId, fn () => \App\Models\Pedido::create([
            'distribuidora_id' => $distribuidoraId,
            'sucursal_id' => \App\Models\Sucursal::withoutGlobalScopes()->where('distribuidora_id', $distribuidoraId)->value('id'),
            'tipo' => 'cliente_directo',
            'cliente_directo_id' => $cliente->id,
            'capturado_por_staff_id' => (int) DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', auth()->id())->value('id'),
            'folio' => 'PRUEBA-'.uniqid(),
            'estado' => 'colocado', 'subtotal' => 10, 'total' => 10, 'fecha_colocacion' => now(),
        ]));

        Livewire::test('distribuidora.clientes')
            ->call('pedirEliminar', 'cliente', $cliente->id)
            ->call('eliminarDefinitivamente')
            ->assertNotSet('errorEliminar', null)
            ->assertSee('Dale de baja')
            ->assertNotSet('porEliminar', null);

        $this->assertNotNull(ClienteDirecto::withoutGlobalScopes()->find($cliente->id));
        $this->assertFalse(Auditoria::where('accion', 'cliente_directo.eliminado_definitivamente')->exists());
    }

    public function test_cancelar_no_borra_nada(): void
    {
        $cliente = $this->crearCliente('No Me Borres Directo');

        Livewire::test('distribuidora.clientes')
            ->call('pedirEliminar', 'cliente', $cliente->id)
            ->call('cancelarEliminar')
            ->assertSet('porEliminar', null);

        $this->assertNotNull($cliente->fresh());
    }

    public function test_pedir_eliminar_otro_tipo_da_404(): void
    {
        Livewire::test('distribuidora.clientes')->call('pedirEliminar', 'empleado', 1)->assertStatus(404);
    }

    /** Las tres pantallas comparten el mismo formato: ancho completo, tabla responsiva y acciones con aria-label. */
    public function test_las_tres_listas_usan_el_mismo_formato(): void
    {
        $this->crearCliente('Formato Igual');

        foreach ([
            ['distribuidora.usuarios', ['seccion' => 'empleados']],
            ['distribuidora.usuarios', ['seccion' => 'mayoristas']],
            ['distribuidora.clientes', []],
        ] as [$componente, $parametros]) {
            Livewire::test($componente, $parametros)
                ->assertSeeHtml('max-w-7xl')
                ->assertDontSeeHtml('max-w-3xl fp-aparecer')
                ->assertSeeHtml('class="fp-tabla-personas"')
                ->assertSeeHtml('fp-personas-contenedor')
                ->assertSeeHtml('fp-accion-persona')
                ->assertSeeHtml('aria-label="Editar: ');
        }

        Livewire::test('distribuidora.clientes')
            ->assertSeeHtml('aria-haspopup="menu"')
            ->assertSeeHtml('aria-label="Más acciones para Formato Igual"')
            ->assertSeeHtml('fp-accion-persona-peligro')
            ->assertSeeHtml('data-filtros-clientes')
            ->assertSeeHtml('data-exportar="clientes-directos"');
    }
}
