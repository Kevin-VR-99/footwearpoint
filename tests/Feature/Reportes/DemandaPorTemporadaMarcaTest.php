<?php

namespace Tests\Feature\Reportes;

use App\Models\Campana;
use App\Models\CategoriaProducto;
use App\Models\CicloCompra;
use App\Models\Distribuidora;
use App\Models\DistribuidoraLinea;
use App\Models\DistribuidoraStaff;
use App\Models\Linea;
use App\Models\Marca;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\RevendedorDistribuidora;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Variante;
use App\Services\Reporte\DemandaPorTemporadaMarcaAction;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TG-253 (R11, E14-04) — Demanda por temporada (campaña) y marca: piezas
 * pedidas, ordenadas por piezas y con su monto. Solo pedidos; los no
 * surtidos cuentan.
 */
class DemandaPorTemporadaMarcaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    private const EMPLEADO = 'empleado@calzadosramirez.test';

    private Campana $temporadaA;

    private Campana $temporadaB;

    private Marca $marcaX;

    private Marca $marcaY;

    protected function setUp(): void
    {
        parent::setUp();

        $this->como(self::ADMIN);
        // Partimos de cero: el seeder demo puede traer pedidos.
        Pedido::query()->update(['estado' => 'borrador', 'fecha_colocacion' => null]);

        $linea = Linea::create(['nombre' => 'Línea Demanda', 'activa' => true]);
        DistribuidoraLinea::query()->create([
            'distribuidora_id' => Tenant::id(), 'linea_id' => $linea->id,
            'es_extra' => false, 'activa' => true, 'fecha_activacion' => now(),
        ]);
        $this->temporadaA = Campana::create(['linea_id' => $linea->id, 'nombre' => 'Primavera Demanda', 'estado' => 'activa']);
        $this->temporadaB = Campana::create(['linea_id' => $linea->id, 'nombre' => 'Otoño Demanda', 'estado' => 'borrador']);
        $this->marcaX = Marca::create(['nombre' => 'Marca Equis']);
        $this->marcaY = Marca::create(['nombre' => 'Marca Ye']);
    }

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

    private function publicacion(Marca $marca, Campana $temporada, string $nombre): ProductoCampana
    {
        $producto = Producto::create([
            'marca_id' => $marca->id,
            'categoria_id' => CategoriaProducto::firstOrFail()->id,
            'modelo' => 'DEM-'.uniqid(),
            'nombre' => $nombre,
            'activo' => true,
        ]);

        return ProductoCampana::create([
            'producto_id' => $producto->id,
            'campana_id' => $temporada->id,
            'codigo_catalogo' => 'DEM-'.uniqid(),
            'precio_publico' => 100,
            'activo' => true,
        ]);
    }

    /** @param array<int, array{0: ProductoCampana, 1: int}> $lineas */
    private function pedido(array $lineas, string $estado = 'colocado', string $fecha = '2026-09-15 12:00:00', ?int $ciclo = null): Pedido
    {
        $pedido = Pedido::create([
            'distribuidora_id' => Tenant::id(),
            'sucursal_id' => Sucursal::query()->value('id'),
            'folio' => 'DEM-'.uniqid(),
            'tipo' => 'revendedor',
            'revendedor_distribuidora_id' => RevendedorDistribuidora::query()->value('id'),
            'ciclo_compra_id' => $ciclo,
            'estado' => $estado,
            'subtotal' => 0,
            'total' => 0,
            'fecha_colocacion' => $estado === 'borrador' ? null : $fecha,
            'capturado_por_staff_id' => (int) DistribuidoraStaff::query()->value('id'),
        ]);

        foreach ($lineas as [$pc, $cantidad]) {
            PedidoDetalle::query()->forceCreate([
                'distribuidora_id' => Tenant::id(), 'pedido_id' => $pedido->id,
                'producto_campana_id' => $pc->id, 'variante_id' => Variante::query()->value('id'),
                'producto_nombre' => 'x', 'modelo' => 'x', 'talla' => '25', 'color' => 'Negro',
                'cantidad' => $cantidad, 'precio_unitario' => 100, 'subtotal' => 100 * $cantidad,
            ]);
        }

        return $pedido;
    }

    private function reporte(...$args): array
    {
        return app(DemandaPorTemporadaMarcaAction::class)->ejecutar(...$args);
    }

    public function test_suma_piezas_y_monto_por_marca_producto_y_temporada_ordenado_por_piezas(): void
    {
        $x1 = $this->publicacion($this->marcaX, $this->temporadaA, 'Bota X1');
        $y1 = $this->publicacion($this->marcaY, $this->temporadaA, 'Tenis Y1');
        $y2 = $this->publicacion($this->marcaY, $this->temporadaB, 'Sandalia Y2');
        $this->pedido([[$x1, 2], [$y1, 3]]);
        $this->pedido([[$y2, 4]]);

        $r = $this->reporte();

        $this->assertSame([$this->marcaY->id, $this->marcaX->id], array_column($r['por_marca'], 'id'));
        $this->assertSame(7, $r['por_marca'][0]['piezas']);
        $this->assertSame(2, $r['por_marca'][0]['modelos']);
        $this->assertSame(2, $r['por_marca'][0]['pedidos']);
        $this->assertEqualsWithDelta(700, $r['por_marca'][0]['monto'], 0.001);
        $this->assertSame(['Sandalia Y2', 'Tenis Y1', 'Bota X1'], array_column($r['productos'], 'producto'));
        $this->assertSame('Otoño Demanda', $r['productos'][0]['temporada']);
        $this->assertSame([$this->temporadaA->id, $this->temporadaB->id], array_column($r['por_temporada'], 'id'));
        $this->assertSame(['piezas' => 9, 'pedidos' => 2, 'monto' => 900.0], $r['totales']);
    }

    public function test_filtra_por_temporada_y_por_marca_combinados(): void
    {
        $x1 = $this->publicacion($this->marcaX, $this->temporadaA, 'Bota X1');
        $y1 = $this->publicacion($this->marcaY, $this->temporadaA, 'Tenis Y1');
        $y2 = $this->publicacion($this->marcaY, $this->temporadaB, 'Sandalia Y2');
        $this->pedido([[$x1, 2], [$y1, 3], [$y2, 4]]);

        $this->assertSame(5, $this->reporte($this->temporadaA->id)['totales']['piezas']);
        $this->assertSame(7, $this->reporte(null, $this->marcaY->id)['totales']['piezas']);
        $combinado = $this->reporte($this->temporadaA->id, $this->marcaY->id);
        $this->assertSame(['Tenis Y1'], array_column($combinado['productos'], 'producto'));
        $this->assertSame(3, $combinado['totales']['piezas']);
    }

    public function test_filtra_por_fecha_de_colocacion_y_por_ciclo(): void
    {
        $x1 = $this->publicacion($this->marcaX, $this->temporadaA, 'Bota X1');
        Livewire::test('ciclo.index');
        $ciclo = CicloCompra::query()->firstOrFail();
        $this->pedido([[$x1, 1]], fecha: '2026-09-01 00:10:00');
        $this->pedido([[$x1, 2]], fecha: '2026-09-30 23:50:00', ciclo: $ciclo->id);
        $this->pedido([[$x1, 4]], fecha: '2026-10-01 08:00:00');

        $this->assertSame(3, $this->reporte(null, null, '2026-09-01', '2026-09-30')['totales']['piezas']);
        $this->assertSame(2, $this->reporte(null, null, null, null, $ciclo->id)['totales']['piezas']);
    }

    public function test_excluye_borradores_y_cerrados_pero_cuenta_los_no_surtidos(): void
    {
        $x1 = $this->publicacion($this->marcaX, $this->temporadaA, 'Bota X1');
        $this->pedido([[$x1, 1]], 'entregado');
        $this->pedido([[$x1, 2]], 'no_surtido');
        foreach (['borrador', 'rechazado', 'descartado', 'vencido_recoleccion'] as $estado) {
            $this->pedido([[$x1, 10]], $estado);
        }

        $r = $this->reporte();
        $this->assertSame(3, $r['totales']['piezas']);
        $this->assertSame(2, $r['totales']['pedidos']);
    }

    public function test_productos_mas_solicitados_se_limitan_a_20(): void
    {
        $lineas = [];
        for ($i = 1; $i <= 22; $i++) {
            $lineas[] = [$this->publicacion($this->marcaX, $this->temporadaA, sprintf('Modelo %02d', $i)), $i];
        }
        $this->pedido($lineas);

        $productos = $this->reporte()['productos'];
        $this->assertCount(DemandaPorTemporadaMarcaAction::TOP_PRODUCTOS, $productos);
        $this->assertSame('Modelo 22', $productos[0]['producto']);
    }

    public function test_otra_distribuidora_no_ve_la_demanda_aunque_el_catalogo_sea_compartido(): void
    {
        $this->pedido([[$this->publicacion($this->marcaX, $this->temporadaA, 'Bota X1'), 5]]);
        $otra = Distribuidora::create([
            'nombre_comercial' => 'Otra DEM', 'slug' => 'otra-dem-'.uniqid(), 'estado' => 'activa',
            'fecha_solicitud' => now(), 'fecha_aprobacion' => now(),
        ]);

        Tenant::forzar($otra->id, function () {
            $r = $this->reporte();
            $this->assertSame([], $r['por_marca']);
            $this->assertSame(0, $r['totales']['piezas']);
        });
    }

    public function test_la_pantalla_muestra_la_seccion_y_filtra(): void
    {
        $x1 = $this->publicacion($this->marcaX, $this->temporadaA, 'Bota X1');
        $y2 = $this->publicacion($this->marcaY, $this->temporadaB, 'Sandalia Y2');
        $this->pedido([[$x1, 2], [$y2, 3]]);

        $this->get(route('reportes.index'))->assertOk()
            ->assertSee('Demanda por temporada y marca')
            ->assertSee('Productos más solicitados')
            ->assertSee('Primavera Demanda')
            ->assertSee('Marca Equis');

        Livewire::test('reportes.index')
            ->set('demandaMarcaId', (string) $this->marcaX->id)
            ->assertSee('Bota X1')
            ->assertDontSee('Sandalia Y2')
            ->assertSet('errorDemanda', '');
    }

    public function test_el_empleado_sin_permiso_recibe_403_en_pantalla_y_api(): void
    {
        $usuario = $this->como(self::EMPLEADO);
        $this->get(route('reportes.index'))->assertForbidden();

        Sanctum::actingAs($usuario);
        $this->getJson('/api/reportes/demanda')->assertForbidden();
    }

    public function test_el_empleado_con_permiso_y_el_admin_usan_el_endpoint(): void
    {
        $x1 = $this->publicacion($this->marcaX, $this->temporadaA, 'Bota X1');
        $this->pedido([[$x1, 6]]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(Tenant::id());
        Usuario::where('email', self::EMPLEADO)->firstOrFail()->givePermissionTo('reportes.ver_financieros');
        $registrar->forgetCachedPermissions();

        foreach ([self::EMPLEADO, self::ADMIN] as $email) {
            Sanctum::actingAs($this->como($email));

            $this->getJson('/api/reportes/demanda?marca_id='.$this->marcaX->id.'&campana_id='.$this->temporadaA->id)
                ->assertOk()
                ->assertJsonPath('data.totales.piezas', 6)
                ->assertJsonPath('data.por_marca.0.nombre', 'Marca Equis')
                ->assertJsonPath('data.productos.0.producto', 'Bota X1')
                ->assertJsonPath('data.filtros.marca_id', $this->marcaX->id);
        }
    }

    public function test_el_endpoint_valida_los_parametros(): void
    {
        Sanctum::actingAs(Usuario::where('email', self::ADMIN)->firstOrFail());

        $this->getJson('/api/reportes/demanda?desde=2026-09-30&hasta=2026-09-01')
            ->assertStatus(422)
            ->assertJsonPath('message', 'La fecha "hasta" no puede ser anterior a "desde".');
        $this->getJson('/api/reportes/demanda?marca_id=abc')->assertStatus(422);
    }
}
