<?php

namespace Tests\Feature\Funciones;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

/** Comparación de KPIs de Reportes contra el periodo anterior de la misma duración. */
class ComparacionKpisTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function variacion(float $actual, ?float $anterior, string $conviene = 'subir'): string
    {
        return Blade::render('<x-ui.variacion :actual="$a" :anterior="$b" :conviene="$c" />', ['a' => $actual, 'b' => $anterior, 'c' => $conviene]);
    }

    public function test_porcentaje_flecha_y_color_segun_conviene(): void
    {
        $sube = $this->variacion(150, 100);
        $this->assertStringContainsString('data-variacion="+50.0%"', $sube);
        $this->assertStringContainsString('text-fp-badge-success-fg', $sube);
        $this->assertStringContainsString('Sube', $sube);

        $baja = $this->variacion(50, 100);
        $this->assertStringContainsString('data-variacion="-50.0%"', $baja);
        $this->assertStringContainsString('text-fp-danger', $baja);

        $this->assertStringContainsString('text-fp-badge-success-fg', $this->variacion(50, 100, 'bajar'));
    }

    public function test_nuevo_o_guion_cuando_el_anterior_es_cero_o_no_existe(): void
    {
        $this->assertStringContainsString('data-variacion="Nuevo"', $this->variacion(3, 0));
        $this->assertStringContainsString('data-variacion="—"', $this->variacion(0, 0));
        $this->assertStringContainsString('data-variacion="—"', $this->variacion(3, null));
    }

    public function test_reportes_compara_contra_el_periodo_anterior(): void
    {
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $pedido = function (string $fecha) {
            $p = Pedido::create([
                'distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::query()->value('id'),
                'folio' => 'KPI-'.uniqid(), 'tipo' => 'cliente_directo',
                'cliente_directo_id' => ClienteDirecto::query()->value('id'),
                'estado' => 'colocado', 'subtotal' => 100, 'total' => 100,
                'fecha_colocacion' => $fecha.' 12:00:00',
                'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
            ]);
            $p->forceFill(['created_at' => $fecha.' 12:00:00'])->saveQuietly();
        };
        // Periodo actual 2031-03-11..20 (10 días): 2 pedidos. Anterior 2031-03-01..10: 1 pedido.
        $pedido('2031-03-12');
        $pedido('2031-03-15');
        $pedido('2031-03-05');

        $c = Livewire::test('reportes.index')->set('desde', '2031-03-11')->set('hasta', '2031-03-20');
        $this->assertSame(['desde' => '2031-03-01', 'hasta' => '2031-03-10'], $c->instance()->periodoAnterior);
        $this->assertSame(1.0, $c->instance()->comparacion['pedidos']);
        $c->assertSee('vs 01/03–10/03')->assertSee('data-variacion="+100.0%"', false);

        Livewire::test('reportes.index')->assertSee('data-sin-comparacion', false);
    }
}
