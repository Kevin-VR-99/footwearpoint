<?php

namespace Tests\Feature\Sprint5;

use App\Database\ReversaAutorVale;
use App\Database\ReversaTiposVale;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * TG-356: reversa real de la migración de autoría sobre una base vacía,
 * distinta de footwearpoint_testing. MySQL confirma cada ALTER; aquí se
 * comprueba el camino que sí puede revertirse (filas legacy, sin usuario
 * autor) y el que restaura fk_vales_5 si el ALTER se corta a la mitad.
 * No se borran filas para conseguir que down() pase.
 */
class TG356ReversaDdlTest extends TestCase
{
    private string $base = 'footwearpoint_tg356_ddl';

    private string $anterior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anterior = (string) config('database.connections.mysql.database');
        DB::statement('CREATE DATABASE IF NOT EXISTS `'.$this->base.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.mysql.database' => $this->base]);
        DB::purge('mysql');
        DB::reconnect('mysql');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        config(['database.connections.mysql.database' => $this->anterior]);
        DB::reconnect('mysql');
        DB::statement('DROP DATABASE IF EXISTS `'.$this->base.'`');
        parent::tearDown();
    }

    public function test_legacy_se_revierte_igual_y_un_corte_restaura_la_fk_sin_borrar_filas(): void
    {
        [$dist, $staff] = $this->casaMinima();
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = DB::table('vales')->insertGetId($this->filaLegacy($dist, $staff, $i));
        }
        $antes = $this->foto($ids);
        $cortada = new class extends ReversaAutorVale
        {
            protected function despuesDeSoltarFk(): void
            {
                throw new RuntimeException('falla inyectada tras soltar fk_vales_5');
            }
        };
        try {
            $cortada->down();
            $this->fail('La falla inyectada debía abortar down().');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('se restauró el esquema de la subida', $e->getMessage());
            $this->assertStringContainsString('fk_vales_5', $e->getMessage());
            $this->assertStringContainsString('falla inyectada', $e->getMessage());
        }
        $this->assertTrue($this->restriccion('fk_vales_5'));
        $this->assertTrue($this->restriccion('chk_vale_autor'));
        $this->assertSame('YES', $this->staffNullable());
        $this->assertTrue($this->columna('creado_por_usuario_id'));
        $this->assertSame($antes, $this->foto($ids));

        $migracion = require base_path('database/migrations/2026_11_07_120100_permite_vale_sobrante_generado_desde_app.php');
        $this->assertFalse(property_exists($migracion, 'fallarTrasSoltarFk'));
        $migracion->down();
        $this->assertFalse($this->columna('creado_por_usuario_id'));
        $this->assertTrue($this->restriccion('fk_vales_5'));
        $this->assertFalse($this->restriccion('chk_vale_autor'));
        $this->assertSame('NO', $this->staffNullable());
        $this->assertSame($this->sinAuditoria($antes), $this->foto($ids));

        $migracion->up();
        $this->assertSame($antes, $this->foto($ids));
        $this->assertTrue($this->restriccion('chk_vale_autor'));
        $this->assertSame('YES', $this->staffNullable());

