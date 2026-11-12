<?php

use App\Database\LimpiezaComisionesMayoristas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quita comisiones y metas de clientes mayoristas (S5-53).
 *
 * No hay tabla comisiones. Se eliminan:
 * - configuraciones_distribuidora.comision_porcentaje_default
 * - revendedor_distribuidora.comision_porcentaje
 * - la tabla metas_revendedor
 * - la llave comisiones.ver dentro de distribuidora_staff.permisos_finos
 * - el permiso Spatie comisiones.ver, si existiera
 *
 * Auditoría, filtro exacto (LimpiezaComisionesMayoristas::ACCIONES_AUDITORIA):
 *
 *   DELETE FROM auditorias
 *   WHERE accion IN (
 *       'comision.default_actualizado',
 *       'comision.porcentaje_actualizado',
 *       'comision.meta_actualizada'
 *   );
 *
 * down() vuelve a crear la estructura, sin datos. Los datos solo se
 * recuperan del respaldo footwearpoint-20261112-063541.sql.gz.
 */
return new class extends Migration
{
    public function up(): void
    {
        LimpiezaComisionesMayoristas::borrarAuditoria();
        LimpiezaComisionesMayoristas::quitarPermisoFino();
        LimpiezaComisionesMayoristas::quitarPermisoSpatie();

        Schema::dropIfExists('metas_revendedor');

        if (Schema::hasColumn('revendedor_distribuidora', 'comision_porcentaje')) {
            Schema::table('revendedor_distribuidora', function (Blueprint $tabla) {
                $tabla->dropColumn('comision_porcentaje');
            });
        }

        if (Schema::hasColumn('configuraciones_distribuidora', 'comision_porcentaje_default')) {
            Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
                $tabla->dropColumn('comision_porcentaje_default');
            });
        }
    }

    public function down(): void
    {
        // Estructura vacía. Los porcentajes, las metas y las filas de
        // auditoría no se reconstruyen: salen del respaldo.
        if (! Schema::hasColumn('configuraciones_distribuidora', 'comision_porcentaje_default')) {
            Schema::table('configuraciones_distribuidora', function (Blueprint $tabla) {
                $tabla->decimal('comision_porcentaje_default', 5, 2)->default(0);
            });
        }

        if (! Schema::hasColumn('revendedor_distribuidora', 'comision_porcentaje')) {
            Schema::table('revendedor_distribuidora', function (Blueprint $tabla) {
                $tabla->decimal('comision_porcentaje', 5, 2)->nullable();
            });
        }

        if (! Schema::hasTable('metas_revendedor')) {
            Schema::create('metas_revendedor', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->unsignedBigInteger('distribuidora_id');
                $tabla->unsignedBigInteger('revendedor_distribuidora_id');
                $tabla->char('periodo', 7);
                $tabla->decimal('monto_meta', 12, 2);
                $tabla->timestamps();

                $tabla->unique(
                    ['distribuidora_id', 'revendedor_distribuidora_id', 'periodo'],
                    'uq_meta_revendedor_periodo'
                );
                $tabla->foreign('distribuidora_id', 'fk_metas_rev_1')
                    ->references('id')->on('distribuidoras')->onDelete('cascade');
                $tabla->foreign('revendedor_distribuidora_id', 'fk_metas_rev_2')
                    ->references('id')->on('revendedor_distribuidora')->onDelete('cascade');
            });
        }
    }
};
