<?php

namespace Tests\Feature\Suscripcion;

use App\Exceptions\CargoRevendedorExtraException;
use App\Models\Auditoria;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Models\RevendedorDistribuidora;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Services\Distribuidora\CobroSuscripcion;
use App\Services\Distribuidora\CupoRevendedoresDistribuidora;
use App\Services\Distribuidora\GestionarRevendedorAction;
use App\Services\Suscripcion\CrearPagoSuscripcionMercadoPagoAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Límite de clientes mayoristas (revendedores) por plan.
 *
 * Básico 25, Pro 50, Enterprise 100; cada revendedor de más cuesta $10 al
 * mes y se suma a la mensualidad, igual que las líneas extra. Pasarse solo
 * se puede aceptando el cargo. Quien ya tenía más que el límite no pierde a
 * ninguno ni paga por ellos.
 */
class LimiteRevendedoresPorPlanTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        Mail::fake();

        $this->admin = Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail();
        $this->actingAs($this->admin);
        Tenant::olvidarCache();
    }

    // ------------------------------------------------------------------
    // Ayudas
    // ------------------------------------------------------------------

    private function distribuidoraId(): int
    {
        return (int) Distribuidora::where('slug', 'calzados-ramirez')->value('id');
    }

    private function suscripcion(): Suscripcion
    {
        return Suscripcion::withoutGlobalScopes()
            ->where('distribuidora_id', $this->distribuidoraId())
            ->where('estado', 'activa')
            ->latest('id')
            ->firstOrFail();
    }

    private function activos(): int
    {
        return app(CupoRevendedoresDistribuidora::class)->activos($this->distribuidoraId());
    }

    /** Deja la suscripción con un límite de revendedores a la medida. */
    private function conLimite(int $incluidos, float $precio = 10.00, int $extrasAceptados = 0): Suscripcion
    {
        $suscripcion = $this->suscripcion();
        $suscripcion->update([
            'revendedores_incluidos_contratados' => $incluidos,
            'precio_revendedor_extra_contratado' => $precio,
            'revendedores_extra_contratados' => $extrasAceptados,
        ]);

        return $suscripcion->fresh();
    }

    private function afiliar(string $nombre, bool $acepta = false): RevendedorDistribuidora
    {
        return app(GestionarRevendedorAction::class)->afiliar(['nombre' => $nombre], $acepta);
    }

    // ------------------------------------------------------------------
    // Planes: valores por defecto y los de cada plan
    // ------------------------------------------------------------------

    public function test_los_planes_traen_su_limite_y_el_costo_del_extra(): void
    {
        $this->assertSame(25, PlanSuscripcion::where('nombre', 'Básico')->value('revendedores_incluidos'));
        $this->assertSame(50, PlanSuscripcion::where('nombre', 'Pro')->value('revendedores_incluidos'));
        $this->assertSame(100, PlanSuscripcion::where('nombre', 'Enterprise')->value('revendedores_incluidos'));

        foreach (PlanSuscripcion::whereIn('nombre', ['Básico', 'Pro', 'Enterprise'])->get() as $plan) {
            $this->assertEqualsWithDelta(10.0, (float) $plan->precio_revendedor_extra, 0.001);
        }
    }

    public function test_un_plan_nuevo_sin_datos_de_revendedores_toma_25_y_10(): void
    {
        $plan = PlanSuscripcion::create([
            'nombre' => 'Plan sin revendedores',
            'precio_base_mensual' => 100,
            'lineas_incluidas' => 1,
            'precio_linea_extra' => 50,
            'activo' => true,
        ])->fresh();

        $this->assertSame(25, $plan->revendedores_incluidos);
        $this->assertEqualsWithDelta(10.0, (float) $plan->precio_revendedor_extra, 0.001);
    }

    public function test_la_suscripcion_congela_el_limite_de_su_plan(): void
    {
        $pro = PlanSuscripcion::where('nombre', 'Pro')->firstOrFail();

        $suscripcion = Suscripcion::withoutGlobalScopes()->create([
            'distribuidora_id' => $this->distribuidoraId(),
            'plan_id' => $pro->id,
            'fecha_inicio' => now()->toDateString(),
            'fecha_fin' => now()->addMonth()->toDateString(),
            'estado' => 'cancelada',
            'precio_base_contratado' => $pro->precio_base_mensual,
            'lineas_incluidas_contratadas' => $pro->lineas_incluidas,
            'precio_linea_extra_contratado' => $pro->precio_linea_extra,
            'lineas_extra_contratadas' => 0,
        ])->fresh();

        $this->assertSame(50, $suscripcion->revendedores_incluidos_contratados);
        $this->assertEqualsWithDelta(10.0, (float) $suscripcion->precio_revendedor_extra_contratado, 0.001);
        $this->assertSame(0, $suscripcion->revendedores_extra_contratados);
    }

    // ------------------------------------------------------------------
    // Límite y aviso
    // ------------------------------------------------------------------

    public function test_dentro_del_limite_se_agrega_sin_cargo(): void
    {
        $this->conLimite($this->activos() + 1);

        $this->afiliar('Cabe en el plan');

        $this->assertSame(0, $this->suscripcion()->revendedores_extra_contratados);
        $this->assertSame(0, CrearPagoSuscripcionMercadoPagoAction::monto($this->suscripcion())['revendedores_extra']);
    }

    public function test_pasarse_sin_aceptar_avisa_el_costo_y_no_lo_agrega(): void
    {
        $this->conLimite($incluidos = $this->activos());
        $antes = $this->activos();

        try {
            $this->afiliar('De más');
            $this->fail('Agregó un revendedor por encima del plan sin aceptar el cargo.');
        } catch (CargoRevendedorExtraException $e) {
            $this->assertStringContainsString("Tu plan incluye {$incluidos} cliente(s) mayorista(s)", $e->getMessage());
            $this->assertStringContainsString('$10.00 MXN más al mes', $e->getMessage());
        }

        $this->assertSame($antes, $this->activos());
        $this->assertSame(0, $this->suscripcion()->revendedores_extra_contratados);
    }

    public function test_aceptando_el_cargo_se_agrega_y_se_suma_a_la_mensualidad(): void
    {
        $this->conLimite($this->activos());
        $base = (float) $this->suscripcion()->precio_base_contratado;
        $totalAntes = CrearPagoSuscripcionMercadoPagoAction::monto($this->suscripcion())['total'];

        $this->afiliar('Extra aceptado', acepta: true);

        $suscripcion = $this->suscripcion();
        $monto = CrearPagoSuscripcionMercadoPagoAction::monto($suscripcion);

        $this->assertSame(1, $suscripcion->revendedores_extra_contratados);
        $this->assertSame(1, $monto['revendedores_extra']);
        $this->assertEqualsWithDelta(10.0, $monto['precio_revendedor_extra'], 0.001);
        $this->assertEqualsWithDelta($totalAntes + 10, $monto['total'], 0.001);
        $this->assertGreaterThan($base, $monto['total']);
        $this->assertEqualsWithDelta($monto['total'], app(CobroSuscripcion::class)->montoDelSiguienteCobro(), 0.001);

        $this->assertTrue(Auditoria::withoutGlobalScopes()->where('accion', 'revendedor_extra.contratado')->exists());
    }

    public function test_el_conteo_resume_activos_incluidos_y_extras(): void
    {
        $this->conLimite($this->activos());
        $this->afiliar('Uno de más', acepta: true);

        $resumen = app(CupoRevendedoresDistribuidora::class)->resumen($this->distribuidoraId());

        $this->assertSame($this->activos(), $resumen['activos']);
        $this->assertSame($this->activos() - 1, $resumen['incluidos']);
        $this->assertSame(0, $resumen['disponibles']);
        $this->assertSame(1, $resumen['extras_cobrados']);
        $this->assertSame(0, $resumen['sin_cargo']);
        $this->assertTrue($resumen['siguiente_es_extra']);
    }

    // ------------------------------------------------------------------
    // Las que ya tenían más que el límite
    // ------------------------------------------------------------------

    public function test_quien_ya_tenia_mas_no_pierde_a_nadie_ni_paga_por_ellos(): void
    {
        $this->afiliar('Antiguo uno');
        $this->afiliar('Antiguo dos');
        $activos = $this->activos();

        // El límite llega después: ya tiene 2 por encima.
        $this->conLimite($activos - 2);

        $this->assertSame($activos, $this->activos());
        $resumen = app(CupoRevendedoresDistribuidora::class)->resumen($this->distribuidoraId());
        $this->assertSame(2, $resumen['sin_cargo']);
        $this->assertSame(0, $resumen['extras_cobrados']);
        $this->assertSame(0, CrearPagoSuscripcionMercadoPagoAction::monto($this->suscripcion())['revendedores_extra']);

        // Para agregar otro sí tiene que aceptar el cargo, y solo se cobra ese.
        $this->expectException(CargoRevendedorExtraException::class);

        try {
            $this->afiliar('Nuevo sin aceptar');
        } finally {
            $this->afiliar('Nuevo aceptado', acepta: true);
            $this->assertSame(1, CrearPagoSuscripcionMercadoPagoAction::monto($this->suscripcion())['revendedores_extra']);
        }
    }

    public function test_si_desactiva_revendedores_deja_de_pagar_por_ellos(): void
    {
        $this->conLimite($this->activos());
        $extra = $this->afiliar('Se va', acepta: true);
        $this->assertSame(1, CrearPagoSuscripcionMercadoPagoAction::monto($this->suscripcion())['revendedores_extra']);

        app(GestionarRevendedorAction::class)->actualizar($extra, ['estado' => 'inactivo']);

        $this->assertSame(0, CrearPagoSuscripcionMercadoPagoAction::monto($this->suscripcion())['revendedores_extra']);
        // Al renovar se vuelve a contar con lo que tiene hoy.
        $this->assertSame(0, app(CupoRevendedoresDistribuidora::class)->extrasParaRenovar($this->suscripcion()));
    }

    public function test_reactivar_por_encima_del_limite_tambien_pide_aceptar(): void
    {
        $dormido = $this->afiliar('Dormido');
        app(GestionarRevendedorAction::class)->actualizar($dormido, ['estado' => 'inactivo']);
        $this->conLimite($this->activos());

        try {
            app(GestionarRevendedorAction::class)->actualizar($dormido->fresh(), ['estado' => 'activo']);
            $this->fail('Reactivó por encima del plan sin aceptar el cargo.');
        } catch (CargoRevendedorExtraException) {
            $this->assertSame('inactivo', $dormido->fresh()->estado);
        }

        app(GestionarRevendedorAction::class)->actualizar($dormido->fresh(), ['estado' => 'activo'], true);
        $this->assertSame('activo', $dormido->fresh()->estado);
        $this->assertSame(1, $this->suscripcion()->revendedores_extra_contratados);
    }

    // ------------------------------------------------------------------
    // API y pantalla
    // ------------------------------------------------------------------

    public function test_la_api_responde_409_con_el_costo_y_acepta_el_cargo(): void
    {
        $this->conLimite($this->activos());
        Sanctum::actingAs($this->admin);
        Tenant::olvidarCache();

        $this->postJson('/api/distribuidora/revendedores', ['nombre' => 'Por API'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '$10.00 MXN más al mes'));

        $this->postJson('/api/distribuidora/revendedores', ['nombre' => 'Por API', 'acepta_cargo_extra' => true])
            ->assertSuccessful();

        $this->assertSame(1, $this->suscripcion()->revendedores_extra_contratados);
    }

    public function test_la_pantalla_muestra_el_conteo_y_pide_aceptar_el_cargo(): void
    {
        $this->conLimite($incluidos = $this->activos());

        $pantalla = Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->assertSeeHtml('data-cupo-revendedores')
            ->assertSee("{$incluidos} de {$incluidos}")
            ->call('abrirFormularioAfiliarRevendedor')
            ->assertSeeHtml('data-aviso-cargo-revendedor')
            ->assertSee('$10.00 MXN más al mes')
            ->set('revendedor_nombre', 'Desde la pantalla')
            ->call('guardarRevendedor')
            ->assertHasErrors('revendedor_cargo');

        $this->assertSame($incluidos, $this->activos());

        $pantalla->set('aceptaCargoRevendedor', true)
            ->call('guardarRevendedor')
            ->assertHasNoErrors()
            ->assertSet('mostrandoFormularioRevendedor', false);

        $this->assertSame($incluidos + 1, $this->activos());
        $this->assertSame(1, $this->suscripcion()->revendedores_extra_contratados);
    }

    public function test_dentro_del_limite_la_pantalla_no_pide_nada(): void
    {
        $this->conLimite($this->activos() + 5);

        Livewire::test('distribuidora.usuarios', ['seccion' => 'mayoristas'])
            ->assertSee('Te quedan 5.')
            ->call('abrirFormularioAfiliarRevendedor')
            ->assertDontSeeHtml('data-aviso-cargo-revendedor')
            ->set('revendedor_nombre', 'Sin cargo')
            ->call('guardarRevendedor')
            ->assertHasNoErrors();

        $this->assertSame(0, $this->suscripcion()->revendedores_extra_contratados);
    }

    // ------------------------------------------------------------------
    // Admin general: configurable por plan
    // ------------------------------------------------------------------

    public function test_el_admin_general_configura_el_limite_del_plan(): void
    {
        $admin = Usuario::firstOrCreate(
            ['email' => \Database\Seeders\AdminGeneralSeeder::EMAIL],
            ['nombre' => 'Admin General', 'password' => \Illuminate\Support\Facades\Hash::make('password'), 'estado' => 'activo']
        );
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(0);
        $admin->assignRole('admin_general');
        $registrar->forgetCachedPermissions();
        $this->actingAs($admin);
        $pro = PlanSuscripcion::where('nombre', 'Pro')->firstOrFail();

        Livewire::test('admin.planes-index')
            ->call('editar', $pro->id)
            ->assertSet('revendedores_incluidos', '50')
            ->set('revendedores_incluidos', '60')
            ->set('precio_revendedor_extra', '12.5')
            ->call('guardar')
            ->assertHasNoErrors();

        $pro->refresh();
        $this->assertSame(60, $pro->revendedores_incluidos);
        $this->assertEqualsWithDelta(12.5, (float) $pro->precio_revendedor_extra, 0.001);

        Livewire::test('admin.planes-index')
            ->call('editar', $pro->id)
            ->set('revendedores_incluidos', '')
            ->call('guardar')
            ->assertHasErrors(['revendedores_incluidos' => 'required']);
    }
}
