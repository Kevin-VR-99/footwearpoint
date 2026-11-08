<?php

namespace App\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * TG-356 — Reversa de vales.tipo, vale_origen_id, clave_idempotencia y
 * vale_devolucion_partidas.
 *
 * MySQL confirma cada ALTER por separado. down() solo quita lo que todavía
 * existe, así un reintento después de un corte (por ejemplo fk_vales_origen
 * ya no está) termina la reversa en vez de exigir reparación a mano.
 *
 * Si la reversa se interrumpe antes de quitar las columnas, se vuelve al
 * esquema de la subida. No se borran filas para poder revertir: con tipo o
 * con partidas, down() se niega.
 *
 * Modo mantenimiento: no hay un candado que sobreviva a estos ALTER. Hay que
 * detener escrituras de vales antes de revertir. Si aparece un tipo o una
 * partida a mitad de camino, se restaura el esquema y no se tocan las filas.
 * Esa comprobación no cierra la ventana de concurrencia; las pruebas no
 * sustituyen el mantenimiento.
 */
class ReversaTiposVale
{
    public function down(): void
    {
        if ($motivo = $this->impedimento()) {
            throw new RuntimeException($motivo.' No se modificó la tabla.');
        }

        $tocado = false;
        try {
            if ($this->soltarPartidasVacias()) {
                $tocado = true;
            }
            $this->despuesDeSoltarPartidas();
            if (! $this->hayColumnasDeTipo()) {
                return;
            }
            if ($this->soltarFkOrigen()) {
                $tocado = true;
            }
            $this->despuesDeSoltarFkOrigen();
            if ($this->soltarUniqueIdempotencia()) {
                $tocado = true;
            }
            if ($this->soltarIndiceOrigen()) {
                $tocado = true;
            }
            $this->antesDeQuitarColumnas();
            if ($motivo = $this->impedimento()) {
                throw new RuntimeException($motivo);
            }
            $this->soltarColumnasTipo();
        } catch (Throwable $e) {
            if (! $tocado || ! $this->hayColumnasDeTipo()) {
                throw $e;
            }
            try {
                $this->restaurarEsquemaDeSubida();
            } catch (Throwable $restauracion) {
                throw new RuntimeException(
                    'La reversa de tipos falló y no se pudo restaurar fk_vales_origen: '.$e->getMessage().' | restauración: '.$restauracion->getMessage(),
                    0,
                    $e
                );
            }
            throw new RuntimeException(
                'No se terminó de revertir los tipos de vale. MySQL confirma cada ALTER; se restauró fk_vales_origen y la tabla de partidas, sin borrar filas. '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /** Punto de extensión para una prueba: la clase que se despliega no falla aquí. */
    protected function despuesDeSoltarPartidas(): void {}

    /** Punto de extensión para una prueba: la clase que se despliega no falla aquí. */
    protected function despuesDeSoltarFkOrigen(): void {}

    /** Punto de extensión para una prueba: la clase que se despliega no falla aquí. */
    protected function antesDeQuitarColumnas(): void {}

    private function impedimento(): ?string
    {
        if (Schema::hasColumn('vales', 'tipo') && DB::table('vales')->whereNotNull('tipo')->exists()) {
            return 'Hay vales con tipo; revertir perdería su trazabilidad.';
        }
        if (Schema::hasTable('vale_devolucion_partidas') && DB::table('vale_devolucion_partidas')->exists()) {
            return 'Hay partidas devueltas; revertir perdería su trazabilidad.';
        }
        if ($this->restriccionExiste('chk_vale_autor')) {
            return 'El CHECK chk_vale_autor todavía usa la columna tipo. Revierte primero la migración de autoría.';
        }

        return null;
    }

    private function soltarPartidasVacias(): bool
    {
        if (! Schema::hasTable('vale_devolucion_partidas')) {
            return false;
        }
        if (DB::table('vale_devolucion_partidas')->exists()) {
            throw new RuntimeException('Hay partidas devueltas; revertir perdería su trazabilidad. No se modificó la tabla.');
        }
        Schema::drop('vale_devolucion_partidas');

        return true;
    }

    private function soltarFkOrigen(): bool
    {
        if (! $this->restriccionExiste('fk_vales_origen')) {
            return false;
        }
        Schema::table('vales', function (Blueprint $table) {
            $table->dropForeign('fk_vales_origen');
        });

        return true;
    }

    private function soltarUniqueIdempotencia(): bool
    {
        if (! $this->indiceExiste('uq_vale_tenant_idempotencia')) {
            return false;
        }
        Schema::table('vales', function (Blueprint $table) {
            $table->dropUnique('uq_vale_tenant_idempotencia');
        });

        return true;
    }

    private function soltarIndiceOrigen(): bool
    {
        if (! $this->indiceExiste('ix_vale_origen')) {
            return false;
        }
        Schema::table('vales', function (Blueprint $table) {
            $table->dropIndex('ix_vale_origen');
        });

        return true;
    }

    private function soltarColumnasTipo(): void
    {
        $columnas = array_values(array_filter(
            ['tipo', 'vale_origen_id', 'clave_idempotencia'],
            fn (string $columna) => Schema::hasColumn('vales', $columna)
        ));
        if ($columnas === []) {
            return;
        }
        Schema::table('vales', function (Blueprint $table) use ($columnas) {
            $table->dropColumn($columnas);
        });
    }

    private function restaurarEsquemaDeSubida(): void
    {
        if (! $this->hayColumnasDeTipo()) {
            throw new RuntimeException('Las columnas de tipo ya no están; no se reconstruye la trazabilidad.');
        }
        if (! $this->indiceExiste('ix_vale_origen')) {
            Schema::table('vales', function (Blueprint $table) {
                $table->index(['distribuidora_id', 'vale_origen_id'], 'ix_vale_origen');
            });
        }
        if (! $this->indiceExiste('uq_vale_tenant_idempotencia')) {
            Schema::table('vales', function (Blueprint $table) {
                $table->unique(['distribuidora_id', 'clave_idempotencia'], 'uq_vale_tenant_idempotencia');
            });
        }
        if (! $this->restriccionExiste('fk_vales_origen')) {
            Schema::table('vales', function (Blueprint $table) {
                $table->foreign(['distribuidora_id', 'vale_origen_id'], 'fk_vales_origen')
                    ->references(['distribuidora_id', 'id'])->on('vales')->onUpdate('restrict')->onDelete('restrict');
            });
        }
        $this->asegurarTablaPartidas();
    }

    private function asegurarTablaPartidas(): void
    {
        if (Schema::hasTable('vale_devolucion_partidas')) {
            return;
        }
        Schema::create('vale_devolucion_partidas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('distribuidora_id');
            $table->unsignedBigInteger('vale_id');
            $table->unsignedBigInteger('pedido_detalle_id');
            $table->unsignedInteger('cantidad');
            $table->decimal('precio_unitario', 12);
            $table->decimal('monto', 12);
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['vale_id', 'pedido_detalle_id'], 'uq_vdp_vale_partida');
            $table->index(['distribuidora_id', 'pedido_detalle_id'], 'ix_vdp_partida');
            $table->foreign(['distribuidora_id', 'vale_id'], 'fk_vdp_vale')
                ->references(['distribuidora_id', 'id'])->on('vales')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['distribuidora_id', 'pedido_detalle_id'], 'fk_vdp_partida')
                ->references(['distribuidora_id', 'id'])->on('pedido_detalle')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    private function hayColumnasDeTipo(): bool
    {
        return Schema::hasColumn('vales', 'tipo')
            || Schema::hasColumn('vales', 'vale_origen_id')
            || Schema::hasColumn('vales', 'clave_idempotencia');
    }

    private function restriccionExiste(string $nombre): bool
    {
        $fila = DB::selectOne(
            'select CONSTRAINT_NAME from information_schema.TABLE_CONSTRAINTS where TABLE_SCHEMA = database() and TABLE_NAME = ? and CONSTRAINT_NAME = ? limit 1',
            ['vales', $nombre]
        );

        return $fila !== null;
    }

    private function indiceExiste(string $nombre): bool
    {
        $fila = DB::selectOne(
            'select INDEX_NAME from information_schema.STATISTICS where TABLE_SCHEMA = database() and TABLE_NAME = ? and INDEX_NAME = ? limit 1',
            ['vales', $nombre]
        );

        return $fila !== null;
    }
}
