<?php

namespace Tests\Feature\Distribuidora;

use App\Mail\CambioEstadoDistribuidoraMail;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Usuario;
use App\Services\Distribuidora\NotificarCambioEstadoDistribuidoraAction as Notificar;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-224 (G3) — En pantalla se dice "cliente mayorista" (el valor interno
 * sigue siendo 'revendedor'), y el alta de personal usa Tenant::forzar en
 * lugar de quitar el scope de tenant sin que cambie el resultado.
 */
class TextosClienteMayoristaYPersonalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Distribuidora $distribuidora;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $this->distribuidora = Distribuidora::where('slug', DemoDistribuidoraSeeder::SLUG)->firstOrFail();
        $this->actingAs(Usuario::where('email', DemoDistribuidoraSeeder::ADMIN)->firstOrFail());
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->distribuidora->id);
    }

    public function test_usuarios_dice_clientes_mayoristas(): void
    {
        Livewire::test('distribuidora.usuarios')
            ->assertSee('Clientes mayoristas')
            ->assertSee('+ Afiliar cliente mayorista')
            ->assertDontSee('Afiliar revendedor');
    }

    public function test_pedidos_y_vales_dicen_cliente_mayorista_y_conservan_el_valor(): void
    {
        Livewire::test('pedidos.index')
            ->assertSeeHtml('<option value="revendedor">Cliente mayorista</option>');

        Livewire::test('vales.index')
            ->assertSeeHtml('<option value="revendedor">Solo clientes mayoristas</option>');
    }

    public function test_el_correo_de_suspension_dice_clientes_mayoristas(): void
    {
        $suspendida = (new CambioEstadoDistribuidoraMail('Calzados Ramírez', Notificar::SUSPENDIDA))->render();
        $reactivada = (new CambioEstadoDistribuidoraMail('Calzados Ramírez', Notificar::REACTIVADA))->render();

        $this->assertStringContainsString('clientes (mayoristas y directos)', $suspendida);
        $this->assertStringContainsString('clientes (mayoristas y directos)', $reactivada);
        $this->assertStringNotContainsString('revendedores', $suspendida.$reactivada);
    }

    public function test_el_mensaje_de_tipo_de_pedido_dice_cliente_mayorista(): void
    {
        Sanctum::actingAs(Usuario::where('email', DemoDistribuidoraSeeder::ADMIN)->firstOrFail());

        $this->postJson('/api/pedidos', ['tipo' => 'otro', 'propietario_id' => 1, 'sucursal_id' => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.tipo.0', 'El tipo debe ser cliente directo (cliente_directo) o cliente mayorista (revendedor).');
    }

    public function test_registrar_empleado_desde_la_pantalla_lo_deja_en_la_distribuidora_del_admin(): void
    {
        Livewire::test('auth.register-empleado')
            ->set('nombre', 'Luisa Pérez')
            ->set('email', 'luisa.perez@calzadosramirez.test')
            ->set('password', 'clave-segura-1')
            ->set('password_confirmation', 'clave-segura-1')
            ->call('registrar')
            ->assertHasNoErrors()
            ->assertSet('mensaje', 'Empleado registrado correctamente.');

        $usuario = Usuario::where('email', 'luisa.perez@calzadosramirez.test')->firstOrFail();
        $staff = DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $usuario->id)->sole();

        $this->assertSame((int) $this->distribuidora->id, (int) $staff->distribuidora_id);
        $this->assertSame('empleado', $staff->tipo);
        $this->assertSame('activo', $staff->estado);
        $this->assertTrue($usuario->hasRole('empleado'));
    }

    public function test_registrar_empleado_por_api_lo_deja_en_la_distribuidora_del_admin(): void
    {
        Sanctum::actingAs(Usuario::where('email', DemoDistribuidoraSeeder::ADMIN)->firstOrFail());

        $this->postJson('/api/auth/register-empleado', [
            'nombre'                => 'Pedro Ruiz',
            'email'                 => 'pedro.ruiz@calzadosramirez.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.distribuidora_id', (int) $this->distribuidora->id)
            ->assertJsonPath('data.rol', 'empleado');

        $usuario = Usuario::where('email', 'pedro.ruiz@calzadosramirez.test')->firstOrFail();
        $this->assertSame(
            (int) $this->distribuidora->id,
            (int) DistribuidoraStaff::withoutGlobalScopes()->where('usuario_id', $usuario->id)->value('distribuidora_id')
        );
    }
}
