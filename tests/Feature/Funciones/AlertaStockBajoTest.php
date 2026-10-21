<?php

namespace Tests\Feature\Funciones;

use App\Models\ConfiguracionDistribuidora;
use App\Models\Distribuidora;
use App\Models\Notificacion;
use App\Models\StockLocal;
use App\Models\Usuario;
use App\Services\Stock\AlertaStockBajoService;
use App\Services\Stock\StockService;
use App\Services\VentaDirecta\RegistrarVentaDirectaService;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Alerta de stock bajo por variante: mínimo general y propio, campana sin repetir, badge y filtro. */
class AlertaStockBajoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function como(string $email): Usuario
    {
        $this->app['auth']->forgetGuards();
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
        $usuario = Usuario::where('email', $email)->firstOrFail();
        $this->actingAs($usuario);
        Tenant::olvidarCache();

        return $usuario;
    }

    private function alertas(): int
    {
        return Notificacion::withoutGlobalScopes()->where('tipo', AlertaStockBajoService::TIPO)->count();
    }

    /** Una existencia con 5 piezas y mínimo propio 3 de la distribuidora actual. */
    private function existencia(int $cantidad = 5, int $minimo = 3): StockLocal
    {
        $stock = StockLocal::query()->orderBy('id')->firstOrFail();
        $stock->timestamps = false;
        $stock->forceFill(['cantidad_disponible' => $cantidad, 'stock_minimo' => $minimo, 'alerta_stock_bajo_at' => null])->save();
        Notificacion::withoutGlobalScopes()->where('tipo', AlertaStockBajoService::TIPO)->delete();

        return $stock->fresh();
    }

    public function test_al_bajar_del_minimo_por_ajuste_avisa_a_los_admins_una_sola_vez(): void
    {
        $this->como('admin@calzadosramirez.test');
        $stock = $this->existencia(5, 3);
        $admins = \App\Models\DistribuidoraStaff::query()->where('tipo', 'administrador')->where('estado', 'activo')->pluck('usuario_id');

        app(StockService::class)->registrarAjuste($stock->variante_id, 'ajuste_negativo', 3, 'Merma');
        $this->assertSame($admins->count(), $this->alertas());
        $this->assertDatabaseHas('notificaciones', ['tipo' => 'stock_bajo', 'usuario_id' => $admins->first(), 'entidad_id' => $stock->id]);

        // Sigue bajo: no se repite.
        app(StockService::class)->registrarAjuste($stock->variante_id, 'ajuste_negativo', 1, 'Merma');
        $this->assertSame($admins->count(), $this->alertas());

        // Se repone y vuelve a bajar: avisa otra vez.
        app(StockService::class)->registrarEntrada($stock->variante_id, 10);
        $this->assertNull($stock->fresh()->alerta_stock_bajo_at);
        app(StockService::class)->registrarAjuste($stock->variante_id, 'ajuste_negativo', 10, 'Merma');
        $this->assertSame($admins->count() * 2, $this->alertas());
    }

    public function test_el_empleado_no_recibe_la_alerta(): void
    {
        $empleado = Usuario::where('email', 'empleado@calzadosramirez.test')->firstOrFail();
        $this->como('admin@calzadosramirez.test');
        $stock = $this->existencia(5, 3);

        app(StockService::class)->registrarAjuste($stock->variante_id, 'ajuste_negativo', 4, 'Merma');

        $this->assertDatabaseMissing('notificaciones', ['tipo' => 'stock_bajo', 'usuario_id' => $empleado->id]);
    }

    public function test_usa_el_minimo_general_si_la_variante_no_tiene_propio(): void
    {
        $this->como('admin@calzadosramirez.test');
        $stock = $this->existencia(5, 0);
        app(StockService::class)->registrarAjuste($stock->variante_id, 'ajuste_negativo', 2, 'Merma');
        $this->assertSame(0, $this->alertas(), 'Sin mínimo general no avisa.');

        ConfiguracionDistribuidora::query()->update(['stock_minimo_general' => 4]);
        app(StockService::class)->registrarAjuste($stock->variante_id, 'ajuste_negativo', 1, 'Merma');
        $this->assertGreaterThan(0, $this->alertas());
    }

    public function test_una_venta_de_mostrador_tambien_dispara_la_alerta(): void
    {
        $this->como('empleado@calzadosramirez.test');
        ConfiguracionDistribuidora::query()->update(['stock_minimo_general' => 1000]);
        Notificacion::withoutGlobalScopes()->where('tipo', 'stock_bajo')->delete();
        StockLocal::query()->update(['alerta_stock_bajo_at' => null, 'stock_minimo' => 0]);

        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        app(RegistrarVentaDirectaService::class)->registrar([[
            'variante_id' => $renglon['variante_id'], 'producto_campana_id' => $renglon['producto_campana_id'], 'cantidad' => 1,
        ]], 'efectivo', null);

        $this->assertGreaterThan(0, $this->alertas());
    }

    public function test_la_configuracion_guarda_el_minimo_general(): void
    {
        $this->como('admin@calzadosramirez.test');

        Livewire::test('distribuidora.general')->set('stock_minimo_general', 6)->call('guardarConfiguracion')->assertHasNoErrors();

        $this->assertSame(6, (int) ConfiguracionDistribuidora::query()->value('stock_minimo_general'));
    }

    public function test_stock_local_muestra_badge_filtra_y_edita_el_minimo(): void
    {
        $this->como('admin@calzadosramirez.test');
        $bajo = $this->existencia(1, 3);

        Livewire::test('stock.index')
            ->assertSee('Stock bajo')
            ->set('soloBajo', true)
            ->assertSee($bajo->variante->sku)
            ->call('editarMinimo', $bajo->id)
            ->set('minimoNuevo', '0')
            ->call('guardarMinimo')
            ->assertHasNoErrors()
            ->assertSee('Ninguna variante está por debajo de su mínimo.');

        $this->assertSame(0, (int) $bajo->fresh()->stock_minimo);
        $this->assertDatabaseHas('auditorias', ['accion' => 'stock.minimo', 'entidad_id' => $bajo->id]);
    }

    public function test_el_empleado_sin_permiso_no_edita_el_minimo(): void
    {
        $this->como('admin@calzadosramirez.test');
        $stock = $this->existencia(5, 3);
        $this->como('empleado@calzadosramirez.test');

        Livewire::test('stock.index')->call('editarMinimo', $stock->id)->assertForbidden();
    }

    public function test_no_edita_existencias_de_otra_distribuidora(): void
    {
        $otra = Distribuidora::query()->where('slug', 'boutique-del-calzado')->firstOrFail();
        $ajena = StockLocal::withoutGlobalScopes()->where('distribuidora_id', $otra->id)->first();
        $this->como('admin@calzadosramirez.test');

        if ($ajena === null) {
            $this->markTestSkipped('El seeder no dejó stock en la otra distribuidora.');
        }

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('stock.index')->call('editarMinimo', $ajena->id);
    }
}
