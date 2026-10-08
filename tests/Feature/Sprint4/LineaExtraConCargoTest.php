<?php

namespace Tests\Feature\Sprint4;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Auditoria;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Services\Distribuidora\ActivarLineaDistribuidoraAction;
use App\Services\Distribuidora\CobroSuscripcion;
use App\Services\Distribuidora\DesactivarLineaDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-211 (Ola 2, K4) — Línea extra con cargo (E2-06).
 *
 * Pasarse del plan se puede, pero cuesta: la distribuidora tiene que
 * confirmarlo, y a partir de ahí esa línea de más se suma a su cobro. Si
 * después la desactiva, deja de cobrarse en el siguiente periodo.
 *
 * El cobro de verdad con Mercado Pago es G11; aquí solo está la cuenta.
 */
class LineaExtraConCargoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const PRECIO_EXTRA = 350.00;

    private const PRECIO_BASE = 1200.00;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
    }

    private function activar(): ActivarLineaDistribuidoraAction
    {
        return app(ActivarLineaDistribuidoraAction::class);
    }

    private function desactivar(): DesactivarLineaDistribuidoraAction
    {
        return app(DesactivarLineaDistribuidoraAction::class);
    }

    private function cobro(): CobroSuscripcion
    {
        return app(CobroSuscripcion::class);
    }

    /**
     * Cada prueba parte de cero: el seeder demo ya le activa una línea a la
     * distribuidora, y aquí se mide el cupo con números exactos.
     */
    private function sinLineasActivas(): void
    {
        DistribuidoraLinea::query()->delete();
    }

    private function conPlanDe(int $incluidas, float $precioExtra = self::PRECIO_EXTRA): void
    {
        $plan = PlanSuscripcion::firstOrCreate(
            ['nombre' => 'Plan de prueba TG-211'],
            [
                'descripcion' => 'Solo para pruebas',
                'precio_base_mensual' => self::PRECIO_BASE,
                'lineas_incluidas' => $incluidas,
                'precio_linea_extra' => $precioExtra,
                'activo' => true,
            ]
        );

        $this->sinLineasActivas();

        Suscripcion::withoutGlobalScopes()->updateOrCreate(
            [
                'distribuidora_id' => Distribuidora::where('slug', 'calzados-ramirez')->value('id'),
                'estado' => 'activa',
            ],
            [
                'plan_id' => $plan->id,
                'fecha_inicio' => now()->toDateString(),
                'precio_base_contratado' => self::PRECIO_BASE,
                'lineas_incluidas_contratadas' => $incluidas,
                'precio_linea_extra_contratado' => $precioExtra,
                'lineas_extra_contratadas' => 0,
            ]
        );
    }

    private function nuevaLinea(string $nombre): Linea
    {
        return Linea::create(['nombre' => $nombre, 'activa' => true]);
    }

    // ------------------------------------------------------------------
    // Dentro del plan no se cobra nada de más
    // ------------------------------------------------------------------

    public function test_activar_dentro_del_plan_no_genera_cargo(): void
    {
        $this->conPlanDe(2);

        $this->activar()->ejecutar($this->nuevaLinea('Impuls')->id);

        $this->assertSame(0, $this->cobro()->extrasQueSeCobran());
        $this->assertEqualsWithDelta(self::PRECIO_BASE, $this->cobro()->montoDelSiguienteCobro(), 0.001);
    }

    // ------------------------------------------------------------------
    // Pasarse del plan
    // ------------------------------------------------------------------

    public function test_pasarse_del_plan_sin_confirmar_avisa_cuanto_cuesta(): void
    {
        $this->conPlanDe(1);
        $this->activar()->ejecutar($this->nuevaLinea('Incluida')->id);

        $deMas = $this->nuevaLinea('De más');

        try {
            $this->activar()->ejecutar($deMas->id);
            $this->fail('Activó una línea extra sin que nadie la confirmara.');
        } catch (OperacionInvalidaException $e) {
            $this->assertStringContainsString('las 1 línea(s) que incluye tu plan', $e->getMessage());
            $this->assertStringContainsString('$350.00 más por periodo', $e->getMessage());
            $this->assertStringContainsString('Confirma', $e->getMessage());
        }

        // No se activó ni se le cobró nada.
        $this->assertSame(0, $this->cobro()->extrasQueSeCobran());
        $this->assertEqualsWithDelta(self::PRECIO_BASE, $this->cobro()->montoDelSiguienteCobro(), 0.001);
    }

    /** Si el plan no cobra las líneas extra, el aviso no habla de dinero. */
    public function test_si_la_linea_extra_no_cuesta_el_aviso_no_menciona_monto(): void
    {
        $this->conPlanDe(1, precioExtra: 0);
        $this->activar()->ejecutar($this->nuevaLinea('Incluida')->id);

        try {
            $this->activar()->ejecutar($this->nuevaLinea('De más')->id);
            $this->fail('Activó una línea de más sin confirmación.');
        } catch (OperacionInvalidaException $e) {
            $this->assertStringContainsString('por encima de tu plan', $e->getMessage());
            $this->assertStringNotContainsString('$', $e->getMessage());
        }
    }

    public function test_al_confirmar_se_activa_y_se_suma_al_cobro(): void
    {
        $this->conPlanDe(1);
        $this->activar()->ejecutar($this->nuevaLinea('Incluida')->id);

        $extra = $this->activar()->ejecutar($this->nuevaLinea('De más')->id, aceptaCargoExtra: true);

        $this->assertTrue($extra->activa);
        $this->assertTrue($extra->es_extra);
        $this->assertSame(1, $this->cobro()->extrasQueSeCobran());
        $this->assertEqualsWithDelta(self::PRECIO_BASE + self::PRECIO_EXTRA, $this->cobro()->montoDelSiguienteCobro(), 0.001);

        // Y su plan ahora le permite esa línea de más.
        $this->assertSame(1, (int) $this->cobro()->suscripcionActiva()->lineas_extra_contratadas);
    }

    public function test_la_linea_extra_queda_registrada_en_la_auditoria(): void
    {
        $this->conPlanDe(1);
        $this->activar()->ejecutar($this->nuevaLinea('Incluida')->id);
        $this->activar()->ejecutar($this->nuevaLinea('De más')->id, aceptaCargoExtra: true);

        $registro = Auditoria::where('accion', 'linea_extra.activada')->latest('id')->first();

        $this->assertNotNull($registro, 'No quedó registro de la línea extra.');
        $this->assertSame('De más', $registro->datos_nuevos['linea']);
        $this->assertEqualsWithDelta(
            self::PRECIO_BASE + self::PRECIO_EXTRA,
            (float) $registro->datos_nuevos['monto_siguiente_cobro'],
            0.001
        );
    }

    public function test_dos_lineas_de_mas_se_cobran_las_dos(): void
    {
        $this->conPlanDe(1);
        $this->activar()->ejecutar($this->nuevaLinea('Incluida')->id);
        $this->activar()->ejecutar($this->nuevaLinea('Extra 1')->id, aceptaCargoExtra: true);
        $this->activar()->ejecutar($this->nuevaLinea('Extra 2')->id, aceptaCargoExtra: true);

        $this->assertSame(2, $this->cobro()->extrasQueSeCobran());
        $this->assertEqualsWithDelta(
            self::PRECIO_BASE + (2 * self::PRECIO_EXTRA),
            $this->cobro()->montoDelSiguienteCobro(),
            0.001
        );
    }

    // ------------------------------------------------------------------
    // Dejar de usarla deja de costar
    // ------------------------------------------------------------------

    public function test_al_renovar_una_extra_desactivada_deja_de_cobrarse(): void
    {
        $this->conPlanDe(1);
        $this->activar()->ejecutar($this->nuevaLinea('Incluida')->id);
        $deMas = $this->nuevaLinea('De más');
        $this->activar()->ejecutar($deMas->id, aceptaCargoExtra: true);

        $this->desactivar()->ejecutar($deMas->id);

        // Ya no se cuenta, aunque la suscripción todavía diga que tiene una extra.
        $this->assertSame(0, $this->cobro()->extrasQueSeCobran());
        $this->assertSame(1, (int) $this->cobro()->suscripcionActiva()->lineas_extra_contratadas);

        $suscripcion = $this->cobro()->recalcularExtrasParaRenovar();

        $this->assertSame(0, (int) $suscripcion->lineas_extra_contratadas);
        $this->assertEqualsWithDelta(self::PRECIO_BASE, $this->cobro()->montoDelSiguienteCobro(), 0.001);
        $this->assertNotNull(Auditoria::where('accion', 'suscripcion.extras_recalculadas')->first());
    }

    public function test_desactivar_una_extra_queda_en_la_auditoria(): void
    {
        $this->conPlanDe(1);
        $this->activar()->ejecutar($this->nuevaLinea('Incluida')->id);
        $deMas = $this->nuevaLinea('De más');
        $this->activar()->ejecutar($deMas->id, aceptaCargoExtra: true);

        $this->desactivar()->ejecutar($deMas->id);

        $registro = Auditoria::where('accion', 'linea_extra.desactivada')->latest('id')->first();

        $this->assertNotNull($registro);
        $this->assertSame('De más', $registro->datos_nuevos['linea']);
    }

    /** Si deja de usar una incluida, la de más ocupa su lugar y ya no se cobra. */
    public function test_al_soltar_una_incluida_la_de_mas_deja_de_ser_extra(): void
    {
        $this->conPlanDe(1);
        $incluida = $this->nuevaLinea('Incluida');
        $this->activar()->ejecutar($incluida->id);
        $this->activar()->ejecutar($this->nuevaLinea('De más')->id, aceptaCargoExtra: true);

        $this->desactivar()->ejecutar($incluida->id);

        $this->assertSame(0, $this->cobro()->extrasQueSeCobran());
        $this->assertEqualsWithDelta(self::PRECIO_BASE, $this->cobro()->montoDelSiguienteCobro(), 0.001);
    }

    /** Una línea que el admin general retiró del catálogo no se le cobra a nadie. */
    public function test_una_linea_retirada_del_catalogo_no_se_cobra(): void
    {
        $this->conPlanDe(1);
        $this->activar()->ejecutar($this->nuevaLinea('Incluida')->id);
        $deMas = $this->nuevaLinea('De más');
        $this->activar()->ejecutar($deMas->id, aceptaCargoExtra: true);

        $this->assertSame(1, $this->cobro()->extrasQueSeCobran());

        $deMas->update(['activa' => false]);

        $this->assertSame(0, $this->cobro()->extrasQueSeCobran());
        $this->assertEqualsWithDelta(self::PRECIO_BASE, $this->cobro()->montoDelSiguienteCobro(), 0.001);
    }
}
