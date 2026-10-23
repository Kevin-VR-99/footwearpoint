<?php

namespace Tests\Feature\Distribuidora;

use App\Models\ClienteDirecto;
use App\Models\Revendedor;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\DemoContactosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Al confirmar el correo, la cuenta lo guarda en usuarios.email y el de
 * contacto queda vacío. La tabla tiene que mostrar ese correo y decir
 * que ya está confirmado.
 */
class CorreoVisibleEnTablasTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
    }

    public function test_mayoristas_muestran_el_correo_de_la_cuenta_ya_confirmado(): void
    {
        $maria = Revendedor::whereHas('usuario', fn ($q) => $q->where('email', DemoContactosSeeder::REVENDEDOR))->firstOrFail();
        $this->assertNull($maria->email);
        $this->assertTrue($maria->usuario->hasVerifiedEmail());

        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->assertSee(DemoContactosSeeder::REVENDEDOR)
            ->assertSee('roberto.garcia@revendedor.test')
            ->assertSee('Correo confirmado')
            ->assertDontSee('Correo sin confirmar')
            ->assertDontSee('Reenviar confirmación')
            ->set('buscar', DemoContactosSeeder::REVENDEDOR)
            ->assertSee('María López')
            ->assertDontSee('Roberto García');
    }

    public function test_clientes_directos_muestran_el_correo_de_la_cuenta_ya_confirmado(): void
    {
        $jose = ClienteDirecto::whereHas('usuario', fn ($q) => $q->where('email', DemoContactosSeeder::CLIENTE_DIRECTO))->firstOrFail();
        $this->assertNull($jose->email);
        $this->assertTrue($jose->usuario->hasVerifiedEmail());

        Livewire::test('distribuidora.clientes')
            ->assertSee(DemoContactosSeeder::CLIENTE_DIRECTO)
            ->assertSee('ana.garcia@cliente.test')
            ->assertSee('Correo confirmado')
            ->set('buscar', DemoContactosSeeder::CLIENTE_DIRECTO)
            ->assertSee('José Hernández')
            ->assertDontSee('Ana García');

        Livewire::test('clientes.historial')
            ->set('buscar', DemoContactosSeeder::CLIENTE_DIRECTO)
            ->assertSee('José Hernández')
            ->assertDontSee('Ana García');
    }

    public function test_empleados_muestran_su_correo_y_si_ya_lo_confirmaron(): void
    {
        Livewire::test('distribuidora.usuarios')
            ->assertSee('empleado@calzadosramirez.test')
            ->assertSee('Correo confirmado')
            ->assertDontSee('Correo sin confirmar');
    }

    public function test_un_mayorista_nuevo_muestra_el_correo_antes_y_despues_de_confirmarlo(): void
    {
        $correo = 'nuevo.mayorista@correo.test';

        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->call('abrirFormularioAfiliarRevendedor')
            ->set('revendedor_nombre', 'Nuevo Mayorista')
            ->set('revendedor_acceso_email', $correo)
            ->call('guardarRevendedor')
            ->assertHasNoErrors()
            ->assertSee($correo)
            ->assertSee('Correo sin confirmar')
            ->assertSee('Reenviar confirmación');

        $cuenta = Usuario::where('email', $correo)->firstOrFail();
        $this->assertFalse($cuenta->hasVerifiedEmail());
        $this->assertNull(
            Revendedor::where('usuario_id', $cuenta->id)->firstOrFail()->email
        );

        $cuenta->markEmailAsVerified();

        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->assertSee($correo)
            ->assertSee('Correo confirmado')
            ->assertDontSee('Reenviar confirmación');
    }
}
