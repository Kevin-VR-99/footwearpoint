<?php

namespace Tests\Feature\Suscripcion;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Services\Suscripcion\AsignarSuscripcionAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Database\Seeders\AdminGeneralSeeder;
use Database\Seeders\DemoDistribuidoraSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-224 (G3) — Asignar suscripción (E2-04) vive en una sola acción que usan
 * la API del admin general y el panel admin.distribuidoras-index. El
 * comportamiento debe ser el mismo de antes.
 */
class AsignarSuscripcionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Usuario $adminGeneral;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olvidarSesionEnMemoria();

        $this->adminGeneral = Usuario::firstOrCreate(
            ['email' => AdminGeneralSeeder::EMAIL],
            ['nombre' => 'Admin General', 'password' => Hash::make('password'), 'estado' => 'activo']
        );

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $this->adminGeneral->assignRole('admin_general');
        $registrar->forgetCachedPermissions();
    }

    private function olvidarSesionEnMemoria(): void
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function distribuidora(string $slug = DemoDistribuidoraSeeder::SLUG): Distribuidora
    {
        return Distribuidora::where('slug', $slug)->firstOrFail();
    }

    private function plan(string $nombre = 'Pro'): PlanSuscripcion
    {
        return PlanSuscripcion::where('nombre', $nombre)->firstOrFail();
    }

    /** @return \Illuminate\Support\Collection<int, Suscripcion> */
    private function suscripciones(Distribuidora $distribuidora)
    {
        return Suscripcion::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->id)
            ->orderBy('id')
            ->get();
    }

    // ---------------------------------------------------------------
    // La acción
    // ---------------------------------------------------------------

    public function test_crea_la_suscripcion_con_los_precios_del_plan_y_cierra_la_anterior(): void
    {
        $this->actingAs($this->adminGeneral);
        $distribuidora = $this->distribuidora();
        $anterior = $this->suscripciones($distribuidora)->firstWhere('estado', 'activa');
        $this->assertNotNull($anterior);
        $plan = $this->plan();

        $nueva = app(AsignarSuscripcionAction::class)->ejecutar($distribuidora, $plan, 3, 2, false);

        $this->assertSame((int) $distribuidora->id, (int) $nueva->distribuidora_id);
        $this->assertSame('activa', $nueva->estado);
        $this->assertSame((int) $plan->id, (int) $nueva->plan_id);
        $this->assertEquals($plan->precio_base_mensual, $nueva->precio_base_contratado);
        $this->assertSame((int) $plan->lineas_incluidas, (int) $nueva->lineas_incluidas_contratadas);
        $this->assertEquals($plan->precio_linea_extra, $nueva->precio_linea_extra_contratado);
        $this->assertSame(2, (int) $nueva->lineas_extra_contratadas);
        $this->assertFalse($nueva->fresh()->renovacion_automatica);
        $this->assertSame(now()->toDateString(), $nueva->fecha_inicio->toDateString());
        $this->assertSame(now()->addMonths(3)->toDateString(), $nueva->fecha_fin->toDateString());

        $anterior->refresh();
        $this->assertSame('cancelada', $anterior->estado);
        $this->assertSame(now()->toDateString(), $anterior->fecha_fin->toDateString());

        $this->assertCount(1, $this->suscripciones($distribuidora)->where('estado', 'activa'));
    }

    public function test_no_toca_las_suscripciones_de_otra_distribuidora(): void
    {
        $this->actingAs($this->adminGeneral);
        $otra = $this->distribuidora(DemoDistribuidoraSeeder::SEGUNDA_SLUG);
        $antes = $this->suscripciones($otra)->map->only(['id', 'estado', 'fecha_fin'])->all();

        app(AsignarSuscripcionAction::class)->ejecutar($this->distribuidora(), $this->plan());

        $this->assertEquals($antes, $this->suscripciones($otra)->map->only(['id', 'estado', 'fecha_fin'])->all());
    }

    public function test_funciona_sin_sesion_y_deja_el_tenant_como_estaba(): void
    {
        $distribuidora = $this->distribuidora();

        $nueva = app(AsignarSuscripcionAction::class)->ejecutar($distribuidora, $this->plan());

        $this->assertSame((int) $distribuidora->id, (int) $nueva->distribuidora_id);
        $this->assertCount(1, $this->suscripciones($distribuidora)->where('estado', 'activa'));
        $this->assertNull(Tenant::id());
    }

    public function test_rechaza_una_distribuidora_que_no_esta_activa_ni_suspendida(): void
    {
        $distribuidora = $this->distribuidora();
        $distribuidora->forceFill(['estado' => 'rechazada'])->save();
        $antes = $this->suscripciones($distribuidora)->count();

        try {
            app(AsignarSuscripcionAction::class)->ejecutar($distribuidora, $this->plan());
            $this->fail('Debió rechazar la asignación.');
        } catch (OperacionInvalidaException $e) {
            $this->assertSame(AsignarSuscripcionAction::MENSAJE_ESTADO, $e->getMessage());
        }

        $this->assertSame($antes, $this->suscripciones($distribuidora)->count());
    }

    public function test_rechaza_un_plan_inactivo_sin_cerrar_la_suscripcion_actual(): void
    {
        $distribuidora = $this->distribuidora();
        $plan = $this->plan();
        $plan->forceFill(['activo' => false])->save();

        $this->expectException(OperacionInvalidaException::class);
        $this->expectExceptionMessage(AsignarSuscripcionAction::MENSAJE_PLAN_INACTIVO);

        try {
            app(AsignarSuscripcionAction::class)->ejecutar($distribuidora, $plan);
        } finally {
            $this->assertCount(1, $this->suscripciones($distribuidora)->where('estado', 'activa'));
        }
    }

    // ---------------------------------------------------------------
    // API del admin general (mismas respuestas de antes)
    // ---------------------------------------------------------------

    public function test_la_api_asigna_la_suscripcion(): void
    {
        Sanctum::actingAs($this->adminGeneral);
        $distribuidora = $this->distribuidora();
        $plan = $this->plan();

        $this->postJson("/api/admin/distribuidoras/{$distribuidora->id}/suscripcion", [
            'plan_id'                  => $plan->id,
            'meses'                    => 2,
            'lineas_extra_contratadas' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Suscripción asignada correctamente.')
            ->assertJsonPath('data.plan_id', $plan->id)
            ->assertJsonPath('data.estado', 'activa')
            ->assertJsonPath('data.lineas_extra_contratadas', 1)
            ->assertJsonPath('data.renovacion_automatica', true)
            ->assertJsonPath('data.plan.nombre', 'Pro');

        $activas = $this->suscripciones($distribuidora)->where('estado', 'activa');
        $this->assertCount(1, $activas);
        $this->assertSame(now()->addMonths(2)->toDateString(), $activas->first()->fecha_fin->toDateString());
    }

    public function test_la_api_responde_422_con_el_mismo_mensaje_si_la_distribuidora_no_puede(): void
    {
        Sanctum::actingAs($this->adminGeneral);
        $distribuidora = $this->distribuidora();
        $distribuidora->forceFill(['estado' => 'rechazada'])->save();

        $this->postJson("/api/admin/distribuidoras/{$distribuidora->id}/suscripcion", ['plan_id' => $this->plan()->id])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Solo se puede asignar suscripción a distribuidoras activas o suspendidas.']);
    }

    public function test_la_api_responde_422_con_el_mismo_mensaje_si_el_plan_esta_inactivo(): void
    {
        Sanctum::actingAs($this->adminGeneral);
        $plan = $this->plan();
        $plan->forceFill(['activo' => false])->save();

        $this->postJson("/api/admin/distribuidoras/{$this->distribuidora()->id}/suscripcion", ['plan_id' => $plan->id])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'El plan seleccionado no está activo.']);
    }

    // ---------------------------------------------------------------
    // Panel admin (Livewire)
    // ---------------------------------------------------------------

    public function test_el_panel_asigna_la_suscripcion_con_la_misma_accion(): void
    {
        $this->actingAs($this->adminGeneral);
        $distribuidora = $this->distribuidora();
        $plan = $this->plan('Enterprise');

        Livewire::test('admin.distribuidoras-index')
            ->call('abrirSuscripcion', $distribuidora->id)
            ->set('plan_id', (string) $plan->id)
            ->set('meses', '6')
            ->set('lineas_extra_contratadas', '0')
            ->call('guardarSuscripcion')
            ->assertHasNoErrors()
            ->assertSet('mostrarSuscripcion', false)
            ->assertSet('mensaje', "Suscripción «Enterprise» asignada a «{$distribuidora->nombre_comercial}».");

        $activas = $this->suscripciones($distribuidora)->where('estado', 'activa');
        $this->assertCount(1, $activas);
        $this->assertSame((int) $plan->id, (int) $activas->first()->plan_id);
        $this->assertSame(now()->addMonths(6)->toDateString(), $activas->first()->fecha_fin->toDateString());
    }

    public function test_el_panel_muestra_el_mensaje_si_el_plan_esta_inactivo(): void
    {
        $this->actingAs($this->adminGeneral);
        $distribuidora = $this->distribuidora();
        $plan = $this->plan();
        $activaAntes = $this->suscripciones($distribuidora)->firstWhere('estado', 'activa');

        $componente = Livewire::test('admin.distribuidoras-index')
            ->call('abrirSuscripcion', $distribuidora->id)
            ->set('plan_id', (string) $plan->id);

        $plan->forceFill(['activo' => false])->save();

        $componente->call('guardarSuscripcion')
            ->assertSet('mensaje', 'El plan seleccionado no está activo.')
            ->assertSet('mostrarSuscripcion', true);

        $this->assertSame('activa', $activaAntes->fresh()->estado);
    }
}
