<?php

namespace Tests\Feature\Pedido;

use App\Models\Auditoria;
use App\Models\ClienteDirecto;
use App\Models\DistribuidoraStaff;
use App\Models\HistorialEstadoPedido;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Services\Pedido\VencerRecoleccionAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TG-125 (E13-02) — Los pedidos listos que no se recogen a tiempo pasan solos
 * a «vencido_recoleccion», y el cliente sabe hasta cuándo puede recoger.
 */
class VencerRecoleccionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const JOSE = 'jose.hernandez@cliente.test';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 10:00:00');
        $this->comoAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function comoAdmin(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(Usuario::where('email', 'admin@calzadosramirez.test')->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    /** Como corre el cron: sin nadie con sesión. */
    private function sinSesion(): void
    {
        Auth::logout();
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function joseCliente(): ClienteDirecto
    {
        return ClienteDirecto::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', self::JOSE)->value('id'))
            ->firstOrFail();
    }

    private function pedido(string $estado, ?string $limite): Pedido
    {
        return Pedido::create([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'REC-'.uniqid(),
            'tipo' => 'cliente_directo',
            'cliente_directo_id' => $this->joseCliente()->id,
            'estado' => $estado,
            'subtotal' => 500,
            'total' => 500,
            'fecha_colocacion' => '2026-10-01 10:00:00',
            'fecha_limite_recoleccion' => $limite,
            'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
        ]);
    }

    private function estado(Pedido $pedido): string
    {
        return Pedido::withoutGlobalScopes()->findOrFail($pedido->id)->estado;
    }

    // ------------------------------------------------------------------
    // El comando
    // ------------------------------------------------------------------

    public function test_vence_solo_los_pedidos_listos_con_el_plazo_pasado(): void
    {
        $vencido = $this->pedido('listo_entrega', '2026-10-09 18:00:00');
        $aTiempo = $this->pedido('listo_entrega', '2026-10-12 18:00:00');
        $sinLimite = $this->pedido('listo_entrega', null);
        $entregado = $this->pedido('entregado', '2026-10-01 18:00:00');
        $this->sinSesion();

        $this->artisan('pedidos:vencer-recoleccion')
            ->expectsOutputToContain('Marcados como vencidos: 1')
            ->assertSuccessful();

        $this->assertSame('vencido_recoleccion', $this->estado($vencido));
        $this->assertSame('listo_entrega', $this->estado($aTiempo));
        $this->assertSame('listo_entrega', $this->estado($sinLimite));
        $this->assertSame('entregado', $this->estado($entregado));
    }

    public function test_deja_historial_y_auditoria_con_la_distribuidora_del_pedido(): void
    {
        $pedido = $this->pedido('listo_entrega', '2026-10-09 18:00:00');
        $this->sinSesion();

        $this->artisan('pedidos:vencer-recoleccion')->assertSuccessful();

        $historial = HistorialEstadoPedido::withoutGlobalScopes()->where('pedido_id', $pedido->id)->sole();
        $this->assertSame('listo_entrega', $historial->estado_anterior);
        $this->assertSame('vencido_recoleccion', $historial->estado_nuevo);
        $this->assertSame(VencerRecoleccionAction::COMENTARIO, $historial->comentario);
        $this->assertNull($historial->cambiado_por_staff_id);
        $this->assertSame((int) $pedido->distribuidora_id, (int) $historial->distribuidora_id);

        $auditoria = Auditoria::withoutGlobalScopes()
            ->where('accion', 'pedido.cambio_estado')
            ->where('entidad_id', $pedido->id)
            ->sole();
        $this->assertSame((int) $pedido->distribuidora_id, (int) $auditoria->distribuidora_id);
        $this->assertNull($auditoria->usuario_id);
    }

    public function test_avisa_al_personal_de_la_distribuidora(): void
    {
        $pedido = $this->pedido('listo_entrega', '2026-10-09 18:00:00');
        $staff = DistribuidoraStaff::withoutGlobalScopes()
            ->where('distribuidora_id', $pedido->distribuidora_id)
            ->where('estado', 'activo')
            ->whereNotNull('usuario_id')
            ->pluck('usuario_id');
        $this->sinSesion();

        $this->artisan('pedidos:vencer-recoleccion')->assertSuccessful();

        $avisos = Notificacion::withoutGlobalScopes()
            ->where('entidad_tipo', 'pedido')
            ->where('entidad_id', $pedido->id)
            ->where('tipo', 'pedido_estado')
            ->get();

        $this->assertNotEmpty($staff);
        $this->assertEqualsCanonicalizing($staff->all(), $avisos->pluck('usuario_id')->all());
        $this->assertSame("Pedido {$pedido->folio}: venció el plazo de recolección", $avisos->first()->titulo);
        $this->assertStringContainsString('antes del 09/10/2026', $avisos->first()->mensaje);
    }

    public function test_correrlo_dos_veces_no_repite_nada(): void
    {
        $pedido = $this->pedido('listo_entrega', '2026-10-09 18:00:00');
        $this->sinSesion();

        $this->artisan('pedidos:vencer-recoleccion')->assertSuccessful();
        $this->artisan('pedidos:vencer-recoleccion')
            ->expectsOutputToContain('Pedidos con plazo vencido: 0. Marcados como vencidos: 0.')
            ->assertSuccessful();

        $this->assertSame(1, HistorialEstadoPedido::withoutGlobalScopes()->where('pedido_id', $pedido->id)->count());
    }

    public function test_dry_run_solo_lista_sin_cambiar_nada(): void
    {
        $pedido = $this->pedido('listo_entrega', '2026-10-09 18:00:00');
        $this->sinSesion();

        $this->artisan('pedidos:vencer-recoleccion --dry-run')
            ->expectsOutputToContain('Simulación: 1 pedidos vencerían.')
            ->expectsOutputToContain($pedido->folio)
            ->assertSuccessful();

        $this->assertSame('listo_entrega', $this->estado($pedido));
        $this->assertSame(0, HistorialEstadoPedido::withoutGlobalScopes()->where('pedido_id', $pedido->id)->count());
    }

    // ------------------------------------------------------------------
    // Lo que ve el cliente
    // ------------------------------------------------------------------

    public function test_la_api_del_pedido_trae_la_fecha_limite_de_recoleccion(): void
    {
        $pedido = $this->pedido('listo_entrega', '2026-10-15 18:00:00');
        $sinLimite = $this->pedido('colocado', null);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Usuario::where('email', self::JOSE)->firstOrFail());
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();

        $fecha = $this->getJson("/api/pedidos/{$pedido->id}")->assertOk()->json('data.fecha_limite_recoleccion');
        $this->assertTrue(Carbon::parse($fecha)->equalTo(Carbon::parse('2026-10-15 18:00:00')));

        $this->getJson("/api/pedidos/{$sinLimite->id}")
            ->assertOk()
            ->assertJsonPath('data.fecha_limite_recoleccion', null);
    }

    public function test_el_aviso_de_listo_para_entrega_dice_hasta_cuando_recogerlo(): void
    {
        $pedido = $this->pedido('recibido_distribuidora', null);

        Livewire::test('pedidos.show', ['id' => $pedido->id])
            ->call('marcarListo')
            ->assertSet('errorMsg', '');

        // La demo da 5 días: 10/10 + 5 = 15/10.
        $aviso = Notificacion::withoutGlobalScopes()
            ->where('usuario_id', Usuario::where('email', self::JOSE)->value('id'))
            ->where('tipo', 'pedido_llegada')
            ->where('entidad_id', $pedido->id)
            ->sole();

        $this->assertStringContainsString('Recógelo antes del 15/10/2026.', $aviso->mensaje);
    }

    // ------------------------------------------------------------------
    // Panel
    // ------------------------------------------------------------------

    public function test_la_lista_del_panel_marca_por_vencer_y_vencida(): void
    {
        $porVencer = $this->pedido('listo_entrega', '2026-10-11 09:00:00');
        $vencida = $this->pedido('listo_entrega', '2026-10-09 09:00:00');
        $aTiempo = $this->pedido('listo_entrega', '2026-10-20 09:00:00');

        $this->assertSame('por_vencer', $porVencer->alertaRecoleccion());
        $this->assertSame('vencida', $vencida->alertaRecoleccion());
        $this->assertNull($aTiempo->alertaRecoleccion());
        $this->assertNull($this->pedido('entregado', '2026-10-09 09:00:00')->alertaRecoleccion());

        $html = Livewire::test('pedidos.index')->assertSee('Por vencer')->html();

        // Una vez en el filtro de estados y otra en la insignia del pedido.
        $this->assertSame(2, substr_count($html, 'Recolección vencida'));
    }
}
