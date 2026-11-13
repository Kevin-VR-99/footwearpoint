<?php

namespace Tests\Feature\Admin;

use App\Models\ActividadDiaria;
use App\Models\Distribuidora;
use App\Models\Pago;
use App\Models\Usuario;
use App\Models\VentaDirecta;
use App\Services\Metricas\IndicadoresDeMetricas;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaAdminGeneral;
use Tests\TestCase;

/**
 * TG-394 (A34) — Los indicadores de Métricas.
 *
 * El «listo cuando» de la tarjeta son dos cosas y las dos se prueban aquí:
 * las definiciones se ven en la pantalla, y los ingresos de suscripción no se
 * mezclan con las ventas de tienda.
 */
class IndicadoresDeMetricasTest extends TestCase
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

    private function rango(int $dias = 30): array
    {
        $fin = now(ActividadDiaria::ZONA)->endOfDay();

        return [$fin->copy()->subDays($dias - 1)->startOfDay(), $fin];
    }

    private function calcular(?int $distribuidora = null, ?int $plan = null, int $dias = 30): array
    {
        [$desde, $hasta] = $this->rango($dias);

        return app(IndicadoresDeMetricas::class)->calcular($desde, $hasta, $distribuidora, $plan);
    }

    // --- Lo que NO se mezcla ------------------------------------------------

    /**
     * Dos bolsillos distintos.
     *
     * Los ingresos de suscripción son de FootwearPoint; las ventas de tienda
     * son de la distribuidora. Sumarlos daría un número que no significa nada
     * y haría parecer que FootwearPoint gana mucho más de lo que gana.
     */
    public function test_los_ingresos_de_suscripcion_no_incluyen_las_ventas_de_tienda(): void
    {
        $d = Distribuidora::withoutGlobalScopes()->firstOrFail();

        Pago::withoutGlobalScopes()->create([
            'distribuidora_id' => $d->id,
            'folio' => 'PS-'.uniqid(),
            'tipo' => 'suscripcion',
            'direccion' => 'entrada',
            'metodo' => 'transferencia',
            'monto' => 500,
            'fecha_pago' => now(),
            'estado' => 'aplicado',
        ]);

        VentaDirecta::withoutGlobalScopes()->create([
            'distribuidora_id' => $d->id,
            'sucursal_id' => DB::table('sucursales')->value('id'),
            'folio' => 'V-'.uniqid(),
            'fecha_venta' => now(),
            'subtotal' => 1200,
            'descuento' => 0,
            'total' => 1200,
            'estado' => 'completada',
            'registrada_por_staff_id' => DB::table('distribuidora_staff')->value('id'),
        ]);

        $n = $this->calcular();

        $this->assertSame(500.0, $n['ingresos_suscripcion'], 'Una venta de tienda se coló en los ingresos de FootwearPoint.');
        $this->assertSame(1200.0, $n['ventas_tienda'], 'Un pago de suscripción se coló en las ventas de tienda.');
    }

    public function test_una_venta_anulada_no_cuenta(): void
    {
        $d = Distribuidora::withoutGlobalScopes()->firstOrFail();

        VentaDirecta::withoutGlobalScopes()->create([
            'distribuidora_id' => $d->id,
            'sucursal_id' => DB::table('sucursales')->value('id'),
            'folio' => 'V-'.uniqid(),
            'fecha_venta' => now(),
            'subtotal' => 900, 'descuento' => 0, 'total' => 900,
            'estado' => 'anulada',
            'anulada_at' => now(),
            'registrada_por_staff_id' => DB::table('distribuidora_staff')->value('id'),
        ]);

        $this->assertSame(0.0, $this->calcular()['ventas_tienda']);
    }

    /** Un pago pendiente todavía no es un ingreso. */
    public function test_un_pago_de_suscripcion_pendiente_no_cuenta(): void
    {
        $d = Distribuidora::withoutGlobalScopes()->firstOrFail();

        Pago::withoutGlobalScopes()->create([
            'distribuidora_id' => $d->id,
            'folio' => 'PS-'.uniqid(),
            'tipo' => 'suscripcion', 'direccion' => 'entrada', 'metodo' => 'transferencia',
            'monto' => 700, 'fecha_pago' => now(), 'estado' => 'pendiente',
        ]);

        $this->assertSame(0.0, $this->calcular()['ingresos_suscripcion']);
    }

    // --- Usuarios activos ---------------------------------------------------

    public function test_quien_entra_por_panel_y_app_cuenta_una_vez(): void
    {
        $u = Usuario::query()->firstOrFail();
        $hoy = now(ActividadDiaria::ZONA)->toDateString();

        foreach (['panel', 'app'] as $canal) {
            DB::table('actividad_diaria')->insertOrIgnore(['usuario_id' => $u->id, 'fecha' => $hoy, 'canal' => $canal]);
        }

        $this->assertSame(1, $this->calcular()['usuarios_activos']);
    }

    /**
     * El detalle solo se guarda 90 días (A43).
     *
     * Si piden un año, el número no puede cubrirlo: se recorta y se avisa,
     * en vez de dar una cifra que parezca del año entero.
     */
    public function test_un_periodo_mas_largo_que_el_detalle_se_avisa(): void
    {
        $this->assertFalse($this->calcular(dias: 30)['periodo_recortado']);

        $largo = $this->calcular(dias: 365);

        $this->assertTrue($largo['periodo_recortado'], 'Pidieron un año y nadie avisó que solo hay 90 días.');
        $this->assertNotNull($largo['usuarios_desde']);
    }

    // --- La regla de A23, reutilizada --------------------------------------

    /** La misma regla que la pantalla de Planes, no una copia. */
    public function test_el_plan_mas_contratado_usa_la_regla_de_a23(): void
    {
        $delServicio = app(\App\Services\Metricas\PlanMasContratado::class)->calcular();

        $this->assertSame($delServicio['nombres'], $this->calcular()['plan_mas_contratado']);
        $this->assertSame($delServicio['cuantas'], $this->calcular()['plan_mas_contratado_cuantas']);
    }

    public function test_la_pantalla_de_planes_y_la_de_metricas_dicen_lo_mismo(): void
    {
        $vista = file_get_contents(base_path('resources/views/components/admin/⚡planes-index.blade.php'));

        $this->assertStringContainsString('PlanMasContratado', $vista, 'Planes volvió a calcular la regla por su cuenta.');
    }

    // --- Las definiciones, en la pantalla -----------------------------------

    public function test_cada_numero_enseña_que_significa(): void
    {
        $this->comoAdminGeneral();

        $html = $this->get('/admin/metricas')->assertOk()->getContent();

        foreach (IndicadoresDeMetricas::definiciones() as $clave => $texto) {
            // El primer trozo basta: el texto completo lleva comillas y
            // acentos que el HTML escapa.
            $this->assertStringContainsString(
                e(mb_substr($texto, 0, 40)),
                $html,
                "No se ve la definición de «{$clave}»."
            );
        }
    }

    public function test_la_pantalla_dice_que_el_dinero_no_se_suma(): void
    {
        $this->comoAdminGeneral();

        $this->get('/admin/metricas')
            ->assertOk()
            ->assertSee('Son dinero distinto y por eso nunca se suman', false);
    }

    public function test_los_cuatro_indicadores_estan_en_la_pantalla(): void
    {
        $this->comoAdminGeneral();

        $html = $this->get('/admin/metricas')->assertOk()->getContent();

        foreach (['usuarios-activos', 'ingresos-suscripcion', 'ventas-tienda', 'plan-mas-contratado'] as $cual) {
            $this->assertStringContainsString('data-indicador="'.$cual.'"', $html);
        }
    }

    // --- Los filtros ---------------------------------------------------------

    public function test_el_filtro_de_distribuidora_separa_el_dinero(): void
    {
        $distribuidoras = Distribuidora::withoutGlobalScopes()->take(2)->get();

        foreach ($distribuidoras as $i => $d) {
            // La sucursal y el staff tienen que ser DE esa distribuidora: hay
            // una llave compuesta (distribuidora_id, sucursal_id) que lo exige.
            VentaDirecta::withoutGlobalScopes()->create([
                'distribuidora_id' => $d->id,
                'sucursal_id' => DB::table('sucursales')->where('distribuidora_id', $d->id)->value('id'),
                'folio' => 'V-'.uniqid(),
                'fecha_venta' => now(),
                'subtotal' => 100 * ($i + 1), 'descuento' => 0, 'total' => 100 * ($i + 1),
                'estado' => 'completada',
                'registrada_por_staff_id' => DB::table('distribuidora_staff')->where('distribuidora_id', $d->id)->value('id'),
            ]);
        }

        $this->assertSame(300.0, $this->calcular()['ventas_tienda']);
        $this->assertSame(100.0, $this->calcular($distribuidoras[0]->id)['ventas_tienda']);
        $this->assertSame(200.0, $this->calcular($distribuidoras[1]->id)['ventas_tienda']);
    }

    /**
     * El plan filtra ingresos pero NO ventas de tienda.
     *
     * El plan es el contrato de la distribuidora con FootwearPoint; no tiene
     * nada que ver con a quién le vende ella. Filtrar ventas por plan daría un
     * número que no responde ninguna pregunta.
     */
    public function test_el_filtro_de_plan_no_toca_las_ventas_de_tienda(): void
    {
        $d = Distribuidora::withoutGlobalScopes()->firstOrFail();
        $plan = \App\Models\PlanSuscripcion::query()->firstOrFail();

        VentaDirecta::withoutGlobalScopes()->create([
            'distribuidora_id' => $d->id,
            'sucursal_id' => DB::table('sucursales')->value('id'),
            'folio' => 'V-'.uniqid(),
            'fecha_venta' => now(),
            'subtotal' => 450, 'descuento' => 0, 'total' => 450,
            'estado' => 'completada',
            'registrada_por_staff_id' => DB::table('distribuidora_staff')->value('id'),
        ]);

        $this->assertSame(450.0, $this->calcular(plan: $plan->id)['ventas_tienda']);
    }

    public function test_lo_de_fuera_del_periodo_no_cuenta(): void
    {
        $d = Distribuidora::withoutGlobalScopes()->firstOrFail();

        Pago::withoutGlobalScopes()->create([
            'distribuidora_id' => $d->id,
            'folio' => 'PS-'.uniqid(),
            'tipo' => 'suscripcion', 'direccion' => 'entrada', 'metodo' => 'transferencia',
            'monto' => 999,
            'fecha_pago' => Carbon::now()->subDays(100),
            'estado' => 'aplicado',
        ]);

        $this->assertSame(0.0, $this->calcular(dias: 30)['ingresos_suscripcion']);
    }
}
