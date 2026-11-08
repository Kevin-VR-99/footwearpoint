<?php

namespace Tests\Feature\Sprint5;

use App\Models\ClienteDirecto;
use App\Models\Distribuidora;
use App\Models\DistribuidoraStaff;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\ProductoCampana;
use App\Models\Sucursal;
use App\Models\Usuario;
use App\Models\Vale;
use App\Models\ValeDevolucionPartida;
use App\Models\ValeMovimiento;
use App\Models\Variante;
use App\Models\VentaDirecta;
use App\Services\Auth\PermisoModuloService;
use App\Services\Cambio\SolicitarCambioAction;
use App\Services\Distribuidora\EliminarDistribuidoraAction;
use App\Services\Pedido\RegistrarPagoPedidoAction;
use App\Services\Vale\AplicarValeAction;
use App\Services\Vale\BorrarValesPorOrigen;
use App\Services\Vale\EmitirNotaDevolucionAction;
use App\Services\VentaDirecta\RegistrarVentaDirectaService;
use App\Support\Centavos;
use App\Support\PropietarioActual;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** TG-356 (G33 / S5-74) — Nota de devolución, tipos de vale y canje con sobrante. */
class TG356NotaDevolucionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const ADMIN = 'admin@calzadosramirez.test';

    private const JOSE = 'jose.hernandez@cliente.test';

    private int $folio = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-07 12:00:00');
        $this->como(self::ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function como(string $email, bool $api = false): void
    {
        $this->app['auth']->forgetGuards();
        $u = Usuario::where('email', $email)->firstOrFail();
        $api ? Sanctum::actingAs($u) : $this->actingAs($u);
        Tenant::olvidarCache();
        PropietarioActual::olvidarCache();
    }

    private function jose(): int
    {
        return (int) ClienteDirecto::withoutGlobalScopes()->where('usuario_id', Usuario::where('email', self::JOSE)->value('id'))->value('id');
    }

    /** @param list<array{0:string,1:int,2?:string}> $lineas [precio, cantidad, estado_surtido] */
    private function pedido(string $estado, array $lineas, bool $pagado = true, array $anticipos = []): Pedido
    {
        $this->folio++;
        $pedido = Pedido::create([
            'distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::value('id'),
            'folio' => 'PED-TG356-'.$this->folio, 'tipo' => 'cliente_directo', 'cliente_directo_id' => $this->jose(),
            'estado' => $estado, 'subtotal' => 0, 'total' => 0,
            'fecha_entrega' => $estado === 'entregado' ? now()->subDay() : null,
            'capturado_por_staff_id' => DistribuidoraStaff::value('id'),
        ]);
        $pc = ProductoCampana::query()->firstOrFail();
        $total = 0;
        foreach ($lineas as $i => $l) {
            $sub = Centavos::desdeDecimal($l[0]) * $l[1];
            $total += $sub;
            PedidoDetalle::query()->forceCreate([
                'distribuidora_id' => Tenant::id(), 'pedido_id' => $pedido->id, 'producto_campana_id' => $pc->id,
                'variante_id' => Variante::query()->where('producto_id', $pc->producto_id)->value('id') ?? Variante::value('id'),
                'producto_nombre' => 'Producto '.$this->folio.'-'.$i, 'modelo' => 'M'.$i, 'talla' => '25', 'color' => 'Negro',
                'cantidad' => $l[1], 'precio_unitario' => $l[0], 'subtotal' => Centavos::aDecimal($sub),
                'anticipo_requerido' => $anticipos[$i] ?? 0, 'estado_surtido' => $l[2] ?? 'recibido',
            ]);
        }
        $pedido->forceFill(['subtotal' => Centavos::aDecimal($total), 'total' => Centavos::aDecimal($total)])->save();
        if ($pagado && $total > 0) {
            Pago::create(['distribuidora_id' => Tenant::id(), 'pedido_id' => $pedido->id, 'folio' => 'PAG-TG356-'.$this->folio,
                'tipo' => 'saldo_pedido', 'metodo' => 'efectivo', 'monto' => Centavos::aDecimal($total), 'estado' => 'aplicado',
                'registrado_por_staff_id' => DistribuidoraStaff::value('id')]);
        }

        return $pedido->fresh('detalle');
    }

    private function emitir(Pedido $pedido, array $cantidades, ?string $clave = null): Vale
    {
        return app(EmitirNotaDevolucionAction::class)->ejecutar($pedido->id, $cantidades, $clave ?? uniqid('k', true));
    }

    private function falla(callable $fn, string $texto): void
    {
        try {
            $fn();
            $this->fail('Se esperaba error: '.$texto);
        } catch (\Throwable $e) {
            $this->assertStringContainsString($texto, $e->getMessage());
        }
    }

    private function saldo(Pedido $p): string
    {
        return number_format(app(RegistrarPagoPedidoAction::class)->resumen($p->fresh())['saldo'], 2, '.', '');
    }

    private function estadoNegocio(): array
    {
        $r = [];
        foreach (['pagos', 'pedidos', 'pedido_detalle', 'movimientos_stock', 'stock_local'] as $t) {
            $r[$t] = hash('sha256', DB::table($t)->orderBy('id')->get()->toJson());
        }

        return $r;
    }

    public function test_emite_nota_con_importe_historico_exacto_en_centavos_y_trazabilidad(): void
    {
        $pedido = $this->pedido('entregado', [['333.33', 3], ['0.10', 7]]);
        [$a, $b] = $pedido->detalle->pluck('id')->all();
        $antes = $this->estadoNegocio();
        $nota = $this->emitir($pedido, [$a => 3, $b => 3]);

        $this->assertSame(Vale::TIPO_DEVOLUCION, $nota->tipo);
        $this->assertSame((int) auth()->id(), (int) $nota->creado_por_usuario_id);
        $this->assertNotNull($nota->creado_por_staff_id);
        $this->assertSame('1000.29', $nota->getRawOriginal('monto_original'));
        $this->assertSame('1000.29', $nota->getRawOriginal('saldo_actual'));
        $this->assertSame((int) $pedido->id, (int) $nota->pedido_origen_id);
        $this->assertSame($this->jose(), (int) $nota->cliente_directo_id);
        $this->assertSame((int) Tenant::id(), (int) $nota->distribuidora_id);
        $this->assertTrue($nota->fecha_vencimiento->equalTo($nota->fecha_emision->copy()->addDays(90)));
        $this->assertEquals([[$a, 3, '333.33', '999.99'], [$b, 3, '0.10', '0.30']], $nota->partidasDevolucion->sortBy('pedido_detalle_id')
            ->map(fn ($p) => [(int) $p->pedido_detalle_id, $p->cantidad, $p->getRawOriginal('precio_unitario'), $p->getRawOriginal('monto')])->values()->all());
        $this->assertSame(1, ValeMovimiento::where('vale_id', $nota->id)->where('tipo', 'emision')->count());
        // No toca pagos, pedidos ni inventario, y no cuenta como pago del pedido de origen.
        $this->assertSame($antes, $this->estadoNegocio());
        $this->assertSame('0.00', $this->saldo($pedido));
    }

    public function test_parciales_con_tope_acumulado_sin_doble_devolucion(): void
    {
        $pedido = $this->pedido('entregado', [['100.00', 3]]);
        $id = $pedido->detalle->first()->id;
        $this->emitir($pedido, [$id => 2]);
        $this->falla(fn () => $this->emitir($pedido, [$id => 2]), 'solo quedan 1');
        $this->emitir($pedido, [$id => 1]);
        $this->falla(fn () => $this->emitir($pedido, [$id => 1]), 'solo quedan 0');
        $this->assertSame(3, (int) ValeDevolucionPartida::where('pedido_detalle_id', $id)->sum('cantidad'));
    }

    public function test_idempotencia_misma_clave_misma_nota_y_otra_carga_rechazada(): void
    {
        $pedido = $this->pedido('entregado', [['50.00', 4]]);
        $id = $pedido->detalle->first()->id;
        $uno = $this->emitir($pedido, [$id => 1], 'clave-tg356');
        $dos = $this->emitir($pedido, [$id => '1'], 'clave-tg356');
        $this->assertSame($uno->id, $dos->id);
        $this->assertSame(1, Vale::where('tipo', Vale::TIPO_DEVOLUCION)->count());
        $this->falla(fn () => $this->emitir($pedido, [$id => 2], 'clave-tg356'), 'ya se usó');
    }

    public function test_entradas_invalidas_y_estados_no_admitidos(): void
    {
        $listo = $this->pedido('listo_entrega', [['10.00', 1]]);
        $this->falla(fn () => $this->emitir($listo, [$listo->detalle->first()->id => 1]), 'pedido entregado');
        $ent = $this->pedido('entregado', [['10.00', 2], ['10.00', 1, 'no_surtido']]);
        [$a, $b] = $ent->detalle->pluck('id')->all();
        $this->falla(fn () => $this->emitir($ent, []), 'al menos un producto');
        $this->falla(fn () => $this->emitir($ent, [$a => '1.5']), 'enteros');
        $this->falla(fn () => $this->emitir($ent, [$a => -1]), 'enteros');
        $this->falla(fn () => $this->emitir($ent, [$listo->detalle->first()->id => 1]), 'no pertenece');
        $this->falla(fn () => $this->emitir($ent, [$b => 1]), 'no se surtió completa');
        $this->falla(fn () => app(EmitirNotaDevolucionAction::class)->ejecutar($ent->id, [$a => 1], ''), 'clave');
        $debe = $this->pedido('entregado', [['10.00', 1]], pagado: false);
        $this->falla(fn () => $this->emitir($debe, [$debe->detalle->first()->id => 1]), 'saldo pendiente');
        $this->assertSame(0, Vale::whereNotNull('tipo')->count());
    }

    public function test_otra_distribuidora_y_usuarios_sin_permiso_no_emiten(): void
    {
        $pedido = $this->pedido('entregado', [['10.00', 1]]);
        $id = $pedido->detalle->first()->id;
        $this->como('admin@boutiquedelcalzado.test');
        $this->falla(fn () => $this->emitir($pedido, [$id => 1]), 'No se encontró');
        $this->como(self::JOSE);
        $this->falla(fn () => $this->emitir($pedido, [$id => 1]), 'permiso');
        $this->assertSame(0, Vale::withoutGlobalScopes()->whereNotNull('tipo')->count());
    }

    public function test_flujo_anterior_y_nota_no_acreditan_dos_veces_la_misma_partida(): void
    {
        $pedido = $this->pedido('entregado', [['10.00', 1], ['10.00', 1]]);
        [$a, $b] = $pedido->detalle->pluck('id')->all();
        $this->emitir($pedido, [$a => 1]);
        $this->falla(fn () => app(SolicitarCambioAction::class)->ejecutar(['pedido_detalle_id' => $a, 'motivo' => 'x']), 'nota de devolución');
        app(SolicitarCambioAction::class)->ejecutar(['pedido_detalle_id' => $b, 'motivo' => 'Talla']);
        $this->falla(fn () => $this->emitir($pedido, [$b => 1]), 'flujo anterior');
    }

    private function destino(string $total, array $anticipos = []): Pedido
    {
        return $this->pedido('confirmado', [[$total, 1]], pagado: false, anticipos: $anticipos);
    }

    private function aplicar(Vale $vale, Pedido $pedido, ?string $monto = null): Vale
    {
        return app(AplicarValeAction::class)->ejecutar($vale->fresh(), array_filter(['pedido_id' => $pedido->id, 'monto' => $monto], fn ($v) => $v !== null));
    }

    public function test_canje_igual_menor_y_mayor_con_sobrante_en_vale_nuevo(): void
    {
        $origen = $this->pedido('entregado', [['100.00', 3]]);
        $id = $origen->detalle->first()->id;

        $igual = $this->emitir($origen, [$id => 1]);
        $p1 = $this->destino('100.00');
        $this->aplicar($igual, $p1, '100.00');
        $this->assertSame(['agotado', '0.00'], [$igual->fresh()->estado, $igual->fresh()->getRawOriginal('saldo_actual')]);
        $this->assertSame('0.00', $this->saldo($p1));

        $menor = $this->emitir($origen, [$id => 1]);
        $p2 = $this->destino('250.00');
        $this->aplicar($menor, $p2);
        $this->assertSame('150.00', $this->saldo($p2));
        $this->assertSame(0, Vale::where('vale_origen_id', $menor->id)->count());

        $mayor = $this->emitir($origen, [$id => 1]);
        $p3 = $this->destino('35.55');
        Carbon::setTestNow('2026-11-20 09:30:00');
        $this->aplicar($mayor, $p3, '35.55');
        $sob = Vale::where('vale_origen_id', $mayor->id)->firstOrFail();
        $this->assertSame([Vale::TIPO_SOBRANTE, '64.45', '64.45', 'activo'], [$sob->tipo, $sob->getRawOriginal('monto_original'), $sob->getRawOriginal('saldo_actual'), $sob->estado]);
        $this->assertSame('2027-02-18 09:30:00', $sob->fecha_vencimiento->format('Y-m-d H:i:s'));
        $this->assertSame('0.00', $mayor->fresh()->getRawOriginal('saldo_actual'));
        $this->assertSame('0.00', $this->saldo($p3));
        // Conserva el crédito: aplicado + sobrante = original; el sobrante no cuenta como pago.
        $aplicado = ValeMovimiento::where('vale_id', $mayor->id)->where('tipo', 'aplicacion')->value('monto');
        $this->assertSame(10000, Centavos::desdeDecimal($aplicado) + Centavos::desdeDecimal($sob->getRawOriginal('monto_original')));
        $this->assertSame('35.55', number_format(app(RegistrarPagoPedidoAction::class)->resumen($p3->fresh())['pagado_con_vales'], 2, '.', ''));

        // El sobrante también se usa completo en un solo pedido.
        $p4 = $this->destino('10.00');
        $this->aplicar($sob, $p4);
        $this->assertSame('54.45', Vale::where('vale_origen_id', $sob->id)->firstOrFail()->getRawOriginal('monto_original'));
    }

    public function test_canje_rechaza_parcial_segundo_uso_vencido_otro_dueno_y_venta_local(): void
    {
        $origen = $this->pedido('entregado', [['100.00', 2]]);
        $nota = $this->emitir($origen, [$origen->detalle->first()->id => 1]);
        $p = $this->destino('500.00');
        $this->falla(fn () => $this->aplicar($nota, $p, '40.00'), 'completo');
        $this->falla(fn () => app(AplicarValeAction::class)->ejecutar($nota->fresh(), ['venta_directa_id' => 1, 'monto' => 100]), 'Venta local');
        $renglon = Livewire::test('punto-venta.index')->instance()->disponibles->first();
        $ventasAntes = VentaDirecta::count();
        $this->falla(fn () => app(RegistrarVentaDirectaService::class)->registrar(
            [['variante_id' => $renglon['variante_id'], 'producto_campana_id' => $renglon['producto_campana_id'], 'cantidad' => 1]],
            'efectivo', $this->jose(), $nota->folio), 'no aplica en Venta local');
        $this->assertSame($ventasAntes, VentaDirecta::count());
        Livewire::test('punto-venta.index')->set('valeFolio', $nota->folio)->call('aplicarVale')->assertSee('no aplica en Venta local');
        $this->assertSame('100.00', $nota->fresh()->getRawOriginal('saldo_actual'));

        $otro = Pedido::create(['distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::value('id'), 'folio' => 'PED-TG356-OTRO',
            'tipo' => 'cliente_directo', 'cliente_directo_id' => ClienteDirecto::where('id', '!=', $this->jose())->value('id'),
            'estado' => 'confirmado', 'subtotal' => 100, 'total' => 100, 'capturado_por_staff_id' => DistribuidoraStaff::value('id')]);
        $this->falla(fn () => $this->aplicar($nota, $otro), 'mismo propietario');

        $this->aplicar($nota, $p);
        $this->falla(fn () => $this->aplicar($nota, $this->destino('50.00')), 'ya se usó');

        $vence = $this->emitir($origen, [$origen->detalle->first()->id => 1]);
        Carbon::setTestNow(now()->addDays(90)->addSecond());
        $this->falla(fn () => $this->aplicar($vence, $this->destino('50.00')), 'vencido');
    }

    public function test_otra_distribuidora_no_ve_ni_usa_la_nota(): void
    {
        $origen = $this->pedido('entregado', [['100.00', 1]]);
        $nota = $this->emitir($origen, [$origen->detalle->first()->id => 1]);
        $this->como('admin@boutiquedelcalzado.test');
        $this->assertNull(Vale::query()->find($nota->id));
    }

    private function valeFixture(string $tipo, string $monto): Vale
    {
        return Vale::create(['distribuidora_id' => Tenant::id(), 'cliente_directo_id' => $this->jose(), 'folio' => 'VAL-FIX-'.uniqid(),
            'monto_original' => $monto, 'saldo_actual' => $monto, 'fecha_emision' => now(), 'fecha_vencimiento' => now()->addDays(90),
            'estado' => 'activo', 'tipo' => $tipo, 'creado_por_staff_id' => DistribuidoraStaff::value('id')]);
    }

    public function test_vale_de_anticipo_cubre_anticipos_y_su_sobrante_paga_anticipo_o_saldo(): void
    {
        $p = $this->destino('500.00', anticipos: ['200.00']);
        $a = $this->valeFixture(Vale::TIPO_ANTICIPO, '150.00');
        $this->aplicar($a, $p);
        $r = app(RegistrarPagoPedidoAction::class)->resumen($p->fresh());
        $this->assertSame([150.0, 50.0, 350.0], [$r['pagado'], $r['anticipo_pendiente'], $r['saldo']]);

        // Vale de anticipo mayor que el anticipo pendiente: consume 50 y el resto va a un sobrante nuevo.
        $b = $this->valeFixture(Vale::TIPO_ANTICIPO, '80.00');
        $this->aplicar($b, $p, '80.00');
        $sob = Vale::where('vale_origen_id', $b->id)->firstOrFail();
        $aplicado = ValeMovimiento::where('vale_id', $b->id)->where('tipo', 'aplicacion')->value('monto');
        $this->assertSame(['50.00', '30.00', Vale::TIPO_SOBRANTE], [$aplicado, $sob->getRawOriginal('monto_original'), $sob->tipo]);
        $this->assertSame(8000, Centavos::desdeDecimal($aplicado) + Centavos::desdeDecimal($sob->getRawOriginal('monto_original')));
        $r = app(RegistrarPagoPedidoAction::class)->resumen($p->fresh());
        $this->assertSame([200.0, 0.0, 300.0], [$r['pagado'], $r['anticipo_pendiente'], $r['saldo']]);
        $this->assertSame([$b->id, '2027-02-05 12:00:00'], [(int) $sob->vale_origen_id, $sob->fecha_vencimiento->format('Y-m-d H:i:s')]);

        // El sobrante es de tipo propio: paga saldo del pedido aunque el anticipo ya esté cubierto.
        $this->aplicar($sob, $p, '30.00');
        $this->assertSame('270.00', $this->saldo($p));
        $this->assertSame(0, Vale::where('vale_origen_id', $sob->id)->count());
        $r = app(RegistrarPagoPedidoAction::class)->resumen($p->fresh());
        $this->assertSame([230.0, 0.0], [$r['pagado'], $r['anticipo_pendiente']]);

        // Cadena anticipo -> sobrante -> saldo con sobrante nuevo: conserva centavos y origen.
        $c = $this->valeFixture(Vale::TIPO_ANTICIPO, '100.01');
        $q = $this->destino('300.00', anticipos: ['40.00']);
        $this->aplicar($c, $q);
        $s1 = Vale::where('vale_origen_id', $c->id)->firstOrFail();
        $this->assertSame('60.01', $s1->getRawOriginal('monto_original'));
        $chico = $this->destino('25.50');
        Carbon::setTestNow('2026-11-10 08:00:00');
        $this->aplicar($s1, $chico);
        $s2 = Vale::where('vale_origen_id', $s1->id)->firstOrFail();
        $this->assertSame(['34.51', Vale::TIPO_SOBRANTE, '2027-02-08 08:00:00'], [$s2->getRawOriginal('monto_original'), $s2->tipo, $s2->fecha_vencimiento->format('Y-m-d H:i:s')]);
        $this->assertSame('0.00', $this->saldo($chico));
        $this->assertSame(10001, 4000 + 2550 + Centavos::desdeDecimal($s2->getRawOriginal('monto_original')));

        // El vale de anticipo original sigue siendo solo para anticipos.
        $this->falla(fn () => $this->aplicar($this->valeFixture(Vale::TIPO_ANTICIPO, '5.00'), $this->destino('50.00')), 'no tiene anticipo pendiente');

        // Sobrante: no Venta local, no otro dueño, no otra distribuidora.
        $this->falla(fn () => app(AplicarValeAction::class)->ejecutar($s2->fresh(), ['venta_directa_id' => 1, 'monto' => 1]), 'Venta local');
        $otro = Pedido::create(['distribuidora_id' => Tenant::id(), 'sucursal_id' => Sucursal::value('id'), 'folio' => 'PED-TG356-OTRO2',
            'tipo' => 'cliente_directo', 'cliente_directo_id' => ClienteDirecto::where('id', '!=', $this->jose())->value('id'),
            'estado' => 'confirmado', 'subtotal' => 100, 'total' => 100, 'capturado_por_staff_id' => DistribuidoraStaff::value('id')]);
        $this->falla(fn () => $this->aplicar($s2, $otro), 'mismo propietario');
        $this->como('admin@boutiquedelcalzado.test');
        $this->assertNull(Vale::query()->find($s2->id));
    }

    public function test_montos_con_mas_de_dos_decimales_o_parciales_se_rechazan(): void
    {
        $origen = $this->pedido('entregado', [['100.00', 1]]);
        $nota = $this->emitir($origen, [$origen->detalle->first()->id => 1]);
        $p = $this->destino('500.00');
        $this->falla(fn () => $this->aplicar($nota, $p, '100.001'), 'no es válido');
        $this->falla(fn () => $this->aplicar($nota, $p, '99.99'), 'completo');
        $this->aplicar($nota, $p, '100');
        $this->assertSame('400.00', $this->saldo($p));
    }

    public function test_la_base_solo_admite_vales_sin_staff_si_son_sobrante_con_autor(): void
    {
        $base = ['distribuidora_id' => Tenant::id(), 'cliente_directo_id' => $this->jose(), 'monto_original' => 1, 'saldo_actual' => 1,
            'fecha_vencimiento' => now()->addDays(90), 'estado' => 'activo', 'creado_por_staff_id' => null];
        $origen = $this->valeFixture(Vale::TIPO_ANTICIPO, '1.00')->id;
        $autor = auth()->id();
        foreach ([['tipo' => null], ['tipo' => 'devolucion_producto'], ['tipo' => 'sobrante'], ['tipo' => 'sobrante', 'creado_por_usuario_id' => $autor],
            // Semántica NULL: con tipo NULL, "tipo = 'sobrante'" da NULL y un CHECK con NULL pasaría.
            ['tipo' => null, 'vale_origen_id' => $origen, 'creado_por_usuario_id' => $autor],
            ['tipo' => 'devolucion_producto', 'vale_origen_id' => $origen, 'creado_por_usuario_id' => $autor]] as $i => $extra) {
            try {
                DB::table('vales')->insert($base + $extra + ['folio' => 'CHK-'.$i]);
                $this->fail('El CHECK debía rechazar el caso '.$i);
            } catch (QueryException $e) {
                $this->assertStringContainsStringIgnoringCase('chk_vale_autor', $e->getMessage());
            }
        }
    }

    public function test_bono_no_se_usa_y_no_hay_via_para_emitir_tipos_desde_la_api(): void
    {
        $this->falla(fn () => $this->aplicar($this->valeFixture(Vale::TIPO_BONO, '10.00'), $this->destino('50.00')), 'bono');
        $this->como('empleado@calzadosramirez.test', api: true);
        $id = $this->postJson('/api/vales', ['propietario_tipo' => 'cliente_directo', 'propietario_id' => $this->jose(),
            'monto_original' => 10, 'tipo' => 'bono', 'clave_idempotencia' => 'x', 'creado_por_usuario_id' => 1,
            'vale_origen_id' => 1, 'creado_por_staff_id' => null])->assertCreated()->json('data.id');
        $legacy = Vale::withoutGlobalScopes()->findOrFail($id);
        $this->assertNull($legacy->tipo);
        $this->assertNull($legacy->clave_idempotencia);
        $this->assertNull($legacy->vale_origen_id);
        $this->assertNotNull($legacy->creado_por_staff_id);
        // El cliente de la app no puede emitir vales (ruta solo de personal).
        $this->como(self::JOSE, api: true);
        $this->postJson('/api/vales', ['propietario_tipo' => 'cliente_directo', 'propietario_id' => $this->jose(), 'monto_original' => 10])->assertForbidden();
    }

    public function test_api_de_la_app_respeta_reglas_de_la_nota(): void
    {
        $origen = $this->pedido('entregado', [['100.00', 2]]);
        $nota = $this->emitir($origen, [$origen->detalle->first()->id => 1]);
        $chico = $this->destino('30.00');
        $grande = $this->destino('300.00');
        $this->como(self::JOSE, api: true);
        $lista = $this->getJson('/api/vales')->assertOk()->assertJsonFragment(['folio' => $nota->folio, 'tipo' => 'devolucion_producto'])->json('data');
        foreach ($lista as $fila) {
            // creado_por_usuario_id es auditoría interna, sin FK y sin salir en la API.
            $this->assertArrayNotHasKey('creado_por_usuario_id', $fila);
        }
        $this->postJson("/api/vales/{$nota->id}/aplicar", ['pedido_id' => $grande->id, 'monto' => 20])->assertStatus(422);
        // Desde la app (sin staff), el monto que manda el selector (mín. de vale y saldo) usa la nota
        // completa y el sobrante queda con el usuario como autor, sin inventar un empleado.
        $this->postJson("/api/vales/{$nota->id}/aplicar", ['pedido_id' => $chico->id, 'monto' => 30])->assertOk();
        $this->assertSame('agotado', $nota->fresh()->estado);
        $sob = Vale::withoutGlobalScopes()->where('vale_origen_id', $nota->id)->firstOrFail();
        $this->assertSame(['70.00', null, (int) Usuario::where('email', self::JOSE)->value('id')],
            [$sob->getRawOriginal('monto_original'), $sob->creado_por_staff_id, (int) $sob->creado_por_usuario_id]);
        $this->assertNull(ValeMovimiento::withoutGlobalScopes()->where('vale_id', $nota->id)->where('tipo', 'aplicacion')->value('registrado_por_staff_id'));
        $this->postJson("/api/vales/{$sob->id}/aplicar", ['pedido_id' => $grande->id, 'monto' => 70])->assertOk();
        $this->assertSame('230.00', $this->saldo($grande));
    }

    public function test_vale_legacy_conserva_su_uso_parcial(): void
    {
        $legacy = Vale::create(['distribuidora_id' => Tenant::id(), 'cliente_directo_id' => $this->jose(), 'folio' => 'VAL-LEG-1',
            'monto_original' => '100.00', 'saldo_actual' => '100.00', 'fecha_emision' => now(), 'fecha_vencimiento' => now()->addDays(90),
            'estado' => 'activo', 'creado_por_staff_id' => DistribuidoraStaff::value('id')]);
        $this->aplicar($legacy, $this->destino('500.00'), '40.00');
        $this->assertSame(['60.00', 'activo', null], [$legacy->fresh()->getRawOriginal('saldo_actual'), $legacy->fresh()->estado, $legacy->fresh()->tipo]);
        $this->assertSame(0, Vale::where('vale_origen_id', $legacy->id)->count());
    }

    public function test_panel_cambios_emite_nota_y_muestra_vencimiento_en_hora_cdmx(): void
    {
        $pedido = $this->pedido('entregado', [['120.00', 2]]);
        $id = $pedido->detalle->first()->id;
        $pantalla = Livewire::test('cambios.index')->set('folioPedido', $pedido->folio)->call('buscarPedido')
            ->assertSee('Devolver productos seleccionados')
            ->call('prepararDevolucion')->assertSet('errorDevolucion', 'Elige al menos un producto y la cantidad a devolver.')
            ->set("devolver.$id", '1')->call('prepararDevolucion')->assertSet('confirmandoDevolucion', true)
            ->assertSee('$120.00')->assertSee(Distribuidora::query()->whereKey(Tenant::id())->value('nombre_comercial'))
            ->call('confirmarDevolucion')->assertSet('errorDevolucion', '');
        $nota = Vale::where('tipo', Vale::TIPO_DEVOLUCION)->firstOrFail();
        $pantalla->assertSet('notaEmitidaId', $nota->id)->assertSee($nota->folio)
            ->assertSee('Vence el 05/02/2027 12:00 (hora de Ciudad de México)')->assertSee('de 1');
        // Un clic repetido con la clave vieja no puede volver a emitir: la clave se renueva y el tope manda.
        $pantalla->set("devolver.$id", '2')->call('prepararDevolucion')->call('confirmarDevolucion')
            ->assertSet('errorDevolucion', 'De '.$pedido->detalle->first()->producto_nombre.' solo quedan 1 unidades por devolver.');
        $this->assertSame(1, Vale::where('tipo', Vale::TIPO_DEVOLUCION)->count());
    }

    public function test_panel_sin_permiso_no_ve_controles_de_devolucion(): void
    {
        $pedido = $this->pedido('entregado', [['120.00', 2]]);
        $this->como('empleado@calzadosramirez.test');
        $puede = app(PermisoModuloService::class)->puede(auth()->user(), PermisoModuloService::CAMBIOS_RESOLVER);
        $pantalla = Livewire::test('cambios.index')->set('folioPedido', $pedido->folio)->call('buscarPedido');
        if ($puede) {
            $pantalla->assertSee('Devolver productos seleccionados');
        } else {
            $pantalla->assertDontSee('Devolver productos seleccionados');
            $pantalla->call('prepararDevolucion')->assertForbidden();
        }
        $this->assertTrue(true);
    }

    public function test_borrar_distribuidora_y_cuenta_autora_no_se_bloquea_por_notas_ni_sobrantes(): void
    {
        $origen = $this->pedido('entregado', [['100.00', 1]]);
        $nota = $this->emitir($origen, [$origen->detalle->first()->id => 1]);
        $chico = $this->destino('30.00');
        $this->como(self::JOSE, api: true);
        $this->postJson("/api/vales/{$nota->id}/aplicar", ['pedido_id' => $chico->id, 'monto' => 30])->assertOk();
        $sob = Vale::withoutGlobalScopes()->where('vale_origen_id', $nota->id)->firstOrFail();
        $sob2 = $this->valeFixture(Vale::TIPO_SOBRANTE, '1.00');
        $sob2->forceFill(['vale_origen_id' => $sob->id])->save();

        // El autor es dato de auditoría: borrar la cuenta no falla y el id se conserva.
        $this->assertNotNull($sob->creado_por_usuario_id);
        $suelto = Usuario::query()->firstOrFail()->replicate();
        $suelto->forceFill(['email' => 'autor-suelto@example.test'])->save();
        DB::table('vales')->where('id', $sob->id)->update(['creado_por_usuario_id' => $suelto->id]);
        DB::table('usuarios')->where('id', $suelto->id)->delete();
        $this->assertSame($suelto->id, (int) DB::table('vales')->where('id', $sob->id)->value('creado_por_usuario_id'));

        $this->como(self::ADMIN);
        $dist = Distribuidora::query()->findOrFail(Tenant::id());
        app(EliminarDistribuidoraAction::class)->ejecutar($dist);
        $this->assertSame(0, Vale::withoutGlobalScopes()->where('distribuidora_id', $dist->id)->count());
        $this->assertSame(0, ValeDevolucionPartida::withoutGlobalScopes()->where('distribuidora_id', $dist->id)->count());
    }

    public function test_borra_un_sobrante_aunque_su_id_sea_menor_que_el_origen(): void
    {
        $dist = (int) Tenant::id();
        $staff = (int) DistribuidoraStaff::value('id');
        $cliente = $this->jose();
        $comun = [
            'distribuidora_id' => $dist, 'cliente_directo_id' => $cliente, 'monto_original' => 10, 'saldo_actual' => 10,
            'fecha_vencimiento' => now()->addDays(90), 'estado' => 'activo', 'creado_por_staff_id' => $staff,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('vales')->insert($comun + ['id' => 880020, 'folio' => 'VAL-ORIGEN-880020', 'tipo' => Vale::TIPO_ANTICIPO]);
        DB::table('vales')->insert($comun + ['id' => 880010, 'folio' => 'VAL-SOBRA-880010', 'tipo' => Vale::TIPO_SOBRANTE, 'vale_origen_id' => 880020]);
        $this->assertLessThan(880020, 880010);

        app(EliminarDistribuidoraAction::class)->ejecutar(Distribuidora::query()->findOrFail($dist));

        $this->assertSame(0, DB::table('vales')->where('distribuidora_id', $dist)->count());
    }

    public function test_un_ciclo_de_origen_no_borra_ningun_vale_ni_la_distribuidora(): void
    {
        $dist = (int) Tenant::id();
        $staff = (int) DistribuidoraStaff::value('id');
        $cliente = $this->jose();
        $comun = [
            'distribuidora_id' => $dist, 'cliente_directo_id' => $cliente, 'monto_original' => 5, 'saldo_actual' => 5,
            'fecha_vencimiento' => now()->addDays(90), 'estado' => 'activo', 'creado_por_staff_id' => $staff,
            'tipo' => Vale::TIPO_SOBRANTE, 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('vales')->insert($comun + ['id' => 880101, 'folio' => 'VAL-CICLO-A']);
        DB::table('vales')->insert($comun + ['id' => 880102, 'folio' => 'VAL-CICLO-B']);
        DB::table('vales')->where('id', 880101)->update(['vale_origen_id' => 880102]);
        DB::table('vales')->where('id', 880102)->update(['vale_origen_id' => 880101]);

        try {
            BorrarValesPorOrigen::borrar(DB::table('vales')->whereIn('id', [880101, 880102]));
            $this->fail('El ciclo debía abortar el borrado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ciclo', $e->getMessage());
        }
        $this->assertSame(2, DB::table('vales')->whereIn('id', [880101, 880102])->count());

        try {
            app(EliminarDistribuidoraAction::class)->ejecutar(Distribuidora::query()->findOrFail($dist));
            $this->fail('La distribuidora no debía borrarse con un ciclo de vales.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ciclo', $e->getMessage());
        }
        $this->assertNotNull(DB::table('distribuidoras')->where('id', $dist)->first());
        $this->assertSame(2, DB::table('vales')->whereIn('id', [880101, 880102])->count());
    }
}