        $usuario = (int) DB::table('usuarios')->value('id');
        $sobrante = DB::table('vales')->insertGetId(array_merge($this->filaLegacy($dist, $staff, 9), [
            'folio' => 'VAL-DDL-SOBRA', 'tipo' => 'sobrante', 'vale_origen_id' => $ids[0],
            'creado_por_staff_id' => null, 'creado_por_usuario_id' => $usuario,
        ]));
        try {
            $migracion->down();
            $this->fail('El sobrante sin staff debía impedir la reversa.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No se modificó la tabla vales', $e->getMessage());
        }
        $this->assertTrue($this->restriccion('fk_vales_5'));
        $this->assertTrue($this->restriccion('chk_vale_autor'));
        $this->assertNull(DB::table('vales')->where('id', $sobrante)->value('creado_por_staff_id'));
        $this->assertSame($usuario, (int) DB::table('vales')->where('id', $sobrante)->value('creado_por_usuario_id'));
        $this->assertSame($antes, $this->foto($ids));
    }

    public function test_reintento_de_tipos_no_exige_la_fk_que_ya_se_quito_y_no_borra_filas(): void
    {
        [$dist, $staff] = $this->casaMinima();
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = DB::table('vales')->insertGetId($this->filaLegacy($dist, $staff, $i));
        }
        $antes = $this->foto($ids);

        try {
            (new ReversaTiposVale)->down();
            $this->fail('Con el CHECK de autoría todavía puesto no se pueden quitar los tipos.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('chk_vale_autor', $e->getMessage());
            $this->assertStringContainsString('No se modificó la tabla', $e->getMessage());
        }
        $this->assertTrue($this->restriccion('fk_vales_origen'));
        $this->assertSame($antes, $this->foto($ids));

        (new ReversaAutorVale)->down();
        $sinAutor = $this->foto($ids);

        DB::statement('ALTER TABLE vales DROP FOREIGN KEY fk_vales_origen');
        $this->assertFalse($this->restriccion('fk_vales_origen'));
        (new ReversaTiposVale)->down();
        $this->assertFalse($this->columna('tipo'));
        $this->assertFalse($this->columna('vale_origen_id'));
        $this->assertFalse(Schema::hasTable('vale_devolucion_partidas'));
        $this->assertSame($this->sinTipos($sinAutor), $this->foto($ids));

        $this->subirTipos();
        $this->assertSame($sinAutor, $this->foto($ids));
        $this->assertTrue($this->restriccion('fk_vales_origen'));

        $cortada = new class extends ReversaTiposVale
        {
            protected function despuesDeSoltarFkOrigen(): void
            {
                throw new RuntimeException('falla inyectada tras soltar fk_vales_origen');
            }
        };
        try {
            $cortada->down();
            $this->fail('La falla inyectada debía abortar la reversa de tipos.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fk_vales_origen', $e->getMessage());
            $this->assertStringContainsString('sin borrar filas', $e->getMessage());
        }
        $this->assertTrue($this->restriccion('fk_vales_origen'));
        $this->assertTrue($this->columna('tipo'));
        $this->assertTrue(Schema::hasTable('vale_devolucion_partidas'));
        $this->assertSame($sinAutor, $this->foto($ids));

        (new ReversaTiposVale)->down();
        $this->assertFalse($this->columna('tipo'));
        $this->assertSame($this->sinTipos($sinAutor), $this->foto($ids));

        $this->subirTipos();

        $marcada = new class extends ReversaTiposVale
        {
            public int $vale = 0;

            protected function antesDeQuitarColumnas(): void
            {
                DB::table('vales')->where('id', $this->vale)->update(['tipo' => 'bono']);
            }
        };
        $marcada->vale = $ids[0];
        try {
            $marcada->down();
            $this->fail('Un tipo aparecido a mitad de la reversa debía detenerla.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sin borrar filas', $e->getMessage());
        }
        $this->assertSame('bono', DB::table('vales')->where('id', $ids[0])->value('tipo'));
        $this->assertTrue($this->restriccion('fk_vales_origen'));
        $this->assertTrue($this->columna('tipo'));
        $this->assertSame(5, DB::table('vales')->whereIn('id', $ids)->count());
    }

    private function subirTipos(): void
    {
        $migracion = require base_path('database/migrations/2026_11_07_120000_agrega_tipo_y_devoluciones_a_vales.php');
        $migracion->up();
    }

    /** @return array{0: int, 1: int} */
    private function casaMinima(): array
    {
        $dist = DB::table('distribuidoras')->insertGetId([
            'nombre_comercial' => 'DDL TG356', 'slug' => 'ddl-tg356', 'estado' => 'activa',
        ]);
        $usuario = DB::table('usuarios')->insertGetId([
            'nombre' => 'Staff DDL', 'email' => 'ddl-tg356@example.test', 'password' => 'x', 'estado' => 'activo',
        ]);
        $staff = DB::table('distribuidora_staff')->insertGetId([
            'distribuidora_id' => $dist, 'usuario_id' => $usuario, 'tipo' => 'administrador', 'estado' => 'activo',
        ]);

        return [(int) $dist, (int) $staff];
    }

    private function filaLegacy(int $dist, int $staff, int $n): array
    {
        return [
            'distribuidora_id' => $dist, 'folio' => 'VAL-DDL-LEGACY-'.$n, 'monto_original' => 10 + $n,
            'saldo_actual' => 10 + $n, 'fecha_vencimiento' => '2027-02-05 12:00:00', 'estado' => 'activo',
            'creado_por_staff_id' => $staff, 'created_at' => '2026-11-07 12:00:00', 'updated_at' => '2026-11-07 12:00:00',
        ];
    }

    /** @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function foto(array $ids): array
    {
        return array_map(fn ($fila) => (array) $fila, DB::table('vales')->whereIn('id', $ids)->orderBy('id')->get()->all());
    }

    /** @param  list<array<string, mixed>>  $filas
     * @return list<array<string, mixed>>
     */
    private function sinAuditoria(array $filas): array
    {
        return array_map(function (array $fila) {
            unset($fila['creado_por_usuario_id']);

            return $fila;
        }, $filas);
    }

    /** @param  list<array<string, mixed>>  $filas
     * @return list<array<string, mixed>>
     */
    private function sinTipos(array $filas): array
    {
        return array_map(function (array $fila) {
            unset($fila['tipo'], $fila['vale_origen_id'], $fila['clave_idempotencia']);

            return $fila;
        }, $filas);
    }

    private function restriccion(string $nombre): bool
    {
        $fila = DB::selectOne(
            'select CONSTRAINT_NAME from information_schema.TABLE_CONSTRAINTS where TABLE_SCHEMA = database() and TABLE_NAME = ? and CONSTRAINT_NAME = ? limit 1',
            ['vales', $nombre]
        );

        return $fila !== null;
    }

    private function columna(string $nombre): bool
    {
        $fila = DB::selectOne(
            'select COLUMN_NAME from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ? limit 1',
            ['vales', $nombre]
        );

        return $fila !== null;
    }

    private function staffNullable(): string
    {
        $fila = DB::selectOne(
            'select IS_NULLABLE from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['vales', 'creado_por_staff_id']
        );

        return (string) ($fila->IS_NULLABLE ?? '');
    }
}
