<?php

namespace Tests\Feature\Sprint5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * TG-356: down() con datos se niega ANTES de cualquier ALTER.
 * Estas pruebas viven en la transacción de RefreshDatabase: si la migración
 * tocara la tabla, MySQL confirmaría el DDL y rompería el rollback de la prueba.
 */
class TG356ReversaGuardasTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function migracion(string $archivo): object
    {
        return require base_path('database/migrations/'.$archivo);
    }

    /** @return array<string, string> */
    private function restriccionesVales(): array
    {
        $filas = DB::select(
            'select CONSTRAINT_NAME, CONSTRAINT_TYPE from information_schema.TABLE_CONSTRAINTS where TABLE_SCHEMA = database() and TABLE_NAME = ? order by CONSTRAINT_NAME',
            ['vales']
        );
        $mapa = [];
        foreach ($filas as $fila) {
            $mapa[$fila->CONSTRAINT_NAME] = $fila->CONSTRAINT_TYPE;
        }

        return $mapa;
    }

    private function baseVale(int $dist, int $staff, int $cliente): array
    {
        return [
            'distribuidora_id' => $dist, 'cliente_directo_id' => $cliente, 'monto_original' => 8, 'saldo_actual' => 8,
            'fecha_vencimiento' => now()->addDays(90), 'estado' => 'activo', 'creado_por_staff_id' => $staff,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    public function test_down_de_autor_con_sobrante_sin_staff_no_toca_fk_ni_borra_la_fila(): void
    {
        $dist = (int) DB::table('distribuidoras')->orderBy('id')->value('id');
        $staff = (int) DB::table('distribuidora_staff')->where('distribuidora_id', $dist)->value('id');
        $cliente = (int) DB::table('clientes_directos')->where('distribuidora_id', $dist)->value('id');
        $usuario = (int) DB::table('usuarios')->orderBy('id')->value('id');
        $antes = $this->restriccionesVales();
        $origen = DB::table('vales')->insertGetId(array_merge($this->baseVale($dist, $staff, $cliente), ['folio' => 'VAL-GUARDA-ORIGEN', 'tipo' => 'anticipo']));
        $sobrante = DB::table('vales')->insertGetId(array_merge($this->baseVale($dist, $staff, $cliente), [
            'folio' => 'VAL-GUARDA-SOBRA', 'tipo' => 'sobrante', 'vale_origen_id' => $origen,
            'creado_por_staff_id' => null, 'creado_por_usuario_id' => $usuario,
        ]));

        try {
            $this->migracion('2026_11_07_120100_permite_vale_sobrante_generado_desde_app.php')->down();
            $this->fail('down() debía rechazar el sobrante sin staff.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sin empleado', $e->getMessage());
            $this->assertStringContainsString('No se modificó la tabla vales', $e->getMessage());
        }

        $this->assertSame($antes, $this->restriccionesVales());
        $this->assertSame($usuario, (int) DB::table('vales')->where('id', $sobrante)->value('creado_por_usuario_id'));
        $this->assertNull(DB::table('vales')->where('id', $sobrante)->value('creado_por_staff_id'));
        $this->assertSame('YES', $this->staffNullable());
    }

    public function test_down_de_autor_con_usuario_auditor_no_borra_el_id(): void
    {
        $dist = (int) DB::table('distribuidoras')->orderBy('id')->value('id');
        $staff = (int) DB::table('distribuidora_staff')->where('distribuidora_id', $dist)->value('id');
        $cliente = (int) DB::table('clientes_directos')->where('distribuidora_id', $dist)->value('id');
        $usuario = (int) DB::table('usuarios')->orderBy('id')->value('id');
        $antes = $this->restriccionesVales();
        $id = DB::table('vales')->insertGetId(array_merge($this->baseVale($dist, $staff, $cliente), [
            'folio' => 'VAL-GUARDA-AUDITOR', 'tipo' => 'devolucion_producto', 'creado_por_usuario_id' => $usuario,
        ]));

        try {
            $this->migracion('2026_11_07_120100_permite_vale_sobrante_generado_desde_app.php')->down();
            $this->fail('down() debía rechazar el id de auditoría.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('creado_por_usuario_id', $e->getMessage());
            $this->assertStringContainsString('No se modificó la tabla vales', $e->getMessage());
        }

        $this->assertSame($antes, $this->restriccionesVales());
        $this->assertSame($usuario, (int) DB::table('vales')->where('id', $id)->value('creado_por_usuario_id'));
    }

    public function test_down_de_tipos_con_nota_no_borra_columnas_ni_partidas(): void
    {
        $dist = (int) DB::table('distribuidoras')->orderBy('id')->value('id');
        $staff = (int) DB::table('distribuidora_staff')->where('distribuidora_id', $dist)->value('id');
        $cliente = (int) DB::table('clientes_directos')->where('distribuidora_id', $dist)->value('id');
        $sucursal = (int) DB::table('sucursales')->where('distribuidora_id', $dist)->value('id');
        $pc = DB::table('producto_campana')->first();
        $variante = (int) DB::table('variantes')->where('producto_id', $pc->producto_id)->value('id');
        $antes = $this->restriccionesVales();
        $pedido = DB::table('pedidos')->insertGetId([
            'distribuidora_id' => $dist, 'sucursal_id' => $sucursal, 'folio' => 'PED-GUARDA-TIPO',
            'tipo' => 'cliente_directo', 'cliente_directo_id' => $cliente, 'estado' => 'entregado',
            'subtotal' => 10, 'total' => 10, 'capturado_por_staff_id' => $staff,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $detalle = DB::table('pedido_detalle')->insertGetId([
            'distribuidora_id' => $dist, 'pedido_id' => $pedido, 'producto_campana_id' => $pc->id,
            'variante_id' => $variante, 'producto_nombre' => 'Guarda', 'modelo' => 'G', 'talla' => '25',
            'color' => 'Negro', 'cantidad' => 1, 'precio_unitario' => 10, 'subtotal' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $vale = DB::table('vales')->insertGetId(array_merge($this->baseVale($dist, $staff, $cliente), [
            'folio' => 'VAL-GUARDA-TIPO', 'tipo' => 'devolucion_producto', 'pedido_origen_id' => $pedido,
        ]));
        DB::table('vale_devolucion_partidas')->insert([
            'distribuidora_id' => $dist, 'vale_id' => $vale, 'pedido_detalle_id' => $detalle,
            'cantidad' => 1, 'precio_unitario' => 10, 'monto' => 10,
        ]);

        try {
            $this->migracion('2026_11_07_120000_agrega_tipo_y_devoluciones_a_vales.php')->down();
            $this->fail('down() debía rechazar la nota ya emitida.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('trazabilidad', $e->getMessage());
            $this->assertStringContainsString('No se modificó la tabla', $e->getMessage());
        }

        $this->assertSame($antes, $this->restriccionesVales());
        $this->assertSame('devolucion_producto', DB::table('vales')->where('id', $vale)->value('tipo'));
        $this->assertSame(1, DB::table('vale_devolucion_partidas')->where('vale_id', $vale)->count());
    }

    private function staffNullable(): string
    {
        $fila = DB::selectOne(
            'select IS_NULLABLE from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['vales', 'creado_por_staff_id']
        );

        return (string) $fila->IS_NULLABLE;
    }
}
