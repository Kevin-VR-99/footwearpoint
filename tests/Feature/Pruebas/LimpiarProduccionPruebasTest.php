<?php

namespace Tests\Feature\Pruebas;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * pruebas:limpiar-produccion sobre MySQL real (footwearpoint_testing):
 * arma pedidos, pagos, vales, ventas, stock y cambios encima del seeder,
 * con todas las llaves RESTRICT ocupadas, y comprueba que la limpieza
 * completa corre sin errores de llaves foráneas.
 */
class LimpiarProduccionPruebasTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private int $importacion;

    private array $pcIa;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Storage::fake('respaldos');
        $this->armarDatos();
    }

    private function armarDatos(): void
    {
        $ahora = now();
        $d = DB::table('distribuidoras')->orderBy('id')->value('id');
        $staff = DB::table('distribuidora_staff')->where('distribuidora_id', $d)->value('id');
        $sucursal = DB::table('sucursales')->where('distribuidora_id', $d)->value('id');
        $usuario = DB::table('usuarios')->orderBy('id')->value('id');
        $cliente = DB::table('clientes_directos')->where('distribuidora_id', $d)->value('id');

        // Catálogo "de la IA": los dos primeros productos de temporada.
        $pcs = DB::table('producto_campana')->orderBy('id')->get();
        $this->pcIa = $pcs->take(2)->pluck('id')->all();
        $this->importacion = DB::table('importaciones_catalogo')->insertGetId([
            'linea_id' => DB::table('lineas')->value('id'), 'archivo_url' => 'x.pdf', 'tipo_archivo' => 'pdf',
            'estado' => 'aprobado', 'iniciada_por_usuario_id' => $usuario, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $otra = DB::table('importaciones_catalogo')->insertGetId([
            'linea_id' => DB::table('lineas')->value('id'), 'archivo_url' => 'y.pdf', 'tipo_archivo' => 'pdf',
            'estado' => 'aprobado', 'iniciada_por_usuario_id' => $usuario, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        foreach ($pcs->take(3)->values() as $i => $pc) {
            DB::table('productos_importados_staging')->insert([
                'importacion_id' => $i < 2 ? $this->importacion : $otra, 'datos_extraidos' => '{}', 'estado' => 'aprobado',
                'producto_creado_id' => $pc->producto_id, 'producto_campana_creado_id' => $pc->id,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        $url = fn ($f) => 'https://pub.r2.dev/productos/campana/imagenes/'.$f;
        DB::table('producto_imagenes')->insert([
            ['producto_campana_id' => $this->pcIa[0], 'url' => $url('ia.jpg'), 'created_at' => $ahora],
            ['producto_campana_id' => $this->pcIa[1], 'url' => $url('compartida.jpg'), 'created_at' => $ahora],
            ['producto_campana_id' => $pcs[5]->id, 'url' => $url('compartida.jpg'), 'created_at' => $ahora],
            ['producto_campana_id' => $pcs[6]->id, 'url' => $url('demo.jpg'), 'created_at' => $ahora],
        ]);
        foreach (['ia.jpg', 'compartida.jpg', 'demo.jpg'] as $f) {
            Storage::disk('s3')->put('productos/campana/imagenes/'.$f, 'x');
        }

        $pcDemo = $pcs[5];
        $variante = DB::table('variantes')->where('producto_id', $pcDemo->producto_id)->value('id');
        $varianteIa = DB::table('variantes')->where('producto_id', $pcs[0]->producto_id)->value('id');

        $pedido = DB::table('pedidos')->insertGetId([
            'distribuidora_id' => $d, 'sucursal_id' => $sucursal, 'folio' => 'PED-1', 'tipo' => 'cliente_directo',
            'cliente_directo_id' => $cliente, 'subtotal' => 100, 'total' => 100, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $detalle = [];
        foreach ([[$pcDemo->id, $variante], [$pcs[0]->id, $varianteIa]] as [$pc, $v]) {
            $detalle[] = DB::table('pedido_detalle')->insertGetId([
                'distribuidora_id' => $d, 'pedido_id' => $pedido, 'producto_campana_id' => $pc, 'variante_id' => $v,
                'producto_nombre' => 'X', 'modelo' => 'X', 'talla' => '25', 'color' => 'Negro', 'cantidad' => 1,
                'precio_unitario' => 50, 'subtotal' => 50, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
        DB::table('historial_estados_pedido')->insert(['distribuidora_id' => $d, 'pedido_id' => $pedido, 'estado_nuevo' => 'apartado', 'cambiado_por_staff_id' => $staff]);

        $pago = DB::table('pagos')->insertGetId([
            'distribuidora_id' => $d, 'pedido_id' => $pedido, 'folio' => 'PAG-1', 'tipo' => 'anticipo', 'metodo' => 'mercado_pago',
            'monto' => 50, 'referencia_externa' => '111', 'registrado_por_staff_id' => $staff, 'created_at' => $ahora,
        ]);
        DB::table('reembolsos_mercado_pago')->insert([
            'distribuidora_id' => $d, 'pago_id' => $pago, 'mp_pago_id' => '111', 'monto' => 10, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $suscripcion = DB::table('suscripciones')->where('distribuidora_id', $d)->value('id');
        DB::table('pagos')->insert([
            'distribuidora_id' => $d, 'suscripcion_id' => $suscripcion, 'folio' => 'SUS-1', 'tipo' => 'suscripcion',
            'metodo' => 'mercado_pago', 'monto' => 300, 'referencia_externa' => '222', 'created_at' => $ahora,
        ]);
        DB::table('webhooks_mercado_pago')->insert([
            ['tipo' => 'payment', 'recurso_id' => '111', 'distribuidora_id' => $d, 'payload' => '{}'],
            ['tipo' => 'payment', 'recurso_id' => '222', 'distribuidora_id' => $d, 'payload' => '{}'],
        ]);

        $vale = DB::table('vales')->insertGetId([
            'distribuidora_id' => $d, 'cliente_directo_id' => $cliente, 'folio' => 'VAL-1', 'monto_original' => 100,
            'saldo_actual' => 40, 'fecha_vencimiento' => $ahora->copy()->addMonth(), 'pedido_origen_id' => $pedido,
            'creado_por_staff_id' => $staff, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('vale_movimientos')->insert([
            'distribuidora_id' => $d, 'vale_id' => $vale, 'pedido_id' => $pedido, 'tipo' => 'aplicacion',
            'monto' => 60, 'saldo_anterior' => 100, 'saldo_posterior' => 40, 'registrado_por_staff_id' => $staff,
        ]);

        $stock = DB::table('stock_local')->where('distribuidora_id', $d)->first()
            ?? (object) ['id' => DB::table('stock_local')->insertGetId([
                'distribuidora_id' => $d, 'sucursal_id' => $sucursal, 'variante_id' => $variante, 'existencia' => 3,
            ]), 'variante_id' => $variante];
        $venta = DB::table('ventas_directas')->insertGetId([
            'distribuidora_id' => $d, 'sucursal_id' => $sucursal, 'folio' => 'VD-1', 'subtotal' => 50, 'total' => 50,
            'registrada_por_staff_id' => $staff, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $ventaDetalle = DB::table('venta_directa_detalle')->insertGetId([
            'distribuidora_id' => $d, 'venta_directa_id' => $venta, 'stock_local_id' => $stock->id, 'variante_id' => $stock->variante_id,
            'producto_nombre' => 'X', 'modelo' => 'X', 'talla' => '25', 'color' => 'Negro', 'cantidad' => 1,
            'precio_unitario' => 50, 'subtotal' => 50,
        ]);
        DB::table('pagos')->insert([
            'distribuidora_id' => $d, 'venta_directa_id' => $venta, 'folio' => 'PAG-VD', 'tipo' => 'venta_directa',
            'metodo' => 'efectivo', 'monto' => 50, 'registrado_por_staff_id' => $staff, 'created_at' => $ahora,
        ]);
        DB::table('movimientos_stock')->insert([
            'distribuidora_id' => $d, 'stock_local_id' => $stock->id, 'venta_detalle_id' => $ventaDetalle, 'tipo' => 'venta',
            'cantidad' => 1, 'existencia_anterior' => 3, 'existencia_posterior' => 2, 'registrado_por_staff_id' => $staff,
        ]);

        $solicitud = DB::table('solicitudes_cambio')->insertGetId([
            'distribuidora_id' => $d, 'pedido_detalle_id' => $detalle[0], 'cliente_directo_id' => $cliente, 'vale_generado_id' => $vale,
            'fecha_entrega_original' => $ahora, 'dias_solicitud_aplicados' => 7, 'fecha_limite_solicitud' => $ahora,
            'dias_gestion_fabrica_aplicados' => 7, 'fecha_limite_gestion_fabrica' => $ahora, 'motivo' => 'Talla',
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('solicitudes_cambio_historial')->insert(['distribuidora_id' => $d, 'solicitud_cambio_id' => $solicitud, 'estado_nuevo' => 'solicitada', 'created_at' => $ahora]);

        DB::table('notificaciones')->insert([
            ['usuario_id' => $usuario, 'distribuidora_id' => $d, 'tipo' => 'pedido_estado', 'titulo' => 'x', 'mensaje' => 'x', 'created_at' => $ahora],
            ['usuario_id' => $usuario, 'distribuidora_id' => $d, 'tipo' => 'pago_suscripcion', 'titulo' => 'x', 'mensaje' => 'x', 'created_at' => $ahora],
        ]);
        DB::table('auditorias')->insert(['distribuidora_id' => 1, 'accion' => 'x', 'entidad_tipo' => 'x', 'entidad_id' => 1, 'created_at' => $ahora]);
    }

    private function respaldoReciente(): void
    {
        Storage::disk('respaldos')->put('respaldos/'.now('UTC')->format('Y/m').'/footwearpoint-'.now('UTC')->format('Ymd-His').'.sql.gz', 'x');
    }

    private function conteo(): array
    {
        return collect(DB::select("select table_name t from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'"))
            ->mapWithKeys(fn ($r) => [$r->t => DB::table($r->t)->count()])->all();
    }

    public function test_sin_confirmar_no_borra_nada_y_lista_vales_con_saldo(): void
    {
        $antes = $this->conteo();

        $this->artisan('pruebas:limpiar-produccion', ['--conservar-importacion' => (string) $this->importacion])
            ->expectsOutputToContain('VAL-1')
            ->expectsOutputToContain('SE QUEDA')
            ->assertSuccessful();

        $this->assertSame($antes, $this->conteo());
    }

    public function test_exige_importacion_respaldo_y_permiso_para_vales_con_saldo(): void
    {
        $antes = $this->conteo();

        $this->artisan('pruebas:limpiar-produccion --confirmar')->assertFailed();
        $this->artisan('pruebas:limpiar-produccion', ['--conservar-importacion' => '999', '--confirmar' => true])->assertFailed();
        $this->artisan('pruebas:limpiar-produccion', ['--conservar-importacion' => (string) $this->importacion, '--confirmar' => true])
            ->expectsOutputToContain('vales con saldo')->assertFailed();
        $this->artisan('pruebas:limpiar-produccion', ['--conservar-importacion' => (string) $this->importacion, '--confirmar' => true, '--incluir-vales-con-saldo' => true])
            ->expectsOutputToContain('respaldo')->assertFailed();

        $this->assertSame($antes, $this->conteo());
    }

    public function test_limpieza_completa_sin_errores_de_llaves(): void
    {
        $this->respaldoReciente();
        $intocables = ['usuarios', 'distribuidoras', 'distribuidora_staff', 'suscripciones', 'planes_suscripcion', 'lineas', 'marcas',
            'campanas', 'ciclos_compra', 'clientes_directos', 'revendedores', 'productos_importados_staging', 'importaciones_catalogo', 'sucursales'];
        $antes = $this->conteo();
        $productosIa = DB::table('producto_campana')->whereIn('id', $this->pcIa)->pluck('producto_id');
        $variantesIa = DB::table('variantes')->whereIn('producto_id', $productosIa)->count();

        $this->artisan('pruebas:limpiar-produccion', [
            '--conservar-importacion' => (string) $this->importacion, '--confirmar' => true, '--incluir-vales-con-saldo' => true,
        ])->assertSuccessful();

        foreach (['pedidos', 'pedido_detalle', 'historial_estados_pedido', 'vales', 'vale_movimientos', 'reembolsos_mercado_pago',
            'ventas_directas', 'venta_directa_detalle', 'movimientos_stock', 'stock_local', 'solicitudes_cambio', 'solicitudes_cambio_historial'] as $t) {
            $this->assertSame(0, DB::table($t)->count(), $t);
        }
        $this->assertSame(['suscripcion'], DB::table('pagos')->distinct()->pluck('tipo')->all());
        $this->assertSame(['pago_suscripcion'], DB::table('notificaciones')->distinct()->pluck('tipo')->all());
        $this->assertSame(['222'], DB::table('webhooks_mercado_pago')->pluck('recurso_id')->all());
        $this->assertSame(0, DB::table('auditorias')->where('distribuidora_id', 1)->count());

        $this->assertEqualsCanonicalizing($this->pcIa, DB::table('producto_campana')->pluck('id')->all());
        $this->assertEqualsCanonicalizing($productosIa->all(), DB::table('productos')->pluck('id')->all());
        $this->assertSame($variantesIa, DB::table('variantes')->count());
        $this->assertSame(2, DB::table('producto_imagenes')->count());

        Storage::disk('s3')->assertExists('productos/campana/imagenes/ia.jpg');
        Storage::disk('s3')->assertExists('productos/campana/imagenes/compartida.jpg');
        Storage::disk('s3')->assertMissing('productos/campana/imagenes/demo.jpg');

        $despues = $this->conteo();
        foreach ($intocables as $t) {
            $this->assertSame($antes[$t], $despues[$t], $t);
        }

        // Se puede correr otra vez.
        $this->artisan('pruebas:limpiar-produccion', ['--conservar-importacion' => (string) $this->importacion, '--confirmar' => true])
            ->assertSuccessful();
    }
}
