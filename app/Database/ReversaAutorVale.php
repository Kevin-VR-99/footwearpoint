<?php

namespace App\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * TG-356 — Reversa de la autoría del vale (staff nullable, usuario autor, CHECK).
 *
 * Cada ALTER se confirma solo. Si un paso falla antes de quitar
 * creado_por_usuario_id, se vuelve al esquema de la subida sin borrar filas.
 * Los DROP preguntan si el objeto sigue ahí, así un reintento no exige
 * reparación manual.
 *
 * Modo mantenimiento: esta reversa no conserva un candado a través de los
 * ALTER. Hay que detener la aplicación antes de correrla. El segundo control
 * (y la restauración) cubre el caso en que una fila entra entre medias, pero
 * no cierra esa ventana. Las pruebas no prometen ausencia de carrera con
 * tráfico real.
 *
 * creado_por_usuario_id sigue sin llave foránea: es auditoría y debe sobrevivir
 * al borrado de la cuenta.
 */
class ReversaAutorVale
{
    public function down(): void
    {
        if ($motivo = $this->motivoParaNoRevertir()) {
            throw new RuntimeException($motivo.' No se modificó la tabla vales.');
        }

        $tocado = false;
        try {
            $this->soltarCheckAutor();
            $tocado = true;
            $this->soltarFkStaff();
            $this->despuesDeSoltarFk();
            if ($motivo = $this->motivoParaNoRevertir()) {
                throw new RuntimeException($motivo);
            }
            $this->staffObligatorio();
            $this->reponerFkStaff();
            $this->quitarColumnaUsuario();
        } catch (Throwable $e) {
            $this->restaurarOExplicar($e, $tocado);
        }
    }

    /** Punto de extensión para una prueba. La clase desplegada no hace nada. */
    protected function despuesDeSoltarFk(): void {}

    private function motivoParaNoRevertir(): ?string
    {
        if (DB::table('vales')->whereNull('creado_por_staff_id')->exists()) {
            return 'Hay vales sin empleado (sobrante generado desde la app). No se les inventa un staff ni se borra la fila.';
        }
        if (Schema::hasColumn('vales', 'creado_por_usuario_id') && DB::table('vales')->whereNotNull('creado_por_usuario_id')->exists()) {
            return 'Hay vales con creado_por_usuario_id. Revertir borraría ese id de auditoría.';
        }

        return null;
    }

    private function restaurarOExplicar(Throwable $e, bool $tocado): void
    {
        if (! $tocado || ! Schema::hasColumn('vales', 'creado_por_usuario_id')) {
            throw new RuntimeException(
                'No se revirtió la autoría del vale y no se pudo restaurar el esquema (creado_por_usuario_id ya no está o no se había tocado la tabla). '.$e->getMessage(),
                0,
                $e
            );
        }

        try {
            $this->restaurarEsquemaDeSubida();
        } catch (Throwable $restauracion) {
            throw new RuntimeException(
                'La reversa falló y no se pudo restaurar fk_vales_5 y chk_vale_autor: '.$e->getMessage().' | restauración: '.$restauracion->getMessage(),
                0,
                $e
            );
        }

        throw new RuntimeException(
            'No se revirtió la autoría del vale. MySQL confirma cada ALTER, así que se restauró el esquema de la subida (staff nullable, fk_vales_5 y chk_vale_autor) sin borrar filas. '.$e->getMessage(),
            0,
            $e
        );
    }

    private function restaurarEsquemaDeSubida(): void
    {
        if (! Schema::hasColumn('vales', 'creado_por_usuario_id')) {
            throw new RuntimeException('creado_por_usuario_id ya no está; los ids de auditoría no se pueden reconstruir.');
        }
        $this->soltarFkStaff();
        $this->staffNullable();
        if (! $this->indiceUsuarioExiste()) {
            Schema::table('vales', function (Blueprint $table) {
                $table->index('creado_por_usuario_id', 'ix_vales_usuario_autor');
            });
        }
        $this->reponerFkStaff();
        $this->reponerCheckAutor();
    }

    private function soltarCheckAutor(): void
    {
        if ($this->restriccionExiste('chk_vale_autor')) {
            DB::statement('ALTER TABLE vales DROP CONSTRAINT chk_vale_autor');
        }
    }

    private function reponerCheckAutor(): void
    {
        if ($this->restriccionExiste('chk_vale_autor')) {
            return;
        }
        DB::statement("ALTER TABLE vales ADD CONSTRAINT chk_vale_autor CHECK (creado_por_staff_id IS NOT NULL OR (COALESCE(tipo, '') = 'sobrante' AND vale_origen_id IS NOT NULL AND creado_por_usuario_id IS NOT NULL))");
    }

    private function soltarFkStaff(): void
    {
        if (! $this->restriccionExiste('fk_vales_5')) {
            return;
        }
        Schema::table('vales', function (Blueprint $table) {
            $table->dropForeign('fk_vales_5');
        });
    }

    private function reponerFkStaff(): void
    {
        if ($this->restriccionExiste('fk_vales_5')) {
            return;
        }
        Schema::table('vales', function (Blueprint $table) {
            $table->foreign(['distribuidora_id', 'creado_por_staff_id'], 'fk_vales_5')->references(['distribuidora_id', 'id'])
                ->on('distribuidora_staff')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    private function staffObligatorio(): void
    {
        if (! $this->staffEsNullable()) {
            return;
        }
        Schema::table('vales', function (Blueprint $table) {
            $table->unsignedBigInteger('creado_por_staff_id')->nullable(false)->change();
        });
    }

    private function staffNullable(): void
    {
        if ($this->staffEsNullable()) {
            return;
        }
        Schema::table('vales', function (Blueprint $table) {
            $table->unsignedBigInteger('creado_por_staff_id')->nullable()->change();
        });
    }

    private function quitarColumnaUsuario(): void
    {
        if (! Schema::hasColumn('vales', 'creado_por_usuario_id')) {
            return;
        }
        $tieneIndice = $this->indiceUsuarioExiste();
        Schema::table('vales', function (Blueprint $table) use ($tieneIndice) {
            if ($tieneIndice) {
                $table->dropIndex('ix_vales_usuario_autor');
            }
            $table->dropColumn('creado_por_usuario_id');
        });
    }

    private function staffEsNullable(): bool
    {
        $fila = DB::selectOne(
            'select IS_NULLABLE from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['vales', 'creado_por_staff_id']
        );

        return ($fila->IS_NULLABLE ?? '') === 'YES';
    }

    private function restriccionExiste(string $nombre): bool
    {
        $fila = DB::selectOne(
            'select CONSTRAINT_NAME from information_schema.TABLE_CONSTRAINTS where TABLE_SCHEMA = database() and TABLE_NAME = ? and CONSTRAINT_NAME = ? limit 1',
            ['vales', $nombre]
        );

        return $fila !== null;
    }

    private function indiceUsuarioExiste(): bool
    {
        $fila = DB::selectOne(
            'select INDEX_NAME from information_schema.STATISTICS where TABLE_SCHEMA = database() and TABLE_NAME = ? and INDEX_NAME = ? limit 1',
            ['vales', 'ix_vales_usuario_autor']
        );

        return $fila !== null;
    }
}
