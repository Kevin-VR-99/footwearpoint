<?php

namespace Tests\Feature\Sprint5;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\HistorialEstadoPedido;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class ConfirmarEntregaModalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function pedido(string $estado = 'listo_entrega', float $total = 0): Pedido
    {
        return Pedido::create([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'MODAL-'.uniqid(),
            'tipo' => 'cliente_directo',
            'cliente_directo_id' => ClienteDirecto::query()->value('id'),
            'estado' => $estado,
            'subtotal' => $total,
            'total' => $total,
            'fecha_colocacion' => now(),
            'capturado_por_staff_id' => DistribuidoraStaff::query()->value('id'),
        ]);
    }

    public function test_abrir_muestra_modal_y_folio_sin_entregar(): void
    {
        $pedido = $this->pedido();
        Livewire::test('pedidos.show', ['id' => $pedido->id])
            ->assertSet('confirmandoEntrega', false)
            ->call('abrirConfirmacionEntrega')
            ->assertSet('confirmandoEntrega', true)
            ->assertSee('Confirmar entrega')
            ->assertSee($pedido->folio)
            ->assertSee('role="dialog"', false)
            ->assertSee('Cancelar');

        $this->assertSame('listo_entrega', $pedido->fresh()->estado);
        $this->assertNull($pedido->fresh()->fecha_entrega);
        $this->assertSame(0, HistorialEstadoPedido::where('pedido_id', $pedido->id)->count());
    }

    public function test_cancelar_cierra_y_restaura_foco_sin_cambiar_estado(): void
    {
        $pedido = $this->pedido();
        Livewire::test('pedidos.show', ['id' => $pedido->id])
            ->call('abrirConfirmacionEntrega')
            ->call('cancelarConfirmacionEntrega')
            ->assertSet('confirmandoEntrega', false)
            ->assertDispatched('confirmacion-entrega-cerrada');

        $this->assertSame('listo_entrega', $pedido->fresh()->estado);
        $this->assertNull($pedido->fresh()->fecha_entrega);
        $this->assertSame(0, HistorialEstadoPedido::where('pedido_id', $pedido->id)->count());
    }

    public function test_confirmar_entrega_una_sola_vez(): void
    {
        $pedido = $this->pedido();
        $pantalla = Livewire::test('pedidos.show', ['id' => $pedido->id])
            ->call('abrirConfirmacionEntrega')
            ->call('marcarEntregado')
            ->assertSet('confirmandoEntrega', false)
            ->assertSet('errorMsg', '')
            ->assertSee('Pedido entregado.');

        $this->assertSame('entregado', $pedido->fresh()->estado);
        $this->assertNotNull($pedido->fresh()->fecha_entrega);
        $pantalla->call('marcarEntregado');
        $this->assertSame(1, HistorialEstadoPedido::where('pedido_id', $pedido->id)->where('estado_nuevo', 'entregado')->count());
    }

    public function test_saldo_pendiente_no_permite_abrir_ni_entregar(): void
    {
        $pedido = $this->pedido(total: 500);
        Livewire::test('pedidos.show', ['id' => $pedido->id])
            ->call('abrirConfirmacionEntrega')
            ->assertSet('confirmandoEntrega', false)
            ->assertSet('errorMsg', 'Cobra el saldo pendiente antes de entregar.')
            ->call('marcarEntregado');
        $this->assertSame('listo_entrega', $pedido->fresh()->estado);
    }

    public function test_confirmacion_revalida_el_saldo_que_cambio_despues_de_abrir(): void
    {
        $pedido = $this->pedido();
        $pantalla = Livewire::test('pedidos.show', ['id' => $pedido->id])->call('abrirConfirmacionEntrega');
        $pedido->update(['total' => 500, 'subtotal' => 500]);
        $pantalla->call('marcarEntregado')->assertSet('confirmandoEntrega', false);
        $this->assertSame('listo_entrega', $pedido->fresh()->estado);
        $this->assertSame(0, HistorialEstadoPedido::where('pedido_id', $pedido->id)->count());
    }

    public function test_estado_incompatible_no_abre_modal(): void
    {
        $pedido = $this->pedido('borrador');
        Livewire::test('pedidos.show', ['id' => $pedido->id])
            ->call('abrirConfirmacionEntrega')
            ->assertSet('confirmandoEntrega', false)
            ->assertSet('errorMsg', 'Solo se puede entregar un pedido que ya llegó a la distribuidora.');
        $this->assertSame('borrador', $pedido->fresh()->estado);
    }

    public function test_otro_tenant_no_puede_abrir_el_pedido(): void
    {
        $pedido = $this->pedido();
        $this->actingAs(Usuario::where('email', 'admin@boutiquedelcalzado.test')->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->expectException(ModelNotFoundException::class);
        Livewire::test('pedidos.show', ['id' => $pedido->id]);
    }

    public function test_una_revendedora_no_puede_abrir_la_confirmacion(): void
    {
        $pedido = $this->pedido();
        $this->actingAs(Usuario::where('email', 'maria.lopez@revendedor.test')->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Livewire::test('pedidos.show', ['id' => $pedido->id])
            ->call('abrirConfirmacionEntrega')->assertForbidden();
        $this->assertSame('listo_entrega', $pedido->fresh()->estado);
    }

    public function test_una_persona_sin_sesion_es_redirigida(): void
    {
        $pedido = $this->pedido();
        Auth::logout();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Livewire::test('pedidos.show', ['id' => $pedido->id])->assertRedirect(route('login'));
    }
}
