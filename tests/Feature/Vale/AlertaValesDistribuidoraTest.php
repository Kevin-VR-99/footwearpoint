<?php

namespace Tests\Feature\Vale;

use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\Notificacion;
use App\Models\Usuario;
use App\Models\Vale;
use App\Services\Notificacion\Push\EnviadorPush;
use App\Services\Vale\ResumenValesPorVencerAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * TG-122 — La distribuidora también se entera de los vales por vencer:
 * resumen diario a su personal, insignia y filtro en el panel, y
 * «por_vencer» en la API para la app. Extiende el aviso al cliente de TG-242.
 */
class AlertaValesDistribuidoraTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const JOSE = 'jose.hernandez@cliente.test';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 10:00:00');
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        config(['vales.dias_aviso_vencimiento' => 7]);
        $this->app->instance(EnviadorPush::class, Mockery::mock(EnviadorPush::class)->shouldIgnoreMissing([]));

        // Los vales de la demo, lejos de vencer: cada prueba pone los suyos.
        Vale::withoutGlobalScopes()->update(['fecha_vencimiento' => now()->addYear()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function jose(): ClienteDirecto
    {
        return ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', self::JOSE)->value('id'))
            ->firstOrFail();
    }

    /** Un cliente de la misma distribuidora que José, sin cuenta en la app. */
    private function sinApp(): ClienteDirecto
    {
        return ClienteDirecto::withoutGlobalScopes()
            ->where('distribuidora_id', $this->jose()->distribuidora_id)
            ->whereNull('usuario_id')
            ->firstOrFail();
    }

    private function vale(ClienteDirecto $cliente, int $dias, string $folio, float $saldo = 300.0): Vale
    {
        return Vale::withoutGlobalScopes()->create([
            'distribuidora_id'    => $cliente->distribuidora_id,
            'cliente_directo_id'  => $cliente->id,
            'folio'               => $folio,
            'monto_original'      => 300.0,
            'saldo_actual'        => $saldo,
            'fecha_emision'       => now()->subDays(80),
            'fecha_vencimiento'   => now()->addDays($dias),
            'estado'              => 'activo',
            'creado_por_staff_id' => 1,
        ]);
    }

    private function staffDe(int $distribuidoraId): array
    {
        return DistribuidoraStaff::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('estado', 'activo')
            ->whereNotNull('usuario_id')
            ->pluck('usuario_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    private function resumenes()
    {
        return Notificacion::withoutGlobalScopes()->where('tipo', ResumenValesPorVencerAction::TIPO)->get();
    }

    // ------------------------------------------------------------------
    // Resumen al personal
    // ------------------------------------------------------------------

    public function test_el_personal_recibe_un_resumen_con_los_clientes_sin_app(): void
    {
        $sinApp = $this->sinApp();
        $this->vale($this->jose(), 3, 'V-JOSE');
        $this->vale($sinApp, 5, 'V-LLAMAR');
        $this->vale($this->jose(), 30, 'V-LEJOS');

        $this->artisan('vales:avisar-por-vencer')
            ->expectsOutputToContain('Avisados: 1')
            ->expectsOutputToContain('Resumen al personal: 1 distribuidoras')
            ->assertSuccessful();

        $resumenes = $this->resumenes();
        $staff = $this->staffDe((int) $this->jose()->distribuidora_id);

        $this->assertNotEmpty($staff);
        $this->assertEqualsCanonicalizing($staff, $resumenes->pluck('usuario_id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue($resumenes->every(fn ($n) => (int) $n->distribuidora_id === (int) $this->jose()->distribuidora_id));

        $aviso = $resumenes->first();
        $this->assertSame('2 vales vencen en los próximos 7 días', $aviso->titulo);
        $this->assertSame("1 cliente sin app (llámalos): V-LLAMAR {$sinApp->nombre}.", $aviso->mensaje);
    }

    public function test_si_todos_tienen_app_lo_dice(): void
    {
        $this->vale($this->jose(), 3, 'V-JOSE');

        app(ResumenValesPorVencerAction::class)->ejecutar();

        $this->assertSame('1 vale vence en los próximos 7 días', $this->resumenes()->first()->titulo);
        $this->assertSame('Todos tienen la app y ya recibieron su aviso.', $this->resumenes()->first()->mensaje);
    }

    public function test_sin_vales_por_vencer_no_hay_resumen(): void
    {
        $this->vale($this->jose(), 30, 'V-LEJOS');
        $this->vale($this->jose(), 3, 'V-SIN-SALDO', 0);

        $this->assertSame(['distribuidoras' => 0, 'avisos' => 0], app(ResumenValesPorVencerAction::class)->ejecutar());
        $this->assertCount(0, $this->resumenes());
    }

    public function test_solo_un_resumen_al_dia_y_al_dia_siguiente_otro(): void
    {
        $this->vale($this->sinApp(), 5, 'V-LLAMAR');
        $staff = count($this->staffDe((int) $this->jose()->distribuidora_id));

        $this->artisan('vales:avisar-por-vencer')->assertSuccessful();
        $this->artisan('vales:avisar-por-vencer')
            ->expectsOutputToContain('Resumen al personal: 0 distribuidoras, 0 avisos.')
            ->assertSuccessful();
        $this->assertCount($staff, $this->resumenes());

        // Al otro día, el panorama de ese día.
        Carbon::setTestNow('2026-10-11 10:00:00');
        $this->artisan('vales:avisar-por-vencer')->assertSuccessful();
        $this->assertCount($staff * 2, $this->resumenes());
    }

    public function test_muchos_clientes_sin_app_se_resumen_con_y_n_mas(): void
    {
        $sinApp = $this->sinApp();
        for ($i = 1; $i <= 12; $i++) {
            $this->vale($sinApp, 3, sprintf('V-%02d', $i));
        }

        app(ResumenValesPorVencerAction::class)->ejecutar();

        $mensaje = $this->resumenes()->first()->mensaje;
        $this->assertStringStartsWith('12 clientes sin app (llámalos): V-01', $mensaje);
        $this->assertStringEndsWith(' y 2 más.', $mensaje);
        $this->assertStringNotContainsString('V-11', $mensaje);
    }

    // ------------------------------------------------------------------
    // Panel y API
    // ------------------------------------------------------------------

    public function test_el_panel_marca_y_filtra_los_vales_por_vencer(): void
    {
        $this->vale($this->jose(), 3, 'V-PRONTO');
        $this->vale($this->jose(), 30, 'V-LEJOS');

        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();

        $pantalla = Livewire::test('vales.index')
            ->assertSee('Por vencer (7 días o menos)')
            ->assertSee('V-PRONTO')
            ->assertSee('V-LEJOS');
        // Una vez en el filtro y otra en la insignia de V-PRONTO.
        $this->assertSame(2, substr_count($pantalla->html(), 'Por vencer'));

        $pantalla->set('filtro_tipo', 'por_vencer')
            ->assertSee('V-PRONTO')
            ->assertDontSee('V-LEJOS');
    }

    public function test_la_api_dice_si_el_vale_esta_por_vencer(): void
    {
        $this->vale($this->jose(), 3, 'V-PRONTO');
        $this->vale($this->jose(), 30, 'V-LEJOS');

        Sanctum::actingAs(Usuario::where('email', self::JOSE)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $vales = collect($this->getJson('/api/vales')->assertOk()->json('data'))->keyBy('folio');

        $this->assertTrue($vales['V-PRONTO']['por_vencer']);
        $this->assertFalse($vales['V-LEJOS']['por_vencer']);
    }
}
