<?php

namespace Tests\Feature\Sprint4;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\Linea;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Services\Distribuidora\ActivarLineaDistribuidoraAction;
use App\Services\Distribuidora\CupoLineasDistribuidora;
use App\Services\Distribuidora\DesactivarLineaDistribuidoraAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TG-210 (Ola 2, K3) — Qué líneas vende cada distribuidora.
 *
 * El catálogo es compartido, pero cada distribuidora activa solo las líneas
 * que su plan le permite (sección 4.2 del diseño). Aquí se prueba la regla,
 * no la pantalla: la pantalla "Mis líneas" solo llamará a estas acciones.
 */
class LineasDeLaDistribuidoraTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

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

    private function cupo(): CupoLineasDistribuidora
    {
        return app(CupoLineasDistribuidora::class);
    }

    private function distribuidoraDemo(): Distribuidora
    {
        return Distribuidora::where('slug', 'calzados-ramirez')->firstOrFail();
    }

    /** Deja la suscripción de la distribuidora demo con el límite que pida la prueba. */
    private function conPlanDe(int $incluidas, int $extras = 0): void
    {
        $plan = PlanSuscripcion::firstOrCreate(
            ['nombre' => 'Plan de prueba TG-210'],
            [
                'descripcion' => 'Solo para pruebas',
                'precio_base_mensual' => 1,
                'lineas_incluidas' => $incluidas,
                'precio_linea_extra' => 0,
                'activo' => true,
            ]
        );

        Suscripcion::withoutGlobalScopes()->updateOrCreate(
            ['distribuidora_id' => $this->distribuidoraDemo()->id, 'estado' => 'activa'],
            [
                'plan_id' => $plan->id,
                'fecha_inicio' => now()->toDateString(),
                'precio_base_contratado' => 1,
                'lineas_incluidas_contratadas' => $incluidas,
                'precio_linea_extra_contratado' => 0,
                'lineas_extra_contratadas' => $extras,
            ]
        );
    }

    private function nuevaLinea(string $nombre, bool $activa = true): Linea
    {
        return Linea::create(['nombre' => $nombre, 'activa' => $activa]);
    }

    // ------------------------------------------------------------------
    // Activar
    // ------------------------------------------------------------------

    public function test_la_distribuidora_activa_una_linea_del_catalogo(): void
    {
        $this->conPlanDe(2);
        $linea = $this->nuevaLinea('Impuls');

        $activada = $this->activar()->ejecutar($linea->id);

        $this->assertTrue($activada->activa);
        $this->assertFalse($activada->es_extra);
        $this->assertNotNull($activada->fecha_activacion);
        $this->assertSame($this->distribuidoraDemo()->id, (int) $activada->distribuidora_id);
    }

    public function test_activar_dos_veces_la_misma_linea_no_la_duplica(): void
    {
        $this->conPlanDe(3);
        $linea = $this->nuevaLinea('Confort');

        $primera = $this->activar()->ejecutar($linea->id);
        $segunda = $this->activar()->ejecutar($linea->id);

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, DistribuidoraLinea::where('linea_id', $linea->id)->count());
    }

    public function test_no_se_puede_pasar_del_limite_del_plan(): void
    {
        $this->conPlanDe(1);
        $this->activar()->ejecutar($this->nuevaLinea('Primera')->id);

        $segunda = $this->nuevaLinea('Segunda');

        try {
            $this->activar()->ejecutar($segunda->id);
            $this->fail('Dejó pasar el límite del plan.');
        } catch (OperacionInvalidaException $e) {
            $this->assertStringContainsString('Tu plan permite 1 línea(s) activa(s)', $e->getMessage());
        }

        $this->assertSame(0, DistribuidoraLinea::where('linea_id', $segunda->id)->count());
    }

    /** Las extras contratadas sí cuentan para el límite, y se marcan como extra. */
    public function test_con_lineas_extra_contratadas_se_puede_activar_una_mas(): void
    {
        $this->conPlanDe(1, extras: 1);
        $this->activar()->ejecutar($this->nuevaLinea('Incluida')->id);

        $extra = $this->activar()->ejecutar($this->nuevaLinea('De más')->id);

        $this->assertTrue($extra->es_extra);
        $this->assertSame(2, $this->cupo()->activas());
        $this->assertSame(0, $this->cupo()->disponibles());
    }

    public function test_no_se_puede_activar_una_linea_que_no_existe(): void
    {
        $this->conPlanDe(2);

        $this->expectException(OperacionInvalidaException::class);
        $this->activar()->ejecutar(999999);
    }

    public function test_no_se_puede_activar_una_linea_retirada_del_catalogo(): void
    {
        $this->conPlanDe(2);
        $retirada = $this->nuevaLinea('Retirada', activa: false);

        try {
            $this->activar()->ejecutar($retirada->id);
            $this->fail('Dejó activar una línea que no está en el catálogo.');
        } catch (OperacionInvalidaException $e) {
            $this->assertStringContainsString('no está disponible en el catálogo', $e->getMessage());
        }
    }

    /** Si el admin general retira una línea, deja de ocupar lugar del plan. */
    public function test_una_linea_retirada_del_catalogo_deja_de_ocupar_cupo(): void
    {
        $this->conPlanDe(1);
        $linea = $this->nuevaLinea('Se va a retirar');
        $this->activar()->ejecutar($linea->id);

        $this->assertSame(0, $this->cupo()->disponibles());

        $linea->update(['activa' => false]);

        $this->assertSame(0, $this->cupo()->activas());
        $this->assertSame(1, $this->cupo()->disponibles());

        // Y a quien ya la tenía no se le quita.
        $this->assertTrue(DistribuidoraLinea::where('linea_id', $linea->id)->firstOrFail()->activa);

        // Con el lugar libre puede activar otra.
        $this->activar()->ejecutar($this->nuevaLinea('La nueva')->id);
    }

    // ------------------------------------------------------------------
    // Desactivar
    // ------------------------------------------------------------------

    public function test_desactivar_conserva_el_registro_y_libera_el_lugar(): void
    {
        $this->conPlanDe(1);
        $linea = $this->nuevaLinea('Temporal');
        $this->activar()->ejecutar($linea->id);

        $desactivada = $this->desactivar()->ejecutar($linea->id);

        $this->assertFalse($desactivada->activa);
        $this->assertNotNull($desactivada->fecha_activacion, 'Se perdió desde cuándo la vendía.');
        $this->assertSame(1, DistribuidoraLinea::where('linea_id', $linea->id)->count());
        $this->assertSame(1, $this->cupo()->disponibles());
    }

    public function test_volver_a_activarla_usa_la_misma_fila_y_vuelve_a_ocupar_lugar(): void
    {
        $this->conPlanDe(1);
        $linea = $this->nuevaLinea('Va y viene');
        $primera = $this->activar()->ejecutar($linea->id);
        $this->desactivar()->ejecutar($linea->id);

        $reactivada = $this->activar()->ejecutar($linea->id);

        $this->assertSame($primera->id, $reactivada->id);
        $this->assertTrue($reactivada->activa);
        $this->assertSame(0, $this->cupo()->disponibles());
    }

    public function test_no_se_puede_desactivar_una_linea_que_no_tiene(): void
    {
        $this->conPlanDe(2);

        $this->expectException(OperacionInvalidaException::class);
        $this->desactivar()->ejecutar($this->nuevaLinea('Ajena')->id);
    }

    // ------------------------------------------------------------------
    // Cada distribuidora con lo suyo
    // ------------------------------------------------------------------

    public function test_una_distribuidora_no_ve_ni_toca_las_lineas_de_otra(): void
    {
        $this->conPlanDe(2);
        $miLinea = $this->nuevaLinea('Mía');
        $this->activar()->ejecutar($miLinea->id);

        $otra = Distribuidora::create([
            'nombre_comercial' => 'Zapatería Rival',
            'slug' => 'zapateria-rival-'.uniqid(),
            'estado' => 'activa',
            'fecha_solicitud' => now(),
            'fecha_aprobacion' => now(),
        ]);

        $lineaDeLaOtra = $this->nuevaLinea('De la otra');

        Tenant::forzar($otra->id, function () use ($otra, $lineaDeLaOtra) {
            Suscripcion::create([
                'distribuidora_id' => $otra->id,
                'plan_id' => PlanSuscripcion::firstOrFail()->id,
                'fecha_inicio' => now()->toDateString(),
                'estado' => 'activa',
                'precio_base_contratado' => 1,
                'lineas_incluidas_contratadas' => 2,
                'precio_linea_extra_contratado' => 0,
                'lineas_extra_contratadas' => 0,
            ]);

            $this->activar()->ejecutar($lineaDeLaOtra->id);

            // Desde la otra distribuidora solo se ve lo suyo.
            $this->assertSame(1, DistribuidoraLinea::count());
            $this->assertSame($lineaDeLaOtra->id, (int) DistribuidoraLinea::firstOrFail()->linea_id);
        });

        Tenant::olvidarCache();

        // Y desde la demo, también solo lo suyo.
        $this->assertSame(1, DistribuidoraLinea::count());
        $this->assertSame($miLinea->id, (int) DistribuidoraLinea::firstOrFail()->linea_id);

        // No puede desactivar la de la otra: para ella, no existe.
        $this->expectException(OperacionInvalidaException::class);
        $this->desactivar()->ejecutar($lineaDeLaOtra->id);
    }

    /** Las dos pueden vender la misma línea del catálogo compartido. */
    public function test_dos_distribuidoras_pueden_activar_la_misma_linea(): void
    {
        $this->conPlanDe(2);
        $compartida = $this->nuevaLinea('Compartida');
        $this->activar()->ejecutar($compartida->id);

        $otra = Distribuidora::create([
            'nombre_comercial' => 'Calzado Vecino',
            'slug' => 'calzado-vecino-'.uniqid(),
            'estado' => 'activa',
            'fecha_solicitud' => now(),
            'fecha_aprobacion' => now(),
        ]);

        Tenant::forzar($otra->id, function () use ($otra, $compartida) {
            Suscripcion::create([
                'distribuidora_id' => $otra->id,
                'plan_id' => PlanSuscripcion::firstOrFail()->id,
                'fecha_inicio' => now()->toDateString(),
                'estado' => 'activa',
                'precio_base_contratado' => 1,
                'lineas_incluidas_contratadas' => 1,
                'precio_linea_extra_contratado' => 0,
                'lineas_extra_contratadas' => 0,
            ]);

            $this->assertTrue($this->activar()->ejecutar($compartida->id)->activa);
        });

        $this->assertSame(2, DistribuidoraLinea::withoutGlobalScopes()->where('linea_id', $compartida->id)->count());
    }
}
