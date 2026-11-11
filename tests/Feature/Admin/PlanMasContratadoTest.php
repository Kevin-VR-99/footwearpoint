<?php

namespace Tests\Feature\Admin;

use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * TG-383 A23 — La métrica «Plan más contratado».
 *
 * Cuenta distribuidoras DISTINTAS con suscripción activa en cada plan. Las
 * tres decisiones que hay detrás, y que son las que estas pruebas fijan:
 *
 * - Distintas y no suscripciones: una distribuidora deja varias filas con el
 *   tiempo (renovaciones, cambios de plan) y contarlas todas inflaria el
 *   numero.
 * - Sin pruebas: de los estados (activa, prueba, cancelada, suspendida,
 *   vencida) solo cuenta 'activa'. Una prueba no dice que alguien eligio ese
 *   plan.
 * - Los empates se enseñan juntos, porque mostrar uno solo seria elegir al
 *   azar cual.
 */
class PlanMasContratadoTest extends TestCase
{
    use CreaAdminGeneral;
    use RefreshDatabase;

    protected bool $seed = true;

    private function comoAdminGeneral(): void
    {
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs($this->adminGeneral());
        Tenant::olvidarCache();
    }

    /** Deja a todas las distribuidoras sin suscripción, para partir de cero. */
    private function sinSuscripciones(): void
    {
        Suscripcion::withoutGlobalScopes()->delete();
    }

    private function suscribir(Distribuidora $distribuidora, PlanSuscripcion $plan, string $estado = 'activa'): Suscripcion
    {
        return Suscripcion::withoutGlobalScopes()->create([
            'distribuidora_id' => $distribuidora->id,
            'plan_id' => $plan->id,
            'fecha_inicio' => now()->subMonth(),
            'estado' => $estado,
            'precio_base_contratado' => $plan->precio_base_mensual,
            'lineas_incluidas_contratadas' => $plan->lineas_incluidas,
            'precio_linea_extra_contratado' => $plan->precio_linea_extra,
            'lineas_extra_contratadas' => 0,
        ]);
    }

    private function resumen(): array
    {
        return Livewire::test('admin.planes-index')->get('resumen');
    }

    public function test_gana_el_plan_con_mas_distribuidoras(): void
    {
        $this->comoAdminGeneral();
        $this->sinSuscripciones();

        [$a, $b] = PlanSuscripcion::orderBy('id')->take(2)->get()->all();
        $distribuidoras = Distribuidora::withoutGlobalScopes()->take(2)->get();

        $this->suscribir($distribuidoras[0], $a);
        $this->suscribir($distribuidoras[1], $a);

        $resumen = $this->resumen();

        $this->assertSame($a->nombre, $resumen['mas_contratado']);
        $this->assertSame(2, $resumen['mas_contratado_cuantas']);
    }

    /**
     * Dos filas de la misma distribuidora no valen por dos. Pasa de verdad:
     * al renovar o cambiar de plan queda el historial.
     */
    public function test_la_misma_distribuidora_no_cuenta_dos_veces(): void
    {
        $this->comoAdminGeneral();
        $this->sinSuscripciones();

        $plan = PlanSuscripcion::orderBy('id')->first();
        $distribuidora = Distribuidora::withoutGlobalScopes()->first();

        $this->suscribir($distribuidora, $plan);
        $this->suscribir($distribuidora, $plan);

        $this->assertSame(1, $this->resumen()['mas_contratado_cuantas']);
    }

    public function test_las_suscripciones_de_prueba_no_cuentan(): void
    {
        $this->comoAdminGeneral();
        $this->sinSuscripciones();

        [$a, $b] = PlanSuscripcion::orderBy('id')->take(2)->get()->all();
        $distribuidoras = Distribuidora::withoutGlobalScopes()->take(2)->get();

        // El plan B tendria mas si las pruebas contaran.
        $this->suscribir($distribuidoras[0], $a, 'activa');
        $this->suscribir($distribuidoras[0], $b, 'prueba');
        $this->suscribir($distribuidoras[1], $b, 'prueba');

        $resumen = $this->resumen();

        $this->assertSame($a->nombre, $resumen['mas_contratado'], 'Una suscripción de prueba se coló en la cuenta.');
        $this->assertSame(1, $resumen['mas_contratado_cuantas']);
    }

    public function test_los_empates_se_enseñan_juntos(): void
    {
        $this->comoAdminGeneral();
        $this->sinSuscripciones();

        [$a, $b] = PlanSuscripcion::orderBy('id')->take(2)->get()->all();
        $distribuidoras = Distribuidora::withoutGlobalScopes()->take(2)->get();

        $this->suscribir($distribuidoras[0], $a);
        $this->suscribir($distribuidoras[1], $b);

        $esperado = collect([$a->nombre, $b->nombre])->sort()->implode(' y ');

        $this->assertSame($esperado, $this->resumen()['mas_contratado']);
    }

    public function test_sin_suscripciones_activas_no_inventa_un_ganador(): void
    {
        $this->comoAdminGeneral();
        $this->sinSuscripciones();

        $resumen = $this->resumen();

        $this->assertSame('', $resumen['mas_contratado']);
        $this->assertSame(0, $resumen['mas_contratado_cuantas']);

        // Y en pantalla sale el guion, no un hueco raro.
        Livewire::test('admin.planes-index')
            ->assertSee('Plan más contratado')
            ->assertSee('Sin suscripciones activas');
    }
}
