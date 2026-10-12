<?php

namespace Tests\Feature\Pedido;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-52 / TG-244 (E13-02) — Alerta del plazo de recolección en el detalle del
 * pedido listo para entrega: normal, por vencer (48 h o menos) y vencido.
 */
class AlertaPlazoRecoleccionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedido(string $estado, ?string $limite): Pedido
    {
        return Pedido::create([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'REC-'.uniqid(),
            'tipo' => 'cliente_directo',
            'cliente_directo_id' => ClienteDirecto::query()->value('id'),
            'estado' => $estado,
            'subtotal' => 500,
            'total' => 500,
            'fecha_colocacion' => '2026-10-01 10:00:00',
            'fecha_limite_recoleccion' => $limite,
            'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
        ]);
    }

    public function test_dentro_del_plazo_muestra_la_fecha_limite(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');
        $p = $this->pedido('listo_entrega', '2026-10-15 18:00:00');

        Livewire::test('pedidos.show', ['id' => $p->id])
            ->assertSee('Tiene hasta el 15/10/2026 18:00 para recogerlo.')
            ->assertDontSee('venció')
            ->assertDontSee('por vencer');
    }

    public function test_a_48_horas_o_menos_avisa_que_esta_por_vencer(): void
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $p = $this->pedido('listo_entrega', '2026-10-15 18:00:00');

        Livewire::test('pedidos.show', ['id' => $p->id])
            ->assertSee('El plazo de recolección está por vencer: el cliente tiene hasta el 15/10/2026 18:00.');
    }

    public function test_pasado_el_limite_avisa_que_vencio(): void
    {
        Carbon::setTestNow('2026-10-16 09:00:00');
        $p = $this->pedido('listo_entrega', '2026-10-15 18:00:00');

        Livewire::test('pedidos.show', ['id' => $p->id])
            ->assertSee('El plazo de recolección venció el 15/10/2026 18:00.');
    }

    public function test_sin_limite_o_en_otro_estado_no_hay_alerta(): void
    {
        Carbon::setTestNow('2026-10-16 09:00:00');

        Livewire::test('pedidos.show', ['id' => $this->pedido('listo_entrega', null)->id])
            ->assertDontSee('plazo de recolección')
            ->assertDontSee('para recogerlo');

        Livewire::test('pedidos.show', ['id' => $this->pedido('entregado', '2026-10-15 18:00:00')->id])
            ->assertDontSee('plazo de recolección');
    }
}
